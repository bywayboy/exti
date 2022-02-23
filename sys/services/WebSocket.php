<?php
declare(strict_types=1);

namespace sys\services;

use Swoole\Http\Request;
use Swoole\Http\Response;
use sys\servers\http\Resp;
use sys\SysEvents;

abstract class WebSocket {
    
    public int $queue_size      = 8;        # 消息发送队列尺寸
    public int $wait_timeout    = 60;       # 等待超时
    public int $wait_times      = 2;        # 等待超时次数 超过后会被关闭.

    protected static array $SysMethods = ['BeforeUpgrade'=>true, 'OnUpgradeFailed'=>true, 'AfterConnected'=>true,'AfterClose'=>true,'OnBinaryMessage'=>true];

    /**
     * WebSocket 连接建立之前触发该事件
     * @return bool true 表示允许连接 false 表示反对连接.
     */
    public function BeforeUpgrade(Request $request, callable $next) : ?Resp
    {
        return $next();
    }

    /**
     * 握手失败时到回调
     */
    public function OnUpgradeFailed() : Resp {
        return json(['success'=>false, 'message'=>'Upgrade Failed.'], 401);
    }


    /**
     * 连接已经转换成 WebSocket
     * 连接建立后执行, 可以将其保存到全局表中, 以实现跨连接成通信
     **/
    public function AfterConnected(Request $request) : void
    {
        // 绑定系统退出事件.
        #SysEvents::bind($this, 'shutdown', 'BeforeShutdown');
    }

    /**
     * WebSocket 连接断开
     **/
    public function AfterClose() : void
    {
        
    }

    /**
     *  收到二进制消息.
     */
    public function OnBinaryMessage(string $data) : void
    {

    }


    public function methodNotAllowed(string $methodName):bool
    {
        return static::$SysMethods[$methodName] ?? false;
    }

    public function execute(Response $response) : void {
        
    }
}