<?php
declare(strict_types=1);

namespace app\index\ws;

use Swoole\Http\Request;
use sys\services\JsonWebSocket;


/**
 *  真正的 WebSocket业务类
 */

class ServiceWs extends JsonWebSocket {

    //WebSocket 连接断开后触发的事件
    public function AfterClose(): void
    {
        
    }

 
    // 收到事件
    public function pwron(array $data)
    {

    }
    
}

