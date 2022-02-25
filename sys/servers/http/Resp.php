<?php
declare(strict_types=1);
namespace sys\servers\http;

abstract class Resp {
    protected string $content;
    protected int $status;
    protected string $mime;


    public function output(\Swoole\Http\Response $r, ?array $conf = null) : void
    {
        if($this->status !== 200)
            $r->setStatusCode($this->status);

        $r->setHeader('Content-Type', $this->mime);

        $r->end($this->content);
    }
}