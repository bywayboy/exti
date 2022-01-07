<?php
declare(strict_types=1);

namespace app\index\ws;

use Swoole\Http\Request;
use sys\services\WebSocket;

/**
 *  真正的 WebSocket业务类
 */

class ServiceWs extends WebSocket {

    // 协议切换之前要执行的方法.
    public function BeforeUpgrade(Request $request): bool
    {
        $data = json_decode($request->getContent(), true);

        return true;    
    }

    //WebSocket 连接建立成功
    public function AfterConnected(): void
    {
        
    }

    //WebSocket 连接断开后触发的事件
    public function AfterClose(): void
    {
        
    }

    //服务关闭之前执行的方法.
    public function BeforeShutdown(): void
    {
        
    }

    // 收到事件
    public function pwron(array $data)
    {

    }
    
}

