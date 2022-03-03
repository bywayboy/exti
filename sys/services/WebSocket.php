<?php
declare(strict_types=1);

namespace sys\services;

use Swoole\Http\Request;
use Swoole\Http\Response;
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
    protected function afterConnected(Request $request){

    }
    /**
     * WebSocket 连接断开
     **/
    protected function AfterClose() : void
    {
        
    }


    protected function OnTextMessage_(string $message) : void {

    }
    protected function OnBinaryMessage_(string $message) : void {

    }

    public function execute(Response $response) : bool {
        $this->channel = new \Swoole\Coroutine\Channel($this->queue_size);
        \Swoole\Coroutine::create(function(Response $response)
        {
            $channel = $this->channel;
            while(true)
            {
                if($msg = $channel->pop()){
                    if($channel->errCode === SWOOLE_CHANNEL_CLOSED)
                        break;
                    if(is_array($msg)){
                        $response->push(json_encode($msg, JSON_UNESCAPED_UNICODE));
                    }else{
                        $response->push($msg);
                    }
                    continue;
                }
            }
        },$response);

        $wait_times  = 0;

        # 消息接收循环
        while(true)
        {
            // 0x0 数据附加帧 0x01 文本数据帧 0x02 二进制帧 0x8 连接关闭 0x09 ping 0x0A pong 
            $frame = $response->recv($this->wait_timeout);
            if($frame === ''){
                $response->close();
                break;
            }

            if($frame === false) {
                $errno = swoole_last_error();
                if(110 == $errno){
                    # 超时了
                    if($wait_times++ > $this->wait_times){
                        $response->close();
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
                    $response->close();
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
                        $frame = new Frame();
                        $frame->opcode = WEBSOCKET_OPCODE_PING;
                        $this->channel->push($frame);
                        break;
                    }
                }
            }
        }

        # 连接关闭事件
        $this->AfterClose();
        return true;
    }

    public function close(){
        $this->channel->close();
        $this->response->close();
    }
}