<?php
declare(strict_types=1);

namespace sys\services;

use Swoole\Http\Request;
use Throwable;

class JsonWebSocket extends WebSocket
{
    protected $service;
    protected Request $request;
    
    public function __construct(Request $request, mixed $service)
    {
        $this->request = $request;
        $this->service = $service;
    }

    protected function OnTextMessage_(string $text) : void {
        # 解码数据
        echo "OnTextMessage_{$text}\n";
        $data = json_decode($text, true);
        # 将 action 映射到服务等方法去执行
        if(isset($data['action'])){
            if($ret = call_user_func_array([$this->service, 'On'.ucfirst($data['action'])], [$data, $this])) {
                $this->channel->push($ret);
            }
        }
    }

    protected function OnBinaryMessage_(string $bin) : void {

    }

    protected function afterConnected(Request $request){
        try{
            call_user_func_array([$this->service, 'afterConnected'], [$this, $request]);
        }catch(Throwable $e){
            # 忽略错误.
        }
    }

    protected function AfterClose(): void
    {
        try{
            call_user_func_array([$this->service, 'AfterClose'], [$this]);
        }catch(Throwable $e){
            # 忽略错误.
        }
    }
}
