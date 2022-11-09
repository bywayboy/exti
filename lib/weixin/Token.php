<?php
declare(strict_types = 1);
namespace lib\weixin;

use Swoole\Coroutine;
use Swoole\Timer;
use sys\exception\MemcachedException;
use sys\Log;

class Token {

    protected static array $tokens  = [];
    protected static array $tickets = [];

    public function __construct(string $gh, array $woa)
    {
        $cached = new \sys\Memcached('192.168.1.252', 11211, 6);

        if($woa['token']){
            $this->startTokenTimer($cached, $woa);
        }

        if($woa['jsapi']){
            $this->startJsApiTicketTimer($cached, $woa, 'jsapi');
        }
    }

    public static function getAccessToken(string $appid) {
        $token = static::$tokens[$appid] ?? null;
        if(null == $token || $token['expire'] < time()){
            $cached = new \sys\Memcached('192.168.1.252');
            $key = "aoe.token.{$appid}";
            static::$tokens[$appid] = $token = $cached->get($key);
        }
        return $token;
    }

    public static function getJsApiTicket(string $appid, string $type) {
        $key = "woa.ticket.{$appid}.{$type}";
        $ticket = static::$tickets[$key] ?? null;
        if(null == $ticket || $ticket['expire'] < time()){
            $cached = new \sys\Memcached('192.168.1.252');
            static::$tickets[$key] = $ticket = $cached->get($key);
        }
        return $ticket;
    }

    public static function createSignPackage(string $appid, string $url){
        $ticket = static::getJsApiTicket($appid, 'jsapi');
        $Jsticket = $ticket['ticket'];
        $nonceStr = nonceStr(12);
        $timestamp = time();
        $string =  "jsapi_ticket={$Jsticket}&noncestr={$nonceStr}&timestamp={$timestamp}&url=$url";
        return [
            'url'=>$url,
            'string'=>$string,
            'Jsticket'=>$Jsticket,
            'nonceStr'=>$nonceStr,
            'timestamp'=>$timestamp,
            'signature'=>sha1($string),
            'appId'=>$appid,
            'debug'=>false
        ];
    }

    protected function startTokenTimer(\sys\Memcached $cached, array $woa){
        $key = "aoe.token.{$woa['appid']}";

        $token = $cached->get($key);
        if(!is_array($token)){
            if($token = static::requestAccessToken($woa)){
                $cached->set($key, $token, $token['expires_in'] - 60);
            }
        }
        
        $after_ms = max(1000 * ($token['expire']-time() - 120), 100);
        $tick_ms = ($token['expires_in'] - 120) * 1000;
        echo "首次刷新access_token: {$after_ms}后, 之后间隔: {$tick_ms}\n";
        Timer::after($after_ms, function() use($cached, $key, $woa, $tick_ms) {
            static::execRequestToken($cached, $key, $woa);
            Timer::tick($tick_ms, function() use($cached, $key, $woa) {
                static::execRequestToken($cached, $key, $woa);
            });
        });
    }

    protected function startJsApiTicketTimer(\sys\Memcached $cached, array $woa, string $type){
        $key = "woa.ticket.{$woa['appid']}.{$type}";
        $token_key = "aoe.token.{$woa['appid']}";

        $ticket = $cached->get($key);
        if(!is_array($ticket)){
            $token = $cached->get($token_key);
            if($ticket = static::requestJsApiTicket($token['access_token'], $type)){
                $cached->set($key, $ticket, $ticket['expires_in'] - 60);
            }
        }
        $after_ms = max(1000 * ($ticket['expire']-time()-120), 100);
        $tick_ms = ($ticket['expires_in'] - 120) * 1000;

        #echo json_encode($ticket,JSON_UNESCAPED_UNICODE)."\n";
        echo "首次刷新jsapi_ticket: {$after_ms}后, 之后间隔: {$tick_ms}\n";
        Timer::after($after_ms, function() use($cached, $key, $type, $woa, $tick_ms) {
            static::execRequestJsapiTicket($cached, $key, $type, $woa);
            Timer::tick($tick_ms, function() use($cached, $key, $type, $woa) {
                static::execRequestJsapiTicket($cached, $key, $type, $woa);
            });
        });
    }

    protected static function execRequestToken(\sys\Memcached $cached, string $key, array $woa){
        while(true){
            if($token = static::requestAccessToken($woa)){
                while(true){
                    try{
                        $cached->set($key, $token, $token['expires_in'] - 60);
                        break;
                    }catch(MemcachedException $e){
                        Log::write($e->getMessage(), 'Weixin', 'ERROR');
                        Coroutine::sleep(5.0);
                    }
                }
                break;
            }
            Coroutine::sleep(5.0);
        }
    }

    protected static function execRequestJsapiTicket(\sys\Memcached $cached, string $key, string $type, array $woa){
        $token_key = "aoe.token.{$woa['appid']}";
        while(true){
            while(true){
                try{
                    $token = $cached->get($token_key);
                    break;
                }catch(MemcachedException $e){
                    Log::write($e->getMessage(), 'Weixin', 'ERROR');
                    Coroutine::sleep(5.0);
                }
            }

            if($ticket = static::requestJsApiTicket($token['access_token'], $type)){
                while(true){
                    try{
                        $cached->set($key, $ticket, $ticket['expires_in'] - 60);
                        break;
                    }catch(MemcachedException $e){
                        Log::write($e->getMessage(), 'Weixin', 'ERROR');
                        Coroutine::sleep(5.0);
                    }
                }
                break;
            }
            Coroutine::sleep(5.0);
        }
    }
    protected static function requestAccessToken(array $woa) :?array
    {
        $retu = http_get("https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid={$woa['appid']}&secret={$woa['secret']}");
        if($retu['code'] === 200){
            $data = json_decode($retu['body'], true);
            if(isset($data['access_token']))
            {
                $data['expire'] = time() + $data['expires_in'] - 60;
                echo "获取access_token:".json_encode($data,JSON_UNESCAPED_UNICODE)."\n";
                return $data;
            }
        }
        return null;
    }

    protected static function requestJsApiTicket(string $access_token, string $type) :?array
    {
        $retu = http_get("https://api.weixin.qq.com/cgi-bin/ticket/getticket?access_token={$access_token}&type={$type}");
        if($retu['code'] === 200){
            $data = json_decode($retu['body'], true);
            if(isset($data['ticket'])){
                $data['expire'] = time() + $data['expires_in'] - 60;
                echo "获取JsApiTicket".json_encode($data,JSON_UNESCAPED_UNICODE)."\n";
                return $data;
            }
        }
        return null;
    }

}
