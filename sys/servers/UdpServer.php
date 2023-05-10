<?php
declare(strict_types=1);

use Swoole\Coroutine;
use Swoole\Coroutine\Socket;

class UdpServer {
    protected $sock = null;

    public function __construct()
    {
        
    }

    public function __destruct()
    {

    }

    public function execute($listen)
    {
        
        $sock = new \Swoole\Coroutine\Socket(AF_INET, SOCK_DGRAM, 0); # IPPROTO_UDP

        # 开启端口复用
        $sock->setOption(SOL_SOCKET, SO_REUSEPORT, true);

        $this->sock = $sock->bind('127.0.0.1', 9502);

        while(true){
            $peer = null;
            if(false === ($data = $sock->recvfrom($peer))){
                if($sock->errCode === 110){
                    continue;
                }else{
                    break;
                }
            }else{
                \Swoole\Coroutine::create(function(Socket $sock, $peer, $data){
                    # UDP业务部分.
                }, $sock, $peer, $data);
            }
        }

        if(null !== $this->sock && !$this->sock->isClosed()){
            $this->sock->close();
        }
        $this->sock = null;
    }
    
    public function close(){
        if(null !== $this->sock && ! $this->sock->isClosed()){
            $this->sock->close();
        }
        $this->sock = null;
    }
}