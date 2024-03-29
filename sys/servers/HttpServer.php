<?php
declare(strict_types=1);

namespace sys\servers;

use JsonSerializable;
use Swoole\Http\Request;
use Swoole\Http\Response;

use sys\Log;
use sys\servers\http\View;

class HttpServer {
    public static int $port;
    protected \Swoole\Coroutine\Http\Server $server;
    
    /**
     * @param array $config 当前模块配置 来自 \config\app.php
     * @param string $module 模块名称
     * @param int $workerId 工作进程ID 0~n
     */
    public function __construct(array $config, string $module, int $workerId)
    {
        $sharePort = $config['share_port'];
        static::$port = $listenPort = $sharePort ? $config['listen_port'] : $config['listen_port'] + $workerId;

        $this->server = new \Swoole\Coroutine\Http\Server($config['bind_address'], $listenPort, $config['ssl'], $sharePort);

        # TODO: 开启了SSL的话, 需要进一步设置证书
        if($config['ssl'])
        {

        }
        $this->server->set([
            'websocket_compression'=>true,
        ]);
        $tpl = array_merge([
            'root'=>dirname(SITE_ROOT) . "/app/{$module}/tpl",
            'cache'=>dirname(SITE_ROOT) . "/var/cache/{$module}"
        ], $config['tpl'] ?? []);
        
        $rewrite = null;
        foreach($config['rewrite'] ?? [] as $key=>$val){
            $rewrite['patterns'][] = $key;
            $rewrite['replacements'][] = $val;
        }
        $path_domain = $config['path_domain'] ?? false;
        # 服务器请求路由映射
        $ns = "\\app\\{$module}\\controller\\";
        $this->server->handle('', function(Request $request, Response $response) use($ns, $tpl, $path_domain, $rewrite, $sharePort) {
            
            if($rewrite){
                $uri = preg_replace($rewrite['patterns'], $rewrite['replacements'], $request->server['request_uri']);
            }else{
                $uri = $request->server['request_uri'];
            }
            \preg_match_all("#\/([\-\w\d_]+)*#i", strtolower($uri) , $matches);
            $psr  = array_filter($matches[1]);
            if($path_domain){
                array_shift($psr);
                //$psr = array_slice($psr, 1);
            }
            $num = count($psr);

            switch($num){
            case 0:
                # 默认映射到 \app\index\services\Index::index
                $class = $ns.'Index';
                $method = 'index';
                break;
            case 1:
                # 只有一个类名 默认映射到 index 方法
                //$class = $ns.ucfirst(array_pop($psr));
                $class = $ns.str_replace('_', '',ucwords(array_pop($psr),'_'));
                $method = 'index';
                break;
            default:
                # 最后一个方法名
                $method = array_pop($psr);
                # 剩下的是路径.
                # $class = ucfirst(array_pop($psr));
                $class = str_replace('_', '', ucwords(array_pop($psr),'_'));
                if(empty($psr)){
                    $class = $ns.$class;
                }else{
                    $class = $ns.implode('\\', $psr).'\\'.$class;
                }                        
                break;
            }
            $ipaddr = $request->header['x-real-ip'] ?? $request->server['remote_addr'];
            Log::write("{$uri} => {$class}:{$method} {$ipaddr}", "CALL");

            try{
                $m = new $class();
                # 中间件遍历
                if(property_exists($m, 'middleware')){
                    foreach($m->middleware as $middleware=>$rule){
                        if(is_array($rule)){
                            if(!($rule['except'][$method] ?? false))
                                if($ret = $middleware::handle($request, $response, $m))
                                    break;
                        }else{
                            if($ret = $rule::handle($request, $response, $m))
                                break;
                        }
                    }
                    if(!isset($ret) || null === $ret)
                        $ret = $m->$method($request, $response);
                }else{
                    $ret = $m->$method($request, $response);
                }
                if($ret) {
                    if(true === $ret) return;
                    if($ret instanceof \sys\servers\http\Resp){
                        $ret->output($response, $tpl);
                        return;
                    }
                    if(is_array($ret) || $ret instanceof JsonSerializable){
                        $response->header('Conent-Type', 'application/json');
                        $response->end(json_encode($ret,JSON_UNESCAPED_UNICODE));
                        return;
                    }elseif(is_string($ret)) {
                        $response->header('Conent-Type', 'text/plain');
                        $response->end($ret);
                        return;
                    }
                }
                echo (true === $ret ? 'TRUE':'FALSE'). "{$class} {$method}\n";
                # 无任何返回的时候 返回204 状态
                $response->setStatusCode(204);
                $response->end();
            }catch(\Throwable $e){
                $file = SITE_ROOT. $uri;
                $response->header('Content-Type','text/html');
                $response->status(404);
                $view = new View('sys/servers/http/tpl/error.php', [
                    'title'=>'发生错误',
                    'message'=>$e->getMessage(),
                    'file'=>substr($e->getFile(), strlen(dirname(SITE_ROOT)) + 1),
                    'mapfile'=>$file,
                    'class'=>$class,
                    'type'=>addcslashes($e::class, "\\"),
                    'line'=>$e->getLine(),
                    'trace'=>$e->getTraceAsString()
                ]);
                $view->output($response,[
                    'root'=>dirname(SITE_ROOT),
                ]);
                Log::console(implode([$e->getMessage(),"\nFILE: ", $e->getFile(), "\nLINE: ", $e->getLine(),"\nTRACE: ", $e->getTraceAsString(),"\n"]),'ERROR');
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