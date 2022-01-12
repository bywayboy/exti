<?php
declare(strict_types=1);
namespace sys\servers\http;


class JsonResponse implements Response
{
    protected $content;
    protected $status;
    protected $mime;


    public function __construct($content, int $status = 200, string $mime = 'application/json; charset=utf-8')
    {
        $this->content = is_string($content)? $content : json_encode($content, JSON_UNESCAPED_UNICODE);
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
