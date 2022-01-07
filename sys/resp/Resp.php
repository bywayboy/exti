<?php

namespace sys\resp;

interface Resp{
    public function output(\Swoole\Http\Response $resp);
}