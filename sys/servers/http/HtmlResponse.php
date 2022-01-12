<?php
declare(strict_types=1);
namespace sys\servers\http;


class HtmlResponse implements \sys\servers\http\Response
{
    protected $content;
    protected $status;
    protected $mime;


    public function __construct(string $content, int $status = 200, string $mime = 'text/html; charset=utf-8')
    {
        $this->content = $content;
        $this->status = $status;
        $this->mime = $mime;
    }

    public function output(\Swoole\Http\Response $resp)
    {
        if($this->status !== 200)
            $resp->setStatusCode($this->status);

        $resp->setHeader('Content-Type', $this->mime);

        $resp->end($this->content);
    }
}

