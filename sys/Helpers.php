<?php
declare(strict_types=1);

/**
 * 框架内使用的助手函数
 */
namespace sys;

class Helpers
{
    protected static $types_map = [
        //数值类
        'tinyint'=>'integer',
        'smallint'=>'integer',
        'mediumint'=>'integer',
        'int'=>'integer', 
        'integer'=>'integer',
        'bigint'=>'integer',            
        'bit'=>'boolean',
        //浮点类
        'float'=>'double',
        'double'=>'double',
        'decimal'=>'double',

        //数据类 文本和二进制
        'char'=>'string',
        'varchar'=>'string',

        //当二进制处理
        'binary'=>'binary',
        'varbinary'=>'binary',
        'tinyblob'=>'binary',
        'blob'=>'binary',
        'mediumblob'=>'binary',
        'longblob'=>'binary',

        'tinytext'=>'string',
        'text'=>'string',
        'mediumtext'=>'string',
        'longtext'=>'string',

        'enum'=>'string',
        'set'=>'string',
        
        'date'=>'string',
        'time'=>'string',
        'year'=>'string',
        'datetime'=>'string',
        'timestamp'=>'string'
    ];

    /**
     * 设置进程运行角色
     * @param string $suser 例如 php:www  www组 php用户
     */
    public static function setUser(string $suser) :void {
        $info = explode(':', $suser);
        $user = $info[0];
        if (empty($user)) {
            throw new \Exception('user who run the process is null');
        }
        $group = $info[1] ?? $user;
        if (function_exists('posix_getgrnam') && function_exists('posix_setgid') && (!!$ginfo = posix_getgrnam($group))) {
            posix_setgid($ginfo['gid']);
        }
        if (function_exists('posix_getpwnam') && function_exists('posix_setuid') && (!!$uinfo = posix_getpwnam($user))) {
            posix_setuid($uinfo['uid']);
        }
    }

    /**
     * 该函数在系统启动第一时间执行, 用于生成字段类型映射配置
     * 1. 枚举 config/db.php 的数据库连接配置
     * 2. 根据 config/tables.php 配置生成字段类型映射表
     * 3. 字段类型映射表保存在 config/tables_gen.php 文件中
     */
    public static function CreateDataBaseStructCache():void
    {
        $dbs = \sys\Config::get('db');
        $changed = false;

        $generated = [];
        foreach($dbs as $confkey=>$dbconf){
            $db = new \sys\Db('db.'.$confkey);
            $dbname = $dbconf['dbname'];
            if(isset($generated[$dbname]))
                continue;
            $generated[$dbname] = true;

            $table_names = \sys\Config::get("tables.{$dbname}.table_names");
            if(empty($table_names))
                continue;
            
            $pads = str_repeat('?,', count($table_names)-1).'?';
            if(0 < $db->execute(
                "SELECT `TABLE_NAME`, `COLUMN_NAME`, `DATA_TYPE`, `COLUMN_COMMENT` FROM information_schema.COLUMNS WHERE TABLE_SCHEMA= ? AND TABLE_NAME IN($pads)",
                [
                    [$dbname,\PDO::PARAM_STR], 
                    ...array_map(function($tbname){return [$tbname, \PDO::PARAM_STR];}, $table_names)
                ], 
                Db::SQL_SELECT
            )){
                $records = $db->result(
                    [
                        ['TABLE_NAME',\PDO::PARAM_STR], 
                        ['COLUMN_NAME', \PDO::PARAM_STR], 
                        ['DATA_TYPE', \PDO::PARAM_STR], 
                        ['COLUMN_COMMENT', \PDO::PARAM_STR]
                    ],
                    \sys\Db::SQL_SELECT
                );
                echo "build_cache: {$dbname} \n";
                if(true === static::lazyAppendFieldsCache($dbname, $records)){
                    $changed = true;
                }
            }
            $db = null;
        }

        # echo "================= ". ($changed === true ? 'true' : 'false')."\n";
        if($changed){
            \sys\Config::save('tables_gen');
        }
    }

    /**
     * 缓存制定表结构.
     */
    public static function lazyAppendFieldsCache(string $dbname, array $fields) : bool
    {
        $types_map = static::$types_map;
        $tables = \sys\Config::get("tables.{$dbname}.structs");
        $result = [];
        $changed = false;

        foreach($fields ?? [] as $item){
            $tbname = $item['TABLE_NAME'];
            $colname = $item['COLUMN_NAME'];
            $nv  = $tables[$tbname][$colname] ?? $types_map[ $item['DATA_TYPE'] ];
            if(!isset($result[$tbname][$colname]) || $nv !== ($result[$tbname][$colname])){
                # echo "update field Info {$colname} ".(isset($result[$tbname][$colname])? $result[$tbname][$colname] : 'null')." ==> {$nv}\n";
                $result[$tbname][$colname] = $nv;
                $changed = true;
            }
        }

        // 合并虚拟字段
        if(is_array($tables)){
            foreach($tables as $tbname=>$fields){
                foreach($fields as $colname=>$nv) {
                    if(!isset($result[$tbname][$colname])) {
                        $result[$tbname][$colname] = $nv;
                        $changed = true;
                    }
                }
            }
        }

        # 如果发生改变 就保存到内存缓存中.
        if($changed){
            foreach($result as $tbname=>$fields){
                \sys\Config::set("tables_gen.{$dbname}.{$tbname}", $fields);
            }
            \sys\Config::save('tables_gen');
        }
        return $changed;
    }

    public static function rand($length=6){
        $chars = "5869341270";
        $len = strlen($chars);
		$ret = [];
		for ( $i = 0; $i < $length; $i++ )  {  
			$ret[] = $chars[mt_rand(0, strlen($chars)-1)];
		} 
		return \implode('', $ret);
    }


    
}