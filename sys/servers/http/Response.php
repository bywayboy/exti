<?php
declare(strict_types=1);
namespace sys\servers\http;

interface Response{
    public function output(\Swoole\Http\Response $resp);
}