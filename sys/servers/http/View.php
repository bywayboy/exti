<?php
declare(strict_types=1);

namespace sys\servers\http;

use stdClass;
use Swoole\Http\Response;
use Throwable;

/**
 * TODO: 模板引擎, 用于输出模板
 */
class View extends Resp
{
    protected static array $cache;

    protected string $file;
    protected array $vars;

    public function __construct(string $file, array $vars = [], string $mime = 'text/html; charset=utf-8', int $status =200)
    {
        $this->file = $file;
        $this->vars = $vars;
        $this->mime = $mime;
        $this->status = $status;
    }
    /**
     * 绑定一个变量到将要渲染的模板中
     * @access public
     * @param string $name 变量名称
     * @param mixed $var 变量值
     * @return void
     */
    public function assign(string $name, mixed $var) : void {
        $this->vars[$name] = $var;
    }

    public function output(Response $r, ?array $tplc = null) : void
    {
        extract($this->vars, EXTR_OVERWRITE);
        # 数组成员弄成变量.

        #ob_implicit_flush(false);
        ob_start();
        try{
            include $tplc['root'] . DIRECTORY_SEPARATOR .$this->file;
        }catch(Throwable $e){
            echo '<p>Error: '.$e->getMessage() . ' at file: ' .$e->getFile() . '('. $e->getLine().')</p>';
        }
        $this->content = ob_get_clean();
        parent::output($r);
    }
}
