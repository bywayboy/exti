<?php
declare(strict_types=1);
namespace lib\weixin;

use Exception;
use stdClass;
use Swoole\Coroutine;
use Swoole\Coroutine\Barrier;
use Swoole\Timer;
use sys\Config;
use sys\servers\http\Text;

class Woa {

    protected static array $woa = [];

    public function __construct(string $name)
    {
        $config = Config::get('woa.'.$name, null);
        if(null === $config) {
            throw new Exception('无效的配置公众号配置名, 未找到公众号配置.');
        }

        if(!isset(static::$woa[$name])){
            # 设置屏障.
            static::$woa[$name] = true;

            if($token  = static::getToken($name, $config)){
                # 过期之前一分钟 刷新 access_token
                $token->tick_id = Timer::tick(($token->expires_in - 60) * 1000, function(string $name, array $config){
                    static::getToken($name, $config);
                }, $name, $config);
                foreach($config as $key=>$value){
                    $token->$key = $value;
                }
                $this->token = $token;
                return;
            }
            unset(static::$woa[$name]);
        }

        while(true === static::$woa[$name]){
            Coroutine::sleep(1.0);
        }
        $this->token = static::$woa[$name];
    }

    public function getToken(string $name, array $woa) : ?stdClass {
        $appid = $woa['appid'];
        $retu = http_get("https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid={$appid}&secret={$woa['secret']}");
        if(200 == $retu['code']){
            $token = json_decode($retu['body']);
            if(property_exists($token,'access_token')){
                Coroutine::sleep(3);
                static::$woa[$name] = $token;
                return $token;
            }
            throw new Exception('获取AccessToken失败: '.json_encode($token));
        }
        throw new Exception('获取AccessToken失败, 网络错误.');
        return null;
    }

    /**
     * 核验请求参数
     */
    public function checkSignature(array $get) :mixed {
        if(!isset($get['timestamp']) || !isset($get['nonce']) || !isset($get['signature'])){
            $pass = false;
        }else{
            $arr = [ $this->token->token, $get["timestamp"], $get["nonce"] ];
            sort($arr, SORT_STRING);
            $pass = sha1(implode($arr)) === $get['signature'];
        }
        
        if(isset($get['echostr'])){
            return $pass ? text($get['echostr']) : text('bad config');
        }
        return $pass;
    }

    /**
     * 将推送的消息解析成数组.
     * @param array $get GET参数
     * @param string $xmlString POST 过来的XML文本
     * @return null|array 成功返回 array 失败返回 null;
     */
    public function decodeMessage(array $get, string $xmlString) :?array{
        if(!$this->checkSignature($get)){
            return null;
        }

        if($dom = simplexml_load_string($xmlString)){
            return static::xml2array($dom);
        }
        return null;
    }
    

    private static function xml2array(\SimpleXMLElement $xml) : array {
        $ret = [];
        foreach($xml as $key=>$value){
             $nodes = $value->children();
            if(!empty($nodes)){
                $ret[$key] = static::xml2array($value);
            }else{
                $ret[$key] = $value->__toString();
            }
        }
        return $ret;
    }
}