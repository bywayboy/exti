<?php
/*
    全局函数定义文件
    作者: 龚辟愚
    时间: 2021-09-18
*/


use Swoole\Http\Response;
use Swoole\Http\Request;
use Swoole\WebSocket\Frame;

// 判断一个请求是否是WebSocket
function isWebSocket(\Swoole\Http\Request $req){
    $h = $req->header;
    return !empty($h['upgrade']);
}

function json($msg, int $status = 200, string $mime='application/json; charset=utf-8')
{
    return new \sys\resp\JsonResp($msg, $status, $mime);
}

function html(string $msg, int $status = 200)
{
    return new \sys\resp\HtmlResp($msg, $status);
}


/**
 * WebSocket 连接握手
 * @param Swoole\Http\Request  连接请求对象
 * @param Swoole\Http\Response 连接响应对象
 * @param string WebSocket 服务类
 * @param int 队列尺寸.
 */
function upgrade(Request $request, Response $response, string $class, int $queueSize = 8)
{
    try{
        $m = new $class();
        if(! ($m instanceof \sys\services\WebSocket)){
            return json(['success'=>false, 'message'=>'类 '.$class.' 必须派生自 \sys\websocket\WebSocket'], 401);
        }

        if(false === $m->BeforeUpgrade($request)){
            return json(['success'=>false, 'message'=>'error'], 401);
        }
    }catch(\Throwable $e){
        return json(['success'=>false, 'message'=>$e->getMessage(), 'trace'=>$e->getTrace()], 500);
    }

    //接收协程
    //负责将客户端的请求映射
    $channel = new \Swoole\Coroutine\Channel($queueSize);
    \Swoole\Coroutine::create(function(\Swoole\Coroutine\Channel $channel, Response $response)
    {
        while(true)
        {
            if($msg = $channel->pop(60)){
                if(is_array($msg)){
                    $response->push(json_encode($msg, JSON_UNESCAPED_UNICODE));
                }elseif(is_string($msg)){
                    $response->push($msg);
                }else{
                    $response->push($msg);
                }
                continue;
            }
            if($channel->errCode === SWOOLE_CHANNEL_CLOSED)
                break;
        }
    }, $channel, $response);

    //连接握手
    $response->upgrade();
    //连接成功事件
    try{
        $m->AfterConnected();
    }catch(\Throwable $e){
        //TODO: 记录错误
    }

    $pingnum = 0;
    while(true)
    {
        // 0x0 数据附加帧 0x01 文本数据帧 0x02 二进制帧 0x8 连接关闭 0x09 ping 0x0A pong 
        $frame = $response->recv(60);
        if($frame === ''){
            $response->close();
            break;
        }

        if($frame === false) {
            $errno = swoole_last_error();
            if(110 == $errno){
                //超时了, 主动发送 ping
                $frame = new Frame();
                $frame->opcode = WEBSOCKET_OPCODE_PING;
                $channel->push($frame);
                $pingnum ++;
                if($pingnum > 2){
                    $response->close();
                    break;
                }
                continue;
            }
            break;
        }else{
            $class = get_class($frame);
            if($class === CloseFrame::class){
                $response->close();
                break;
            }else{
                switch($frame->opcode){
                case WEBSOCKET_OPCODE_TEXT: //文本消息
                    $data = json_decode($frame->data, true);
                    if(array_key_exists('action', $data)){
                        $action = $data['action'];
                        try{
                            if($m->methodNotAllowed($action))
                            {
                                $channel->push(['action'=>$action, 'success'=>false,'message'=>'action not allowed!', 'done'=>true]);
                            }else{
                                if(null !=($ret = $m->$action($data))){
                                    $channel->push($ret);
                                }
                            }
                        }catch(\Throwable $e){
                            $channel->push(['success'=>false, 'action'=>$action, 'message'=>$e->getMessage(), 'trace'=>$e->getTrace()]);
                        }
                    }
                    break;
                case WEBSOCKET_OPCODE_BINARY: //二进制消息
                    try{
                        if(null !=($ret = $m->OnBinaryMessage($frame->data))){
                            $channel->push($ret);
                        }
                    }catch(\Throwable $e){
                        $channel->push(['success'=>false, 'message'=>$e->getMessage(), 'trace'=>$e->getTrace()]);
                    }
                    break;
                case WEBSOCKET_OPCODE_PONG:
                    $pingnum = 0;
                    break;
                }
            }
        }
    }
    try{
        $m->AfterClose();
    }catch(\Throwable $e){
        //TODO: 记录错误
    }
}



/**
 * 表单验证
 */
function validate(string $class, string $scene, array $data) : bool
{
    
    return true;
}

if(!function_exists('LoadEnvironmentFromFile')){
    function LoadEnvironmentFromFile(){
        $envfile = APP_ROOT . '/scripts/.env';
        if(is_file($envfile)){
            $ctx = file_get_contents($envfile);
            $lines = explode("\n", $ctx);
            foreach($lines as $line){
                $env = trim($line, "\r\n\t ");
                if('' != $env){
                    echo "setenv: {$env}\n";
                    putenv($env);
                }
            }
        }
    }
}