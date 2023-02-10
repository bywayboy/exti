<?php
declare(strict_types=1);

namespace sys\servers\http;


class Html extends Resp
{
    public function __construct(string $content, int $status = 200, string $mime = 'text/html; charset=utf-8')
    {
        $this->content = $content;
        $this->status = $status;
        $this->mime = $mime;
    }
}

