<?php
declare(strict_types=1);

namespace sys\servers\http;

use Swoole\Http\Response;

class Redirect extends Resp
{

    public function __construct(string $location, int $status = 302)
    {
        $this->content = $location;
        $this->status = $status;

    }
    public function output(Response $r, ?array $conf = null): void
    {
        $r->redirect($this->content, $this->status);
    }
}
