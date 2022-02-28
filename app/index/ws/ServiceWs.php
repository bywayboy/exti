<?php
declare(strict_types=1);

namespace app\index\ws;

use Swoole\Http\Request;
use sys\services\JsonWebSocket;


/**
 *  真正的 WebSocket业务类
 */

class ServiceWs {
 
    # 收到事件 pwron
    public function OnPwron(JsonWebSocket $ws, array $data)
    {
        
    }


    # WS连接进入事件
    public function afterConnected(JsonWebSocket $ws, Request $request) {
        
    }

    # WS连接断开事件
    public function AfterClose(){

    }
}

