<?php
declare(strict_types=1);

namespace app\index\controller;

use Swoole\Http\Request;
use Swoole\Http\Response;

use sys\services\JsonWebSocket;

use app\index\ws\ServiceWs;

class Service
{
    // 这是一个 WebSocket入口.
    public function index(Request $request, Response $response)
    {
        # 事务处理对象
        $service = new ServiceWs();

        return upgrade($request, $response, JsonWebSocket::class, $service);
    }
}