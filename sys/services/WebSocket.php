<?php
declare(strict_types=1);

namespace sys\services;

use Swoole\Http\Request;
use sys\SysEvents;

class WebSocket {
    protected static array $defmethods = ['BeforeUpgrade'=>true,'AfterConnected'=>true,'AfterClose'=>true,'OnBinaryMessage'=>true,'BeforeShutdown'=>true];

    public function __construct()
    {
        
    }
    
    public function __destruct()
    {
        SysEvents::unbind($this, 'shutdown');
    }

    /**
     * WebSocket 连接建立之前触发该事件
     * @return bool true 表示允许连接 false 表示反对连接.
     */
    public function BeforeUpgrade(Request $request) : bool
    {
        return true;
    }

    /**
     * 连接已经转换成 WebSocket
     * 连接建立后执行, 可以将其保存到全局表中, 以实现跨连接成通信
     **/
    public function AfterConnected() : void
    {
        // 绑定系统退出事件.
        SysEvents::bind($this, 'shutdown', 'BeforeShutdown');
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

    /**
     * 服务器关闭之前执行的方法.
     */
    public function BeforeShutdown():void{

    }

    public function methodNotAllowed(string $methodName):bool
    {
        return static::$defmethods[$methodName] ?? false;
    }


}