<?php
declare(strict_types=1);
namespace lib\weixin;

class Msg {
    protected array $conf;

    public function __construct(array $conf)
    {
        $this->conf = $conf;
    }

    public function checkSignature(array $get) :bool
    {
        if(!isset($get['timestamp']) || !isset($get['nonce']) || !isset($get['signature'])){
            $pass = false;
        }else{
            $token = $this->conf['token'];
            $arr = [ $token, $get["timestamp"], $get["nonce"]];
            sort($arr, SORT_STRING);
            $signature = sha1(implode($arr));
            $pass = $signature === $get['signature'];
        }
        return $pass;
    }

    public function decodeMessage(array $get, string $xmlString) :?array
    {
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

    /**
     * 向客户端推送文章
     */
    public static function transmitNews(array $msg, array $articles)
    {
        if (!is_array($articles)) {
            return;
        }
        $itemTpl = "<item><Title><![CDATA[%s]]></Title><Description><![CDATA[%s]]></Description><PicUrl><![CDATA[%s]]></PicUrl><Url><![CDATA[%s]]></Url></item>";
        $list = [];
        foreach ($articles as $item) {
            $list[] = sprintf($itemTpl, $item['Title'], $item['Description'], $item['PicUrl'], $item['Url']);
        }
        $xmlTpl = <<<EOD
<xml>
    <ToUserName><![CDATA[%s]]></ToUserName>
    <FromUserName><![CDATA[%s]]></FromUserName>
    <CreateTime>%s</CreateTime>
    <MsgType><![CDATA[news]]></MsgType>
    <ArticleCount>%s</ArticleCount>
    <Articles>%s</Articles>
</xml>"
EOD;
        $result = sprintf($xmlTpl,$msg['FromUserName'],$msg['ToUserName'], time(), count($list), implode($list));
        return xml($result);
    }

    /**
     * 回复文本消息
     */
    public function transmitText(array $msg, string $message)
    {
        $xmlTpl = <<<EOD
<xml>
    <ToUserName><![CDATA[%s]]></ToUserName>
    <FromUserName><![CDATA[%s]]></FromUserName>
    <CreateTime>%s</CreateTime>
    <MsgType><![CDATA[text]]></MsgType>
    <Content><![CDATA[%s]]></Content>
</xml>
EOD;
        $result = sprintf($xmlTpl,$msg['FromUserName'],$msg['ToUserName'], time(), $message);
        return xml($result);
    }

    /**
     * 转发消息给客服
     */
    public function redirect(array $msg)
    {
        $xmlText = "<xml><ToUserName><![CDATA[%s]]></ToUserName><FromUserName><![CDATA[%s]]></FromUserName><CreateTime>%s</CreateTime><MsgType><![CDATA[transfer_customer_service]]></MsgType></xml>";
        $result = sprintf($xmlText, $msg['FromUserName'], $msg['ToUserName'], time());
        return xml($result);
    }
}
