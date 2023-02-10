<?php
declare(strict_types=1);

namespace sys\servers\http;


class Text extends Resp
{
    public function __construct(string $content, int $status = 200, string $mime = 'text/plain; charset=utf-8')
    {
        $this->content = $content;
        $this->status = $status;
        $this->mime = $mime;
    }
}

