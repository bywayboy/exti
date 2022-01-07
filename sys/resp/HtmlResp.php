<?php
declare(strict_types=1);
namespace sys\resp;

use \Swoole\Http\Response;


class HtmlResp implements Resp
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

    public function output(Response $resp)
    {
        if($this->status !== 200)
            $resp->setStatusCode($this->status);

        $resp->setHeader('Content-Type', $this->mime);

        $resp->end($this->content);
    }
}

