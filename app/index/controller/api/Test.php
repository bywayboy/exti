<?php
declare(strict_types=1);

namespace app\index\controller\api;

use Swoole\Http\Request;
use Swoole\Http\Response;

class Test {
    public function index(Request $req, Response $res){
        return json(['success'=>true]);
    }
}
