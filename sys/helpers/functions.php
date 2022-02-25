<?php
declare(strict_types=1);

/*
    全局函数定义文件
    作者: 龚辟愚
    时间: 2021-09-18
*/


use Swoole\Http\Response;
use Swoole\Http\Request;
use Swoole\WebSocket\Frame;
use sys\servers\http\Json;
use sys\servers\http\Resp;
use sys\services\WebSocket;

// 判断一个请求是否是WebSocket
function isWebSocket(\Swoole\Http\Request $req){
    $h = $req->header;
    return !empty($h['upgrade']);
}

function json($msg, int $status = 200, string $mime='application/json; charset=utf-8') : Json
{
    return new \sys\servers\http\Json($msg, $status, $mime);
}

function html(string $msg, int $status = 200)
{
    return new \sys\servers\http\Html($msg, $status);
}

/**
 * WebSocket 连接握手
 * @param \sys\services\WebSocket  连接请求对象
 * @param Swoole\Http\Response 连接响应对象
 * @param string WebSocket 服务类
 * @param int 队列尺寸.
 */
function upgrade(Request $request, Response $response, string $service, $m) : bool
{
    
    #  实例化对象

    if(!($service instanceof \sys\services\WebSocket)) {
        return json(['success'=>false, 'message'=>'类 '.$service.' 必须派生自 \sys\websocket\WebSocket'], 401);
    }

    # 第一步: 连接握手
    if(false === $response->upgrade()){
        # 连接建立失败
        return false;
    }
    $s = new $service($m);
    $s->execute();
    return true;
}



if(!function_exists('LoadEnvironmentFromFile')) {
    /**
     * 系统内置函数, 加载环境变量文件.
     */
    function LoadEnvironmentFromFile(){
        $envfile = APP_ROOT . '/scripts/.env';
        if(is_file($envfile)){
            $ctx = file_get_contents($envfile);
            $lines = explode("\n", $ctx);
            foreach($lines as $line){
                $env = trim($line, "\r\n\t ");
                if('' != $env && '#' != $env[0]){
                    putenv($env);
                }
            }
        }
    }
}

if(!function_exists('validate')){
    /**
     * 校验快捷函数
     * @param array $$rules             校验规则
     * @param null|string $cacheKey     缓存键值
     * @return \sys\validator\Validator
     */
    function validate(array $rules, ?string $cacheKey = null) :\sys\validator\Validator {
        return new \sys\validator\Validator($rules, $cacheKey);
    }
}

if(!function_exists('rmb_format')) {
    /**
     * @param float $rmb 一个浮点数
     * @return string 返回保留0～2位小数的数字
     */
    function rmb_format(float $rmb) : string {
        return rtrim(rtrim(number_format($rmb, 2, '.', ''), '0'), '.');
    }
}

if(!function_exists('array_to_object')){
    function array_to_object(array $array, string $class, bool $renew) :array {
        foreach($array as &$item){
            $item = $class::CreateInstance($item, false, $renew);
        }
        return $array;
    }
}

if(!function_exists('array_is_list')){
    /**
     * 在PHP-8.1 这是一个内置函数
     */
    function array_is_list(array $array): bool {
        $expectedKey = 0;
        foreach ($array as $i => $_) {
            if ($i !== $expectedKey) { return false; }
            $expectedKey++;
        }
        return true;
    }
}
