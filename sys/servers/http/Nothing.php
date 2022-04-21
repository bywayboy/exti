<?php
declare(strict_types=1);
namespace sys\servers\http;

class Nothing extends Resp
{

    public function __construct(){

    }
    public function output(\Swoole\Http\Response $r, ?array $conf = null) : void
    {}
}