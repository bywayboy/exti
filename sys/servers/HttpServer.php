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

        $tpl = array_merge([
            'root'=>dirname(SITE_ROOT) . "/app/{$module}/tpl",
            'cache'=>dirname(SITE_ROOT) . "/var/cache/{$module}"
        ], $config['tpl'] ?? []);
        
        # 服务器请求路由映射
        $this->server->handle('', function(Request $request, Response $response) use($module, $tpl, $sharePort) {
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
                if($ret = $m->$method($request, $response)) {
                    if($ret instanceof \sys\servers\http\Resp){
                        $ret->output($response, $tpl);
                        return;
                    }
                    if(is_array($ret) || $ret instanceof JsonSerializable){
                        $response->header('Conent-Type', 'application/json');
                        $response->end(json_encode($ret,JSON_UNESCAPED_UNICODE));
                        return;
                    }elseif(is_string($ret)){
                        $response->header('Conent-Type', 'text/plain');
                        $response->end($ret);
                        return;
                    }
                }
                $response->setStatusCode(204);
                $response->end();
            }catch(\Throwable $e){
                $file = SITE_ROOT. $uri;
                $response->header('Content-Type','text/html');
                $response->status(404);

                $err = [
                    '<!DOCTYPE html>', '<html><head><title>404 Not Found</title><meta charset="utf-8"></head><body>',
                    '<h3>ERROR: '.$e->getMessage(), '</h3><div>file:', 
                    $e->getFile(), ' : ', $e->getLine().'</div>',
                    '<div>Class: ', $class, '</div><pre style="line-height:150%;">',
                    "TraceBack:", $e->getTraceAsString(),
                    '</pre></body></html>'
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