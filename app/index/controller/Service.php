<?php
declare(strict_types=1);

namespace app\index\controller;

use app\index\ws\ServiceWs;
use Swoole\Http\Request;
use Swoole\Http\Response;

class Service
{
    // 这是一个 WebSocket入口.
    public function index(Request $request, Response $response)
    {
        return upgrade($request, $response, ServiceWs::class);
    }


}