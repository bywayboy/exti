<?php
declare(strict_types=1);

namespace sys\services;

use Swoole\Http\Response;
use Swoole\Timer;
use Swoole\WebSocket\CloseFrame;
use Swoole\WebSocket\Frame;

abstract class WebSocket {
    
    public int $queue_size      = 8;        # 消息发送队列尺寸
    public int $wait_timeout    = 60;       # 等待超时
    public int $wait_times      = 2;        # 等待超时次数 超过后会被关闭.

    protected ?\Swoole\Coroutine\Channel $channel = null;

    /**
     * WebSocket 连接成功
     */
    protected function afterConnected() : bool {
        return true;
    }
    /**
     * WebSocket 连接断开
     **/
    protected function afterClose() : void
    {
    }

    /**
     * 文本帧到达
     */
    protected function OnTextMessage_(string $message) : void {}

    /**
     * 二进制帧到达
     */
    protected function OnBinaryMessage_(string $message) : void {}

    public function execute(Response $response) : bool {
        $this->channel = new \Swoole\Coroutine\Channel($this->queue_size);
        \Swoole\Coroutine::create(function(Response $response)
        {
            $channel = $this->channel;
            while(true)
            {
                if($msg = $channel->pop(60)){
                    
                    if(is_string($msg) || $msg instanceof \Swoole\WebSocket\Frame){
                        $response->push($msg);
                    }else{
                        $frame = new \Swoole\WebSocket\Frame();
                        $frame->data = json_encode($msg, JSON_UNESCAPED_UNICODE);
                        $frame->opcode = SWOOLE_WEBSOCKET_OPCODE_TEXT;
                        $frame->flags  = SWOOLE_WEBSOCKET_FLAG_COMPRESS;
                        $frame->finish = true;
                        $response->push($frame);
                    }
                    continue;
                }
                if($channel->errCode === SWOOLE_CHANNEL_CLOSED)
                    break;
            }
        },$response);

        $wait_times  = 0;

        # 认证没通过
        if(false === $this->afterConnected()) {
            $this->channel->close();
            $timerid = Timer::after(20000, function() use($response){
                $response->close();
                # 连接关闭事件
                $this->afterClose();
            });
            return true;
        }

        # 消息接收循环
        while(true)
        {
            // 0x0 数据附加帧 0x01 文本数据帧 0x02 二进制帧 0x8 连接关闭 0x09 ping 0x0A pong 
            $frame = $response->recv($this->wait_timeout);
            if($frame === ''){
                echo ">> ERROR...\n";
                break;
            }

            if($frame === false) {
                $errno = swoole_last_error();
                if(110 == $errno){
                    # 超时了
                    if($wait_times++ > $this->wait_times){
                        # echo ">> TIMEOUT...\n";
                        break;
                    }

                    $frame = new Frame();
                    $frame->opcode = WEBSOCKET_OPCODE_PING;
                    $this->channel->push($frame);
                    $frame = null;
                }
            }else{
                # 关闭帧
                if($frame instanceof CloseFrame){
                    echo ">> close frame...\n";
                    break;
                }else{
                    switch($frame->opcode){
                    case WEBSOCKET_OPCODE_TEXT:
                        $this->OnTextMessage_($frame->data);
                        break;
                    case WEBSOCKET_OPCODE_BINARY:
                        $this->OnBinaryMessage_($frame->data);
                        break;
                    case WEBSOCKET_OPCODE_PONG:
                        $wait_times = 0;
                        break;
                    case WEBSOCKET_OPCODE_PING:
                        $wait_times = 0;
                        $frame = new Frame();
                        $frame->opcode = WEBSOCKET_OPCODE_PONG;
                        $this->channel->push($frame);
                        break;
                    }
                }
            }
        }

        # 清理超时关闭定时任务.

        if(isset($timerid)){
            Timer::clear($timerid);
        }

        $response->close();
        $this->channel->close();

        # echo "after close ......\n";
        # 连接关闭事件
        $this->afterClose();
        return true;
    }


    /**
     * 动态修改等待超时
     * @param int $waitTimeout 超时时间 单位 秒
     * @param int $waitTimes 发送PING多少次没响应自动关闭连接.
     */
    public function setIdleTimeout(int $waitTimeout, int $waitTimes = 1) {
        $this->wait_timeout = max($waitTimeout, 3);
        $this->wait_times = max($waitTimes, 1);
    }
    
    public function getChannel() :?\Swoole\Coroutine\Channel
    {
        return $this->channel ?? null;
    }

    public function close(){
        $this->channel->close();
        $this->response->close();
    }

    public function push($msg) {
        $this->channel->push($msg);
    }
}