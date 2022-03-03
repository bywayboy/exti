<?php
declare(strict_types=1);

namespace app\index\controller;

use Swoole\Http\Request;
use Swoole\Http\Response;

use sys\services\JsonWebSocket;

use app\index\ws\ServiceWs;
use Throwable;

class Service
{
    // 这是一个 WebSocket入口.
    public function index(Request $request, Response $response)
    {
        # 事务处理对象
        try{
            # 握手失败 直接返回
            if(false === upgrade($request, $response, JsonWebSocket::class, ServiceWs::class)){
                return json(['success'=>false,'message'=>'upgrade failed!']);
            }
           return true;
        }catch(Throwable $e){
            echo "error: ". $e->getMessage(). "\n";
        }
        return json(['success'=>false,'message'=>'error!!!!!']);
    }
}