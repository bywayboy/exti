<?php
declare(strict_types=1);
/**
 * 配置文件读写类
 * 作者: 龚辟愚
 * 日期: 2021-09-18
 */
namespace sys;
/**
 * 配置读写类
 * @method void set(string $name) static
 * @method mixed get(string $name, mixed $default) static
 * @method void save(string $name) static
 */
class Config {
    protected static array $config = [];

    protected static function cache(string $name, $value) :void {
        static::$config[$name] = $value;
    }

    protected static function root(string $name) {
        if(empty(self::$config[$name])){
            $file = APP_ROOT."/config/{$name}.php";

            if(\is_file($file)){
                self::cache($name, include $file);
            }else{
                self::cache($name, []);
            }
        }
    }

    /**
     * 清理配置缓存, 会强制重新从文件中读取配置.
     */
    public static function clear(?string $name = null): void {
        if(null === $name)
            static::$config = [];
        else
            unset(static::$config[$name]);
    }

    /**
     * 读取配置
     */
    public static function get(string $pname = 'app', mixed $default = null)
    {
        $parts  = explode('.', $pname); $numParts = count($parts);
        if(empty($parts))
            return $default;

        for($i =0; $i < $numParts; $i ++){
            $part = $parts[$i];
            if($i == 0){
                if(!isset(static::$config[$part])){
                    $file = APP_ROOT."/config/{$part}.php";
                    if(!is_file($file))
                        return $default;
                    static::$config[$part] = include $file;
                    if($i == $numParts -  1) return $default;
                }
                $config = static::$config[$part];
                continue;
            }
            if(!isset($config[$part])){
                return $default;
            }
            $config = $config[$part];
        }
        return $config;
    }

    /**
     * 设置配置项
     * @access public
     * @param string $name 配置项名称
     * @param mixed $value 配置值
     * @return void
     */
    public static function set(string $name, mixed $value) : void
    {
        // 1. 读取原始配置
        $parts  = explode('.', $name);
        $numPart = count($parts);

        for($i = 0; $i < $numPart; $i++){
            $part = $parts[$i];

            if($i == 0){
                if(!isset(static::$config[$part])){
                    # 如果新根节点, 需要预先读取文件, 防止全面覆盖.
                    $file = APP_ROOT."/config/{$part}.php";
                    if(is_file($file)){
                        try{
                            static::$config[$part] = include $file;
                        }catch(\Throwable $e){
                            //TODO: 抛出异常.
                        }
                    }
                }

                if(!isset(static::$config[$part])){
                    if($i == $numPart - 1){
                        static::$config[$part] = $value;
                        break;
                    }
                    static::$config[$part] = [];
                }
                $config = &static::$config[$part];
                continue;
            }

            if($i == $numPart - 1){
                $config[$part] = $value;
                break;
            }
            if(!isset($config[$part])){
                $config[$part] = [];
            }
            $config = &$config[$part]; 
        }
    }

    /**
     * 将PHP变量生成PHP代码
     */
    private static function var_export_short(mixed $var, string $indent="") : string {
        switch (gettype($var)) {
            case "string":
                return '\'' . addcslashes($var, "\\\$\"\r\n\t\v\f") . '\'';
            case "array":
                $indexed = array_keys($var) === range(0, count($var) - 1);
                $r = [];
                foreach ($var as $key => $value) {
                    $r[] = "$indent    "
                         . ($indexed ? "" : static::var_export_short($key) . " => ")
                         . static::var_export_short($value, "$indent    ");
                }
                return "[\n" . implode(",\n", $r) . "\n" . $indent . "]";
            case "boolean":
                return $var ? "true" : "false";
            default:
                return var_export($var, TRUE);
        }
    }
    /**
     * 将内存中的配置持久化存储到磁盘中.
     * @access public
     * @param string $name 配置名称
     * @return void
     */
    public static function save(string $name) :void {

        $file = APP_ROOT."/config/". $name . '.php';
        $codeStr = static::var_export_short(static::$config[$name] ?? []);
        $date = date('Y-m-d H:i:s');
        $content = "<?php\ndeclare(strict_types=1);\n\n// 请勿擅自修改!!! 数据生成时间: {$date}\n\nreturn {$codeStr};\n";
        file_put_contents($file, $content);
    }
}

