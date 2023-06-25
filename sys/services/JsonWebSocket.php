<?php
declare(strict_types=1);

namespace sys\services;

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
            try{
                $_SEQ = isset($data['_SEQ']) ? $data['_SEQ'] : null;
                $_DONE = false;
                $sender = function(array $data, bool $done = false) use($_SEQ, &$_DONE) :void {
                    if(false === $_DONE){
                        $_DONE = $done;
                        if(null !== $_SEQ) $data['_SEQ'] = $_SEQ;
                        $data['done'] = $done;
                        $this->channel->push($data);
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
        }elseif(isset($data['event'])){
            // 收到客户端的事件通知
        }elseif(isset($data['_SEQ'])){
            // 收到客户端的响应
            Log::console("收到响应: ".json_encode($data, JSON_UNESCAPED_UNICODE), 'DEBUG');
            if(isset($this->callbacks[$data['_SEQ']])) {
                // 获取内容并清理
                $callback = $this->callbacks[$data['_SEQ']];
                unset($this->callbacks[$data['_SEQ']]);

                // 执行响应回调
                Timer::clear($callback[0]);
                $callback[1]($data);
            }
        }
    }

    /**
     * 服务器向客户端发起一个远程调用
     */
    public function call(string $action, null|array $data, int $timeout, \Closure $callback) {
        $Seq = $this->_SEQ ++;

        Log::console("远程调用:{$Seq}, {$action}", 'DEBUG');
        $this->callbacks[$Seq] = [Timer::after($timeout, function() use($callback, $Seq){
            if(isset($callbacks[$Seq])){
                unset($callbacks[$Seq]);
                Log::console("等待超时: {$Seq}", 'ERROR');
                $callback(['success'=>false, 'message'=>'等待超时']);
            }
        }), $callback];

        $this->channel->push([
            'action'=>$action,
            '_SEQ'=>$Seq,
            'data'=>$data,
            'callback'=>$callback
        ]);
    }

    protected function OnBinaryMessage_(string $bin) : void {

    }

    protected function afterConnected() : bool {
        try{
            return call_user_func_array([$this->service, 'afterConnected'], [$this->request, $this]);
        }catch(Throwable $e){
            # 忽略错误.
            Log::write($e->getMessage() . "\ncode:" . $e->getCode() . "\n File: ". $e->getFile() . " at line:".$e->getLine()."\nTrace: ". $e->getTraceAsString(),'ERROR');
        }
        return false;
    }

    /**
     * WebSocket 连接断开
     **/
    protected function afterClose() : void
    {
        echo "jsonwebsocket::afterClose\n";

        try{
            call_user_func_array([$this->service, 'afterClose'], [$this->request, $this]);
        }catch(Throwable $e){
            # 忽略错误.
            Log::write($e->getMessage() . "\ncode:" . $e->getCode() . "\n File: ". $e->getFile() . " at line:".$e->getLine()."\nTrace: ". $e->getTraceAsString(),'ERROR');
        }
    }
}