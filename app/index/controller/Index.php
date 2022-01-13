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
    <div style="height:80%;width:100%;">进程数:2, 运行正常</div>
    <div style="text-align:center;"><a href="https://beian.miit.gov.cn">湘ICP备2021015331号-1</a>
    </div>
</body>
</html>');
    }

    public function test(Request $r, Response $e){
        $check1 = validate([
            'username|性敏感'=>'require',
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
            'arr+arr'=>[1,2] + [3,4]
        ]);
    }
}
