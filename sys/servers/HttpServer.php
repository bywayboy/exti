<?php
declare(strict_types=1);

namespace sys\servers;

use JsonSerializable;
use Swoole\Http\Request;
use Swoole\Http\Response;

use sys\Log;

class HttpServer {
    protected \Swoole\Coroutine\Http\Server $server;
    
    /**
     * @param array $config 当前模块配置 来自 \config\app.php
     * @param string $module 模块名称
     * @param int $workerId 工作进程ID 0~n
     */
    public function __construct(array $config, string $module, int $workerId)
    {
        $sharePort = $config['share_port'];
        $listenPort = $sharePort ? $config['listen_port'] : $config['listen_port'] + $workerId;

        $this->server = new \Swoole\Coroutine\Http\Server($config['bind_address'], $listenPort, $config['ssl'], $sharePort);

        # TODO: 开启了SSL的话, 需要进一步设置证书
        if($config['ssl'])
        {

        }

        # 服务器请求路由映射
        $this->server->handle('', function(Request $request, Response $response) use($module, $sharePort) {
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
                //$m->isWebSocket = isWebSocket($request);

                if($ret = $m->$method($request, $response)) {
                    if(null === $ret){
                        $response->end('');
                    }else{
                        if($ret instanceof \sys\servers\http\Resp){
                            $ret->output($response);
                        }else{
                            if(is_array($ret) || $ret instanceof JsonSerializable){
                                $response->header('Conent-Type', 'application/json');
                                $response->end(json_encode($ret,JSON_UNESCAPED_UNICODE));
                            }elseif(is_string($ret)){
                                $response->end($ret);
                            }
                        }
                    }
                }
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
    }
    
    public function start() {
        $this->server->start();
    }

    public function shutdown(){
        $this->server->shutdown();
    }
}