<?php
declare(strict_types=1);


namespace sys\db;

use Exception;
use Stringable;

/**
 * 查询缓存类, 用途分为两类.
 *  1. 用于子查询构造器.
 *  2. 用于配合批量执行类来执行批量SQL语句.
 */
class SubQuery {
    protected string $_sql;
    protected array $_params;
    protected int $_sqltype; 
    
    public function __construct(string $sql, array $params, int $type)
    {
        $this->_sqltype = $type;
        $this->_sql = $sql;
        $this->_params = $params;
    }
    public function getSql() : string{
        return $this->_sql;
    }

    public function getParams():array {
        return $this->_params;
    }
    /**
     * 获取查询类型
     * @return int 0 = Db::SQL_INSERT 1 = Db::SQL_UPDATE | 2 = Db::SQL_FIND | 3 = Db::SQL_SELECT | 4 = Db::SQL_DELETE
     */
    public function gettype():int {
        return $this->_sqltype;
    }

    public function __get($name)
    {
        return $this->$name;
    }

    /**
     * 将查询结果组装成 SQL
     * 注意!! 不要将该方法生成的SQL用于数据库执行 . addslashes 会产生编码SQL注入漏洞.
     */
    public function getRealSql(): string {
        return static::buildSql($this->_sql, $this->_params);
    }

    /**
     * 工具函数, 构造SQL 用于打印！
     */
    public static function buildSql(string $sql, array $params) : string {
        $RawSql = $sql;
        $start = 0;
        foreach($params as $i=>$param){
            if($param[1] == \PDO::PARAM_STR){
                $v = '\'' . addslashes($param[0]) . '\'';
            } elseif ($param[1] == \PDO::PARAM_LOB){
                $v = \implode('' ,['x','\'', \bin2hex($param),'\'']);
            }elseif($param[1] == \PDO::PARAM_BOOL){
                $v = $param[0] ? 'TRUE' : 'FALSE';
            }else{
                $v = strval($param[0]);
            }
            $start = strpos($RawSql, '?', $start);
            if($start >=0){
                $RawSql = substr_replace($RawSql, $v, $start, 1);
                $start += strlen($v) + 1;
            }else{
                throw new Exception('[BUG] Sql 占位符和参数数目不一致.');
            }
        }
        return $RawSql;
    }
}