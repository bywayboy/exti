<?php
declare(strict_types=1);

namespace sys\services;

use Swoole\Coroutine;
use Swoole\Http\Request;
use Swoole\Timer;
use sys\Log;
use Throwable;

class JsonWebSocket extends WebSocket
{
    protected mixed $service;
    protected Request $request;
    
    private int $_SEQ = 1;
    private array $callbacks = [];
    public function __construct(Request $request, mixed $service)
    {
        $this->request = $request;
        $this->service = $service;
    }

    protected function OnTextMessage_(string $text) : void {
        # 解码数据
        # echo "OnTextMessage_{$text}\n";
        $data = json_decode($text, true);
        # 将 action 映射到服务等方法去执行
        if(isset($data['action'])){
            Coroutine::create(function() use($data) {
                try{
                    $_SEQ = isset($data['_SEQ']) ? $data['_SEQ'] : null;
                    $_DONE = false;
                    $channel = $this->channel;
                    $sender = function(array $data, bool $done = false) use($_SEQ, &$_DONE, $channel) :void {
                        if(false === $_DONE){
                            $_DONE = $done;
                            if(null !== $_SEQ) $data['_SEQ'] = $_SEQ;
                            $data['done'] = $done;
                            $channel->push($data);
                            return;
                        }
                        // throw new \Exception("Message has been sent!");
                    };
    
                    $result = call_user_func_array([$this->service, 'on'.
                    str_replace(['-','_','.'],['','',''], ucwords($data['action'], "-_.\t\r\n\f\v"))
                    ], [
                        $data,
                        function(array $data) use($sender){$sender($data, false);}, 
                        function(array $data) use($sender){$sender($data, true);},
                        $this
                    ]);
    
                    if(false === $_DONE)
                    {
                        if(is_array($result)){
                            $this->channel->push($result+['_SEQ'=>$_SEQ,'done'=>true]);
                        }elseif(is_string($result)){
                            $this->channel->push($result);
                        }
                    }
                }catch(\Throwable $e){
                    Log::write($e->getMessage() . "\ncode:" . $e->getCode() . "\n File: ". $e->getFile() . " at line:".$e->getLine()."\nTrace: ". $e->getTraceAsString(),'ERROR');
                }
            });
        }elseif(isset($data['event'])){
            // 收到客户端的事件通知
        }elseif(isset($data['_SEQ'])){
            // 收到客户端的响应
            Log::console("收到响应: ".json_encode($data, JSON_UNESCAPED_UNICODE), 'DEBUG');
            if(null !== ($channel = $this->callbacks[$data['_SEQ']] ?? null)) {
                // 获取内容并清理
                $channel->push($data);
            }
        }
    }

    /**
     * 服务器向客户端发起一个远程调用
     */
    public function call(string $action, null|array $data, int $timeout, \Closure $update) :array {
        $Seq = $this->_SEQ ++;

        Log::console("远程调用:{$Seq}, {$action}", 'DEBUG');
        $channel = new \Swoole\Coroutine\Channel(1);
        $this->channel->push([
            'action'=>$action,
            '_SEQ'=>$Seq,
            'data'=>$data,
            'timeout'=>$timeout,
        ]);

        $this->callbacks[$Seq] = $channel;
        while(true){
            $msg = $channel->pop($timeout);
            if(is_array($msg)){
                if(!isset($msg['done']) || $msg['done']){
                    unset($this->callbacks[$Seq]);
                    if(empty($this->callbacks)){
                        $this->callbacks = [];
                    }
                    $channel->close();
                    return $msg;
                }else{
                    $update($msg);
                }
            }else{
                switch($channel->errCode){
                case SWOOLE_CHANNEL_CLOSED:
                    $ret =  ['success'=>false, 'message'=>'连接已断开'];
                    break;
                case SWOOLE_CHANNEL_TIMEOUT:
                    $ret =  ['success'=>false, 'message'=>'等待响应超时'];
                    break;
                case SWOOLE_CHANNEL_CANCELED:
                    $ret =  ['success'=>false, 'message'=>'系统正忙, 请稍后再试'];
                    break;
                default:
                    $ret =  ['success'=>false, 'message'=>'未知错误'];
                    break;
                }
                unset($this->callbacks[$Seq]);
                if(empty($this->callbacks)){
                    $this->callbacks = [];
                }
                $channel->close();
                return $ret;
            }
        }
    }

    protected function OnBinaryMessage_(string $bin) : void {

    }

    protected function afterConnected() : bool {
        $channel = new \Swoole\Coroutine\Channel(1);
        Coroutine::create(function() use($channel){
            try{
                $channel->push(call_user_func_array([$this->service, 'afterConnected'], [$this->request, $this]));
            }catch(Throwable $e){
                # 忽略错误.
                $channel->push(false);
                Log::write($e->getMessage() . "\ncode:" . $e->getCode() . "\n File: ". $e->getFile() . " at line:".$e->getLine()."\nTrace: ". $e->getTraceAsString(),'ERROR');
            }
        });
        return $channel->pop();
    }

    /**
     * WebSocket 连接断开
     **/
    protected function afterClose() : void
    {
        echo "jsonwebsocket::afterClose\n";
        Coroutine::create(function(){
            try{
                call_user_func_array([$this->service, 'afterClose'], [$this->request, $this]);
            }catch(Throwable $e){
                # 忽略错误.
                Log::write($e->getMessage() . "\ncode:" . $e->getCode() . "\n File: ". $e->getFile() . " at line:".$e->getLine()."\nTrace: ". $e->getTraceAsString(),'ERROR');
            }
        });
    }
}