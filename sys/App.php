<?php
declare(strict_types=1);
/*
    框架入口文件
    作者: 龚辟愚
    时间: 2021-09-18
*/
namespace sys;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;

class App {

    /**
     * 在 创建工作进程之前执行
     */
    private static function beforeWorkerManagerCreate() : void
    {
        # 避免连接池污染子进程, 这里新开一个进程处理.
        $process = new \Swoole\Process(function(){
            //第一步: 初始化表结构.
            Helpers::CreateDataBaseStructCache();
        }, false, 0, true);
        $process->start();
        $process->wait(true);

        # 清理配置.
        Config::Clear();
    }

    /**
     * 在工作进程创建之初执行.
     */
    private static function beforeWorkerStart():void {
        $wg = new WaitGroup();
        $wg->add();
        Coroutine::create(function () use($wg){
            echo "before Server Start \n";
            $wg->done();
        });
        $wg->wait();
    }

    /**
     *   应用程序入口
     */
    public static function execute()
    {
        $modules_conf = Config::get('app.modules');
        //在这里执行系统启动之前的任务.
        static::beforeWorkerManagerCreate();

        ini_set('serialize_precision', '15'); # json 浮点设置

        $pm = new \Swoole\Process\Manager();
        foreach($modules_conf as $module=>$config) {
            if($config['enabled']) {
                $pm->addBatch($config['worker_num'], function($pool, $workerId) use ($module, $config) {

                    # 指定工作进程的运行角色
                    if(!empty($config['user']))
                        Helpers::setUser($config['user']);
                    
                    # 工作进程初始化函数.
                    static::beforeWorkerStart();


                    $sharePort = $config['share_port'];
                    $listenPort = $sharePort ? $config['listen_port'] : $config['listen_port'] + $workerId;
                    # $server = new \Swoole\Coroutine\Http\Server($config['bind_address'], $listenPort, $config['ssl'], $sharePort);
                    $server = new \sys\servers\HttpServer;
                    
                    //TODO: 开启了SSL的话, 需要进一步设置证书
                    if($config['ssl'])
                    {

                    }

                    //收到15信号关闭服务
                    \Swoole\Process::signal(SIGTERM, function () use($server){
                        echo "=== Server Shutdown by SIGTERM\n";
                        SysEvents::DispathEvent('shutdown');
                        $server->shutdown();
                        \Swoole\Timer::clearAll(); //清理所有队列中的任务
                    });

                    //收到13信号关闭服务 Ctrl + C
                    \Swoole\Process::signal(SIGINT, function () use($server){
                        echo "=== Server Shutdown by Crtl+C\n";
                        SysEvents::DispathEvent('shutdown');
                        $server->shutdown();
                        \Swoole\Timer::clearAll(); //清理所有队列中的任务
                    });

                    //服务器请求路由映射
                    $server->handle('', function(Request $request, Response $response) use($module, $sharePort) {
                        $ns = "\\app\\{$module}\\controller\\";
                        
                        $uri = $request->server['request_uri'];
                        \preg_match_all("#\/([\-\w\d_]+)*#i", strtolower($uri) , $matches);
                        $psr  = array_filter($matches[1]);

                        if(!$sharePort){
                            $node = array_shift($psr);
                        }
                        $num = count($psr);

                        switch($num){
                        case 0:
                            $class = $ns.'Index';
                            $method = 'index';
                            break;
                        case 1:
                            $class = $ns.ucfirst(array_pop($psr));
                            $method = 'index';
                            break;
                        default:
                            $method = array_pop($psr);
                            $class = ucfirst(array_pop($psr));
                            if(empty($psr)){
                                $class = $ns.$class;
                            }else{
                                $class = $ns.implode('\\', $psr).'\\'.$class;
                            }                        
                            break;
                        }
                        Log::write("[CALL] {$class}:{$method}", "APP","INFO");
                        
                        try{
                            $m = new $class;
                            $m->isWebSocket = isWebSocket($request);

                            if($ret = $m->$method($request, $response)){
                                if(null === $ret){
                                    $response->end('');
                                }else{
                                    if($ret instanceof \sys\resp\Resp){
                                        $ret->output($response);
                                    }else{
                                        if(is_array($ret)){
                                            $response->header('Conent-Type', 'application/json');
                                            $response->end(json_encode($ret,JSON_UNESCAPED_UNICODE));
                                        }elseif(is_string($ret)){
                                            $response->end($ret);
                                        }
                                    }
                                }
                            }
                            return;
                        }catch(\Throwable $e){
                            $file = SITE_ROOT. $uri;
                            $response->header('Content-Type','text/plain');
                            $response->status(404);
                            $err = [
                                'Error:'.$e->getMessage().' at file:'.$e->getFile().'('.$e->getLine().')',
                                'Entry:' . $class, 
                                "TraceBack:\n".$e->getTraceAsString(),
                                //'mapping_file:'. SITE_ROOT.$request->server['request_uri']
                            ];
                            $response->end(\implode("\n", $err));
                        }
                    });

                    //启动服务
                    $server->start();
                }, true);
            }
        }
        $pm->start();
    }
}
