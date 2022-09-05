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
        
        foreach($dbs as $confkey=>$dbconf){
            $db = new \sys\Db('db.'.$confkey);
            $dbname = $dbconf['dbname'];

            $table_names = \sys\Config::get("tables.{$dbname}.table_names");
            if(empty($table_names))
                continue;

            $records = $db->table('information_schema.COLUMNS')
                        ->where([['TABLE_SCHEMA','=', $dbname], ['TABLE_NAME','IN', $table_names]])
                        ->field(['TABLE_NAME', 'COLUMN_NAME', 'DATA_TYPE', 'COLUMN_COMMENT'])
                        ->order('TABLE_NAME ASC')
                        ->select();
            static::lazyAppendFieldsCache($dbname, $records);
            $db = null;
        }

        \sys\Config::save('tables_gen');
    }

    /**
     * 缓存制定表结构.
     */
    public static function lazyAppendFieldsCache(string $dbname, array $fields)
    {
        $types_map = static::$types_map;
        $tables = \sys\Config::get("tables.{$dbname}.structs");
        $result = \sys\Config::get("tables_gen.{$dbname}", []);
        $changed = false;
        foreach($fields ?? [] as $item){
            $tbname = $item['TABLE_NAME'];
            $colname = $item['COLUMN_NAME'];
            $nv  = $tables[$tbname][$colname] ?? $types_map[ $item['DATA_TYPE'] ];
            # echo "field: {$tbname} {$colname}  = {$nv}\n";
            if($nv !== ($result[$tbname][$colname] ?? null)){
                $result[$tbname][$colname] = $nv;
                $changed = true;
            }
        }
        # 如果发生改变 就保存到内存缓存中.
        if($changed){
            \sys\Config::set('tables_gen.'.$dbname, $result);
        }
    }
}