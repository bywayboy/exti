<?php
declare(strict_types=1);

namespace app\index\controller;

use \Swoole\Http\Request;
use \Swoole\Http\Response;
use sys\servers\http\View;

class Index {
    public function index(Request $r, Response $e){
        $info = [
            'memory_used'=>number_format((memory_get_usage(false) / 1024), 3,'.','').'KB',   //true:获取系统分配总的内存尺寸，包括未使用的页。false:仅仅报告实际使用的内存量。
            'memory_peak'=>number_format((memory_get_peak_usage(false) / 1024), 3,'.','').'KB',            //内存峰值
            'framework_ver'=>swoole_version(),          # swoole 框架版本
            'php_ver'=>PHP_VERSION,                     # PHP版本
            'os'=>PHP_OS,                               # 操作系统
            'cpus'=>swoole_cpu_num(),                   # CPU核心数目
            'start_time'=>date('Y-m-d H:i:s', $this->start_time),
        ];
       return json($info);
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

    public function noret(Request $request, Response $response)  {
        echo "no ret called\n";
    }

    public function tpl(Request $request, Response $response) {
        # 测试原生模板支持
        return new View('index/tpl.php', [
            'title'=>'页面标题',
            'vars'=>[
                'username'=>'bywayboy',
                'age'=>'30',
                'message'=>'模板渲染测试.'
            ]
        ]);
    }
}
