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
                str_replace(['-','_'],['',''], ucwords($data['action'], "-_\t\r\n\f\v"))
                ], [
                    $data,
                    function(array $data) use($sender){$sender($data, false);}, 
                    function(array $data) use($sender){$sender($data, true);},
                    $this
                ]);

                if(false === $_DONE)
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