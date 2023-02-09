<?php
declare(strict_types=1);

namespace sys\services;

use Swoole\Http\Request;
use Throwable;

class JsonWebSocket extends WebSocket
{
    protected mixed $service;
    protected Request $request;
    
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
                $isDone = false;
                $result = call_user_func_array([$this->service, 'On'.ucwords($data['action'])], [$data, function(array $data) use($_SEQ) {
                    $data['done'] = false;
                    if(null !== $_SEQ) $data['_SEQ'] = $_SEQ;
                    $this->channel->push($data);
                }, function(array $data) use($_SEQ, $isDone){
                    if(false === $isDone){
                        $isDone = true;
                        $data['done'] = true;
                        if(null !== $_SEQ) $data['_SEQ'] = $_SEQ;
                        $this->channel->push($data);
                    }
                }, $this]);

                if(false === $isDone)
                {
                    if(is_array($result)){
                        if(!isset($result['_SEQ'])) {
                            $result['_SEQ'] = $_SEQ;
                            $result['done'] = true;
                        }
                        $this->channel->push($result);
                    }elseif(is_string($result)){
                        $this->channel->push($result);
                    }
                }
            }catch(\Throwable $e){
                echo "EROR:" . $e->getMessage() . "\ncode:" . $e->getCode() . "\n File: ". $e->getFile() . " at line:".$e->getLine()."\nTrace: ". $e->getTraceAsString() . "\n";
            }
        }
    }

    protected function OnBinaryMessage_(string $bin) : void {

    }

    protected function afterConnected() : bool {
        try{
            return call_user_func_array([$this->service, 'afterConnected'], [$this->request, $this]);
        }catch(Throwable $e){
            # 忽略错误.
            echo "EROR:" . $e->getMessage() . "\ncode:" . $e->getCode() . "\n File: ". $e->getFile() . " at line:".$e->getLine()."\nTrace: ". $e->getTraceAsString() . "\n";
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
            echo "EROR:" . $e->getMessage() . "\ncode:" . $e->getCode() . "\n File: ". $e->getFile() . " at line:".$e->getLine()."\nTrace: ". $e->getTraceAsString() . "\n";
        }
    }
}