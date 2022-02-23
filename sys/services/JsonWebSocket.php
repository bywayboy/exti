<?php
declare(strict_types=1);

namespace sys\services;

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\WebSocket\CloseFrame;
use Swoole\WebSocket\Frame;

class JsonWebSocket extends WebSocket
{

    public function execute(Response $response) : void {
        # 消息推送Channel
        $channel = new \Swoole\Coroutine\Channel($this->queue_size);
        \Swoole\Coroutine::create(function(\Swoole\Coroutine\Channel $channel, Response $response)
        {
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
        }, $channel, $response);

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
                    $channel->push($frame);
                    unset($frame);
                }
            }else{
                # 关闭帧
                if($frame instanceof CloseFrame){
                    $response->close();
                    break;
                }else{
                    switch($frame->opcode){
                    case WEBSOCKET_OPCODE_TEXT: //文本消息
                        $data = json_decode($frame->data, true);
                        break;
                    case WEBSOCKET_OPCODE_PONG:
                        $wait_times = 0;
                        break;
                    case WEBSOCKET_OPCODE_PING:
                        $frame = new Frame();
                        $frame->opcode = WEBSOCKET_OPCODE_PING;
                        $channel->push($frame);
                        break;
                    case WEBSOCKET_OPCODE_BINARY:
                        break;
                    }
                }
            }
        }
        $this->AfterClose();
    }
}
