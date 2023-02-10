<?php
declare(strict_types=1);

namespace sys\servers\http;

use JsonSerializable;

class Json extends Resp
{

    public function __construct($content, int $status = 200, string $mime = 'application/json; charset=utf-8')
    {
        if(is_string($content)){
            $this->content = $content;
        }elseif(is_array($content) || $content instanceof JsonSerializable){
            $this->content = json_encode($content, JSON_UNESCAPED_UNICODE);
        }
        $this->status = $status;
        $this->mime = $mime;
    }
}
