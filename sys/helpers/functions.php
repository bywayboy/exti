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

if(!function_exists('array_compare')){
    /**
     * 递归比较两个 array 是否相同.
     * @return bool 相同返回 true 不同返回 false
     */
    function array_compare(array|object $ov, array|object $nv):bool
    {
        # echo "compare ".json_encode($ov) . "<==>" . json_encode($nv) . "\n";

        # 列表比较
        if(is_array($ov) && is_array($nv) && array_is_list($ov) && array_is_list($nv)){
            if(count($ov) === count($nv)){
                foreach($ov as $i=>$v){
                    if(value_compare($v, $nv[$i]))
                        continue;
                    return false;
                }
                return true;
            }
            return false;
        }

        # 数组比较
        $aov = (array)$ov;$anv = (array)$nv;
        $ukeys = array_unique(array_merge(array_keys($aov),array_keys($anv)));
        foreach($ukeys as $key){
            if(array_key_exists($key, $aov) && array_key_exists($key, $anv)){
                if(!value_compare($aov[$key], $anv[$key]))
                    return false;
            }else{
                return false;
            }
        }
        return true;
    }
}

if(!function_exists('value_compare')){
    /**
     * 比较2个变量 规则如下:
     *  1. 浮点, 误差 < 0.00000000001 视作相等.
     *  2. 文本、整数 值相等, 视作相等.
     *  3. 列表, 成员数相同,、成员值相同、顺序相同, 视作相等.
     *  4. 数组, 键=>值 完全同, 视作相等.
     *  5. null === null, 视作相等.
     * @return bool 相同返回 true 不同返回 false
     */
    function value_compare(mixed $o, mixed $n) : bool {
        if((is_array($o) || is_object($o)) && (is_array($n) || is_object($n))){
            return array_compare($o, $n);
        }
        if($o === $n) return true;
        if(is_numeric($o) && is_numeric($n)){
            if(abs($o - $n) < 0.00000000001)
                return true;
        }
        return false;
    }
}

if(!function_exists('fields_compare'))
{
    function fields_compare(array $new, array $old, bool $setNull)
    {
        $newvalue = null;
        $oldvalue = null;
        # 比较值

        $propNamesU = array_keys($old);
        foreach($propNamesU as $prop){
            if(array_key_exists($prop, $new)){
                if(value_compare($new[$prop], $old[$prop])){
                    continue;
                }
                $oldvalue[$prop] = $old[$prop];
                $newvalue[$prop] = $old[$prop] = $new[$prop];
            }else if(true === $setNull){
                $oldvalue[$prop] = $old[$prop];
                $newvalue[$prop] = $old[$prop] = null;
            }
        }

        return $newvalue;
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
    function safe_idcard(string $szIDNO, $prefix = 1, $suffix= 6){
        $len = strlen($szIDNO);
        return substr($szIDNO, 0, $prefix). str_repeat('*', $len - ($prefix + $suffix)) . substr($szIDNO, $len - ($suffix), $suffix);
    }
}

if(!function_exists('safe_name')){
    function safe_name(string $szName, $prefix = 1, $suffix= 0) {
        $len = mb_strlen($szName);
        if($suffix > 0){
            return mb_substr($szName, 0, $prefix). str_repeat('*', $len - ($prefix + $suffix)) .mb_substr($szName, $len - ($prefix), $suffix);
        }
        return mb_substr($szName, 0, $prefix) . str_repeat('*', $len - $prefix);
    }
}

if(!function_exists('getos')){
    function getos(array $headers):string{
        $os_platform  = "Unknown OS";
        if(isset($headers['x-os'])) return $headers['x-os'];
		$os_array     = [
			  '/windows nt 10/i'      =>  'Windows 10',
			  '/windows nt 6.3/i'     =>  'Windows 8.1',
			  '/windows nt 6.2/i'     =>  'Windows 8',
			  '/windows nt 6.1/i'     =>  'Windows 7',
			  '/windows nt 6.0/i'     =>  'Windows Vista',
			  '/windows nt 5.2/i'     =>  'Windows Server 2003/XP x64',
			  '/windows nt 5.1/i'     =>  'Windows XP',
			  '/windows xp/i'         =>  'Windows XP',
			  '/windows nt 5.0/i'     =>  'Windows 2000',
			  '/macintosh|mac os x/i' =>  'Mac OS X',
			  '/mac_powerpc/i'        =>  'Mac OS 9',
			  '/linux/i'              =>  'Linux',
			  '/ubuntu/i'             =>  'Ubuntu',
			  '/iphone/i'             =>  'iPhone',
			  '/ipod/i'               =>  'iPod',
			  '/ipad/i'               =>  'iPad',
			  '/android/i'            =>  'Android',
			  '/blackberry/i'         =>  'BlackBerry',
			  '/webos/i'              =>  'Mobile'
		];

		foreach ($os_array as $regex => $value)
			if (preg_match($regex, $value))
				$os_platform = $value;

		return $os_platform;
    }
}


if(!function_exists('formatDuration'))
{
    /**
     * 将秒数格式化为包含天数、小时数、分钟数和秒数的字符串，用于表示时间长度。
     *
     * @param int $seconds 要格式化的秒数，可以为负数表示之前的时间。
     * @param array $directions 一个包含两个字符串的数组，分别用于表示时间在当前时间之前和之后的文本后缀，默认为 ['之前', '以后']。
     * @return string 格式化后的时间字符串。
     */
    function formatDuration(int $seconds, array $directions = ['之前', '以后']) : string {
        // 判断时间是否在当前时间之前
        $isBefore = $seconds < 0;
    
        // 取绝对值，方便计算
        $seconds = abs($seconds);

        // 计算天数
        $days = floor($seconds / 86400);
        $seconds %= 86400;

        // 计算小时数
        $hours = floor($seconds / 3600);
        $seconds %= 3600;

        // 计算分钟数
        $minutes = floor($seconds / 60);
        $seconds %= 60;

        // 组装时间字符串，包括天、小时、分钟和秒
        $parts = [
            $days > 0 ? "{$days}天" : '',
            $days > 0 || $hours > 0 ? "{$hours}小时" : '',
            $days > 0 || $hours > 0 || $minutes ? "{$minutes}分钟" : '',
            "{$seconds}秒",
            $isBefore ? $directions[0] : $directions[1]
        ];

        // 将各个时间部分连接成最终的时间字符串并返回
        return implode('', $parts);
    }
}