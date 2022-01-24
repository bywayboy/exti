<?php
declare(strict_types=1);
/**
 * 配置文件读写类
 * 作者: 龚辟愚
 * 日期: 2021-09-18
 */
namespace sys;

class Config{
    private static array $config = [];
    private static function cache(string $name, $value) :void {
        static::$config[$name] = $value;
    }

    
    public static function Clear(): void{
        static::$config = [];
    }

    public static function get(string $name = 'app', array $default = []) : array
    {
        $parts  = explode('.', $name);
        if(empty($parts)){
            return $default;
        }

        $name = array_shift($parts);
        if(empty(static::$config[$name])){
            $file = APP_ROOT."/config/{$name}.php";

            if(\is_file($file)){
                static::cache($name, include $file);
            }else{
                echo "Load Config Failed!!! {$file}\n";
                static::cache($name, []);
            }
        }
        
        $cfg = static::$config[$name];
        foreach($parts as $key){
            if(isset($cfg[$key])){
                $cfg = $cfg[$key];
            }else{
                $cfg = $default;
            }
        }
        return $cfg;
    }

    private static function _set($config, array $parts, $value) : array{
        $key = array_shift($parts);
        if(count($parts) > 0){
            $config[$key] = static::_set($config[ $key ] ?? [], $parts, $value);
        }else{
            $config[$key] = $value;
        }
        return $config;
    }

    public static function set(string $name, array $value = [])
    {
        // 1. 读取原始配置
        $parts  = explode('.', $name);
        if(count($parts) > 0){
            static::$config = static::_set(static::$config, $parts, $value);
        }
    }

    public static function var_export_short($var, $indent="") : string {
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
                return $var ? "TRUE" : "FALSE";
            default:
                return var_export($var, TRUE);
        }
    }
    /**
     * 
     */
    public static function store(string $name) :void {

        $file = APP_ROOT."/config/". $name . '.php';

        $codeStr = static::var_export_short(static::$config[$name] ?? []);
        $date = date('Y-m-d H:i:s');
        $content = "<?php\n// 请勿擅自修改!!! 数据生成时间: {$date}\n\nreturn {$codeStr};\n";
        file_put_contents($file, $content);
    }
}

