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
use Throwable;

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
        Config::clear();
    }

    /**
     * 在工作进程创建之初执行.
     * @access private
     * @param string $module 模块名称
     * @param int $serverId 服务器ID
     * @param int $workerId 进程ID
     */
    private static function beforeWorkerStart(string $module, int $serverId, int $workerId):void {
        $wg = new WaitGroup();
        $wg->add();
        Coroutine::create(function () use($wg, $module, $serverId, $workerId){
            $time = time();
            try{
                $class = "\\app\\{$module}\\BootStarup::onWorkerStart";
                call_user_func_array($class, [$serverId, $workerId]);
                $crontab = Config::get('crontab', []);
                foreach($crontab as $task){
                    if(!isset($task['workerId']) || $task['workerId'] == $workerId){
                        crontab($task['every'], $task['at'] ?? '', $task['exec'], $time);
                    }
                }
            }catch(Throwable $e){
                echo 'ERROR: ' . $e->getMessage() . "\n";
            }
            $wg->done();
        });
        $wg->wait();
    }

    /**
     * 在工作进程关闭之前之初执行.
     * @access private
     * @param string $module 模块名称
     * @param int $serverId 服务器ID
     * @param int $workerId 进程ID
     */
    private static function beforeWorkeShutdown(string $module, int $serverId, int $workerId):void {
        $wg = new WaitGroup();
        $wg->add();
        Coroutine::create(function () use($wg, $module, $serverId, $workerId){
            $time = time();
            try{
                $class = "\\app\\{$module}\\BootStarup::onWorkerShutdown";
                call_user_func_array($class, [$serverId, $workerId]);
            }catch(Throwable $e){
                echo 'ERROR: ' . $e->getMessage() . "\n";
            }
            $wg->done();
        });
        $wg->wait();
    }

    /**
     * 应用程序入口
     * @access public
     * @return void
     */
    public static function execute()
    {
        $modules_conf = Config::get('app.modules');

        # 在这里执行系统启动之前的任务.
        static::beforeWorkerManagerCreate();

        ini_set('serialize_precision', '15'); # json 浮点设置

        $pm = new \Swoole\Process\Manager();

        
        foreach($modules_conf as $module=>$config) {
            if($config['enabled']) {
                $pm->addBatch($config['worker_num'], function($pool, $workerId) use ($module, $config) {

                    Log::start($workerId);

                    # 指定工作进程的运行角色
                    if(!empty($config['user']))
                        Helpers::setUser($config['user']);
                    
                    # 工作进程初始化函数.
                    static::beforeWorkerStart($module, $config['server_id'], $workerId);

                    # 启动服务器角色
                    $protocol = $config['protocol'];
                    $server = new $protocol($config, $module, $workerId);
                    
                    # 收到15信号关闭服务
                    \Swoole\Process::signal(SIGTERM, function () use($server, $module, $config, $workerId){
                        echo "=== Server Shutdown by SIGTERM\n";
                        SysEvents::DispathEvent('shutdown');
                        $server->shutdown();
                        static::beforeWorkeShutdown($module, $config['server_id'], $workerId);
                        \Swoole\Timer::clearAll(); //清理所有队列中的任务
                        Log::end();
                    });

                    # 收到13信号关闭服务 Ctrl + C
                    \Swoole\Process::signal(SIGINT, function () use($server, $module, $config, $workerId){
                        echo "=== Server Shutdown by Crtl+C\n";
                        SysEvents::DispathEvent('shutdown');
                        $server->shutdown();
                        static::beforeWorkeShutdown($module, $config['server_id'], $workerId);
                        \Swoole\Timer::clearAll(); //清理所有队列中的任务
                        Log::end();
                    });
                    # 启动服务
                    $server->start();
                }, true);
            }
        }
        $pm->start();
    }
}
