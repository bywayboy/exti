<?php
declare(strict_types=1);

/*
    全局函数定义文件
    作者: 龚辟愚
    时间: 2021-09-18
*/


use Swoole\Http\Response;
use Swoole\Http\Request;
use sys\servers\http\Json;
use sys\servers\http\Html;
use sys\servers\http\Nothing;
use sys\servers\http\Redirect;
use sys\servers\http\Text;
use sys\servers\http\View;
use sys\servers\http\Xml;


// 判断一个请求是否是WebSocket
function isWebSocket(\Swoole\Http\Request $req){
    $h = $req->header;
    return !empty($h['upgrade']);
}

if(!function_exists('json')) {
    function json($msg, int $status = 200, string $mime='application/json; charset=utf-8') : Json
    {
        return new Json($msg, $status, $mime);
    }
}

if(!function_exists('html')) {
    function html(string $msg, int $status = 200) : Html
    {
        return new Html($msg, $status);
    }
}

if(!function_exists('text')) {
    function text(string $msg, int $status = 200) : Text
    {
        return new Text($msg, $status);
    }
}


if(!function_exists('xml')) {
    function xml(string $msg, int $status = 200) : Xml
    {
        return new Xml($msg, $status);
    }
}


if(!function_exists('nothing')) {
    function nothing() : Nothing {
        return new Nothing();
    }
}

if(!function_exists('redirect')){
    /**
     * 重定向到指定页面
     */
    function redirect(string $location, int $status = 302){
        return new Redirect($location, $status);
    }
}

/**
 * 返回一个模板渲染对象
 * @param string $tpl 模板文件
 * @param mixed $vars 变量
 * @return View 模板视图对象
 */
function view(string $tpl, mixed $vars) : View {
    return new View($tpl, $vars);
}

if(!function_exists('upgrade')) {
    /**
     * WebSocket 连接握手
     * @param \sys\services\WebSocket  连接请求对象
     * @param Swoole\Http\Response 连接响应对象
     * @param string WebSocket 服务类
     * @param int 队列尺寸.
     */
    function upgrade(Request $request, Response $response, string $service, string $m) : bool
    {
        # 实例化对象
        if(!is_subclass_of($service, \sys\services\WebSocket::class)) {
            return json(['success'=>false, 'message'=>'类 '.$service.' 必须派生自 \sys\websocket\WebSocket'], 401);
        }

        # 第一步: 连接握手
        if(false === $response->upgrade()){
            # 连接建立失败
            return false;
        }

        $s = new $service($request, new $m);
        $s->execute($response);
        return true;
    }
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


if(!function_exists('http_post'))
{
    /**
     * 发起一个HTTP Post 请求.
     * @param string $url 请求资源地址.
     * @param string $payload POST 的内容
     * @param null|array $headers HTTP协议头部信息
     * @param int $timeout 超时时间
     * @param  null|array $certs 证书 需包含2个成员 ssl_cert_file, ssl_key_file
     * @return array return_code 错误码 0 成功, code http返回状态码, url 请求的url, body 返回内容.
     */
    function http_post(string $url, string $payload, ?array $headers = null, int $timeout = 30, ?array $certs = null) : array {
        $parts = parse_url($url);
        $port = $parts['port'] ?? ('https' == $parts['scheme']?443:80);
        $http = new \Swoole\Coroutine\Http\Client($parts['host'], $port, 'https' == $parts['scheme']);

        
        $http->set(array_merge([
            'timeout'=>$timeout,
            'keep_alive'=>false,
            'open_ssl'=>'https'==$parts['scheme']
        ], $certs ?? []));

        $http->setMethod('POST');
        $http->setData($payload);
        
        if(null !== $headers)
            $http->setHeaders($headers);

        if(false !== $http->execute(substr($url, \strpos($url, $parts['path'])))){
            $http->close();
            $result = ['url'=>$url, 'code'=>$http->getStatusCode(),'body'=>$http->getBody(),'return_code'=>0];
        }else{
            $result = ['url'=>$url, 'code'=>0, 'return_code'=>$http->errCode];
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE)."\n";
        return $result;
    }
}

if(!function_exists('http_get'))
{
    /**ßß
     * 发起一个HTTP Post 请求.
    * @param string $url 请求资源地址.
    * @param null|array $headers HTTP协议头部信息
    * @param int $timeout 超时时间
    * @param  null|array $certs 证书 需包含2个成员 ssl_cert_file, ssl_key_file
    * @return array return_code 错误码 0 成功, code http返回状态码, url 请求的url, body 返回内容.
    */
    function http_get(string $url, ?array $headers = null, int $timeout = 30, ?array $certs = null) : array {
        $parts = parse_url($url);
        $port = $parts['port'] ?? ('https' == $parts['scheme']?443:80);
        $http = new \Swoole\Coroutine\Http\Client($parts['host'], $port, 'https' == $parts['scheme']);

        
        $http->set(array_merge([
            'timeout'=>$timeout,
            'keep_alive'=>false,
            'open_ssl'=>'https'==$parts['scheme']
        ], $certs ?? []));

        $http->setMethod('GET');
        
        if(null !== $headers)
            $http->setHeaders($headers);

        if(false !== $http->execute(substr($url, \strpos($url, $parts['path'])))){
            $http->close();
            $result = ['url'=>$url, 'code'=>$http->getStatusCode(),'body'=>$http->getBody(),'return_code'=>0];
        }else{
            $result = ['url'=>$url, 'code'=>0, 'return_code'=>$http->errCode];
        }

        return $result;
    }
}

if(!function_exists('dict'))
{
    /**
     * 数组转字典
     */
    function dict(?array $records, string $field = 'order_id', bool $toupper = false) : array{
        $ret = [];

        if(null != $records){
            if($toupper){
                foreach ($records as $value) {
                    $key = $value[$field] ?? false;
                    if(false !== $key){
                        $ret[strtoupper($key)] = $value;
                    }
                }
            }else{
                foreach ($records as $value) {
                    $key = $value[$field] ?? false;
                    if(false !== $key){
                        $ret[$key] = $value;
                    }
                }
            }
        }
        return $ret;
    }
}

if(!function_exists('uniqueid'))
{
    /**
     * 生成一个36进制表示的 UUID
     */
    function uniqueid(){
        $uuid = substr(file_get_contents('/proc/sys/kernel/random/uuid'),0, -1);
        $hex = str_replace('-','', $uuid);
        return base_convert($hex, 16, 36);
    }
}


if(!function_exists('crontab')){

    /**
     * 创建一个计划任务. 如果发生错误会抛出一个异常.
     * @param string $every 执行间隔时间.
     * @param string $at 确定执行时间.
     * @param callable $exec 回调函数
     * @param int $time 开始执行时间 默认为当前时间
     * @return sys\CrontabTask 返回任务对象.
     */
    function crontab(string $every, string $at, callable $exec, int $time = 0) :\sys\CrontabTask{
        if(0 == $time)
            $time = time();
    return new \sys\CrontabTask($time, $every, $at ,$exec);
    }
}


if(!function_exists('nonceStr')){
    function nonceStr(int $length = 6) : string{
        $chars = "0123456789";
        $len = strlen($chars) - 1;
        $ret = [];
        for ( $i = 0; $i < $length; $i++ )  {  
            $ret[] = $chars[mt_rand(0, $len)];
        } 
        return \implode('', $ret);
    }
}

if(!function_exists('groupby')){
    function groupby(?array $records, string $field = 'order_id') : array{
        $ret = [];
        if(null != $records){
            foreach ($records as $value) {
                $key = $value[$field] ?? false;
                if(false !== $key){
                    $ret[$key] = $value;
                }
            }
        }
        return $ret;
    }
}

if(!function_exists('safe_idcard')){
    function safe_idcard(string $szIDNO, $prefix = 4, $suffix= 4){
        $len = strlen($szIDNO);
        return str_pad(substr($szIDNO, 0, $prefix) , $len -  ($prefix + $suffix), '*') . substr($szIDNO, $len - ($prefix), $suffix);
    }
}

if(!function_exists('safe_name')){
    function safe_name(string $szName) {
        $prefix = $suffix = 1;
        $len = mb_strlen($szName);
        return str_pad(mb_substr($szName, 0, $prefix) , $len -  ($prefix + $suffix), '*') . mb_substr($szName, $len - ($prefix), $suffix);
    }
}