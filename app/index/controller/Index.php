<?php
declare(strict_types=1);

namespace app\index\controller;

use \Swoole\Http\Request;
use \Swoole\Http\Response;

class Index {
    public function index(Request $r, Response $e){
       return html('
<html>
<title>框架测试</title>
<body>
    <div style="height:80%;width:100%;">运行正常</div>
</body>
</html>');
    }

    public function test(Request $r, Response $e){
        $check1 = validate([
            'username|姓名'=>'require',
            'profile|资料'=>'require|array',
            'profile.avatar'=>'require'
        ])->check(['username'=>'boy'],true);

        $check2 = validate([
            'username'=>'require',
            'profile|资料'=>'require|array',
            'profile.avatar'=>'require'
        ])->check(['username'=>'boy', 'profile'=>[]]);

        return json([
            'check1'=>$check1,
            'check2'=>$check2,
        ]);
    }
}
