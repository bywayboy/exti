<?php
declare(strict_types=1);

namespace sys\servers;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\Socket;
use sys\Log;

class UdpServer {
    protected bool $running = true;
    protected string $ns;
    protected string $module;
    protected \Swoole\Coroutine\Socket $server;
    protected Channel $channel;

    /**
     * @param array $config 当前模块配置 来自 \config\app.php
     * @param string $module 模块名称
     * @param int $workerId 工作进程ID 0~n
     */
    public function __construct(array $config, string $module, int $workerId)
    {
        $sharePort = $config['share_port'];
        $listenPort = $sharePort ? $config['listen_port'] : $config['listen_port'] + $workerId;

        $this->server = $server = new \Swoole\Coroutine\Socket(AF_INET, SOCK_DGRAM, 0); # IPPROTO_UDP

        # 开启端口复用
        $server->setOption(SOL_SOCKET, SO_REUSEPORT, true);

        $server->bind($config['bind_address'], $listenPort);
        $this->ns = "\\app\\{$module}\\controller\\";
        $this->module = $module;
    }

    public function __destruct()
    {

    }

    public function start() {
        
        $this->running = true;
        $this->channel = $channel = new Channel(0);
        $server = $this->server;
        # 服务器请求路由映射
        Coroutine::create(function()use($channel, $server){
            $class = $this->ns . "Index";
            $module = $this->module;
            
            while($this->running){
                $peer = null;
                if(false === ($data = $server->recvfrom($peer))){
                    if($server->errCode === 110){
                        continue;
                    }else{
                        break;
                    }
                }else{
                    \Swoole\Coroutine::create(function()use($peer, $data, $channel, $class){
                        # UDP业务部分.
                        try{
                            $m = new $class();
                            $ret = $m->index($data, $peer);
                            if(true === $ret){
                                return;
                            }
                            $channel->push([$peer, $ret]);
                        }catch(\Throwable $e){
                            Log::console(implode([$e->getMessage(),"\nFILE: ", $e->getFile(), "\nLINE: ", $e->getLine(),"\nTRACE: ", $e->getTraceAsString(), PHP_EOL]),'ERROR');
                        }
                    });
                }
            }
        });

        while($this->running){
            $msg = $channel->pop();
            if($msg === false){
                break;
            }
            list($peer, $data) = $msg;
            $server->sendto($peer['address'], $peer['port'], $data);
        }
        $this->server->close();
        Log::console("UDP Server shutdown.", 'INFO' );
    }

    public function shutdown(){
        $this->running = false;
        $this->channel->close();
    }
}