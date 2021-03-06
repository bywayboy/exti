<?php
declare(strict_types=1);

namespace sys;

use Exception;
use PDOException;
use Swoole\Database\PDOPool;
use Swoole\Database\PDOConfig;
use Swoole\Database\PDOProxy;
use sys\db\SubQuery;

/**
 * 数据库连接池驱动第二版. 主要具有以下特性:
 *  1. 除事务模式, 数据库类不再持有连接对象.
 *  2. 事务提交或者回滚后 连接对象会被立即归还给连接池.
 *  3. 内置一个死锁检测机制. 其目的是防止同一个协程去持有多个数据库连接.
 *  4. 后续该对象将加入限制, 不允许跨协程使用. 
 */

class Db{

    # SQL 类型
    const SQL_INSERT = 0;
    const SQL_UPDATE = 1;
    const SQL_FIND   = 2;
    const SQL_SELECT = 3;
    const SQL_DELETE = 4;

    protected static $pool = [];
    protected $name;
    protected $conn = null;     # 只有在事务模式下, 才会去持有一个PDO连接, 直到对象销毁.
    protected int $_trans_level = 0;
    protected $errStr = '';
    protected $_tableGenPfx = null;

    protected static $cidMark = [];         # 死锁检测机制

    # 执行结果保存
    protected $_result = null;
    protected int $_insid = 0;

    # 数据库是否断开连接判断标识
    private static $connBreakDict = [
        'MySQL server has gone away'=>true,
        'Lost connection to MySQL server'=>true, 
        'Error while sending QUERY packet'=>true
    ];

    public function __construct(string $connection = 'db.default') {
        $this->name = $connection;

        $conf = Config::get($this->name);
        $this->_tableGenPfx = "tables_gen.{$conf['dbname']}.";
        if(empty(self::$pool[$this->name])) {
            static::$pool[$this->name] = new PDOPool((new PDOConfig)
                ->withHost($conf['dbhost'])
                ->withPort($conf['dbport'])
                #  ->withUnixSocket('/tmp/mysql.sock')
                ->withDbName($conf['dbname'])
                ->withCharset($conf['charset'])
                ->withUsername($conf['dbuser'])
                ->withPassword($conf['dbpass'])
                ->withOptions([
                    \PDO::ATTR_DEFAULT_FETCH_MODE =>\PDO::FETCH_ASSOC,  # 返回 k->v 数组
                    \PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,        # 异常模式
                    \PDO::ATTR_AUTOCOMMIT=>1,                           # 默认开启自动提交
                    //\PDO::ATTR_PERSISTENT=>true,                        # 定义为持久连接(在连接池里面不需要)
                ])
                ,$conf['size'] ?? 16 # 默认创建16个数据库连接
            );
        }
    }

    public function __destruct()
    {
        if(null !== $this->conn){
            $this->putConn($this->conn);
            $this->conn = null;
        }
    }

    private static function CheckDeadLock(int $cid){
        if($cid < 0) return;
        if(static::$cidMark[$cid] ?? false){
            throw new \Exception("监测到可能的死锁! {$cid}, 同一个协程在同一时刻只允许持有一个数据库连接.");
        }
        //if($id = \Swoole\Coroutine::getPcid($cid))
        //    static::checkDeadLock($id);
    }
    /**
     * @return PDOProxy
     */
    protected function getConn() : PDOProxy {
        if($this->conn !== null)
            return $this->conn;
        if(false !== ($this->cid = \Swoole\Coroutine::getCid())){
            static::CheckDeadLock($this->cid);
        }
        return static::$pool[$this->name]->get();
    }

    /**
     * @param $conn null|PDOProxy
     */
    protected function putConn(?PDOProxy $conn) {
        if($this->_trans_level > 0) {
            Log::write('错误! 您有忘记提交的事务,该次数据库操作将被丢弃!!!!', 'Db','ERROR');
            throw new Exception('错误! 您有忘记提交的事务, 该次数据库操作将被丢弃!!!!', 0);
        }
        static::$pool[$this->name]->put($conn);
        # 死锁检测标记删除
        if(false !== $this->cid){
            unset(static::$cidMark[$this->cid]);
        }
    }

    # 判断是否为连接丢失异常.
    static private function isbreak(string $str) : bool {
        return static::$connBreakDict[$str] ?? false;
    }

    public function startTrans() {
        $this->_trans_level ++;
        if($this->_trans_level == 1){
            do{
                try{
                    $this->conn = $this->getConn();
                    $this->conn->setAttribute(\PDO::ATTR_AUTOCOMMIT,0);     #关闭自动提交
                    $this->conn->beginTransaction();                        #开启事务
                    return;
                }catch(PDOException $e){
                    if($this->isbreak($e->errorInfo[2])){
                        $this->_trans_level = 0;
                        $this->putConn(null);
                    }
                    Log::write('发生异常: ' . $e->getMessage().'trace:' . $e->getTraceAsString(), __CLASS__, 'ERROR');
                    Log::flush();
                    throw $e;
                }
            }while(true);
        }
    }

    public function rollback() {
        if($this->_trans_level > 0){
            $this->_trans_level --;
            if(0 == $this->_trans_level){
                try{
                    $this->conn->rollback();                                # 回滚
                    $this->conn->setAttribute(\PDO::ATTR_AUTOCOMMIT, 1);    # 开启自动提交
                    $this->putConn($this->conn);                            # 交还连接
                }catch(PDOException $e){
                    if($this->isbreak($e->errorInfo[2])){
                        $this->putConn(null);
                    }else{
                        $this->putConn($this->conn);
                    }
                    $this->conn = null;
                    Log::write('发生异常: ' . $e->getMessage().'trace:' . $e->getTraceAsString(), __CLASS__, 'ERROR');
                    Log::flush();
                    throw $e;
                }
            }
        }
    }

    public function commit() : bool {
        if($this->_trans_level > 0){
            $this->_trans_level -- ;
            if(0 == $this->_trans_level){
                try{
                    $this->conn->commit();                                  # 回滚
                    $this->conn->setAttribute(\PDO::ATTR_AUTOCOMMIT, 1);    # 开启自动提交
                    $this->putConn($this->conn);                            # 交还连接
                    $this->conn = null;
                    return true;
                }catch(PDOException $e){
                    if($this->isbreak($e->errorInfo[2])){
                        $this->putConn(null);
                    }else{
                        $this->putConn($this->conn);
                    }
                    $this->conn = null;
                    Log::write('发生异常: ' . $e->getMessage().'trace:' . $e->getTraceAsString(), __CLASS__, 'ERROR');
                    Log::flush();
                    throw $e;
                }
            }
        }
        return false;
    }

    public function table(string $tables) : \sys\SqlBuilder
    {
        $tableArr = array_map(function($v){return trim($v," \t\n\r");}, explode(',', $tables));
        $prefix = $this->_tableGenPfx;

        $table = $tableArr[0];
        foreach($tableArr as $tb){
            $typeArr = Config::get($prefix . $tb);
            if(empty($typeArr)){
                Log::write("表: {$prefix}{$tb} 没有找到类型定义.".json_encode($typeArr), 'SqlBuilder', 'ERROR');
                continue;
            }
            $type = array_merge($typeArr, $type ?? []);
        }
        return new \sys\SqlBuilder($this, $table, $type ?? []);
    }

    /**
     * 执行一条SQL语句. 返回受影响的记录行数, 失败抛出异常.
     * 针对查询语句 有数据返回 获取到的记录数, 无数据返回 0.
     */
    public function execute(string $sql, array $params, int $operation) : int {
        if(Config::get('app.log_sql')){
            $logSql = SubQuery::buildSql($sql, $params);
            Log::write($sql . json_encode($params, JSON_PRETTY_PRINT), 'DBX', 'SQL');
            Log::write($logSql, 'DBX', 'SQL');
        }

        $conn = $this->getConn();
        $affert_rows = 0;
        do{
            try{
                $stmt = $conn->prepare($sql);
                foreach($params ?? [] as $i=>$value){
                    $stmt->bindValue(1 + $i, $value[0], $value[1]);
                }
                $stmt->execute(); # 成功 TRUE 失败返回 FALSE
                switch($operation){
                case static::SQL_INSERT:
                    $this->_insid = intval($conn->lastInsertId());
                case static::SQL_UPDATE:
                case static::SQL_DELETE:
                    $affert_rows = $stmt->rowCount();
                    break;
                case static::SQL_SELECT:
                    $this->_result = $stmt->fetchAll();
                    $affert_rows = $this->_result == null ? 0 : count($this->_result);
                    break;
                case static::SQL_FIND:
                    $this->_result = $stmt->fetch();
                    $affert_rows = $this->_result == null ? 0 : 1;
                    $stmt->closeCursor();
                    break;
                default:
                    break;
                }
                if(0 == $this->_trans_level)
                    $this->putConn($conn);
                $conn = null;
                return $affert_rows;
            }catch(\PDOException $e){
                //TODO: 连接断开判断.
                if(static::isbreak($e->errorInfo[2])){
                    $this->putConn(null);
                }else{
                    $this->putConn($conn);
                }
                throw $e; //继续抛出异常.
            }
        }while(true);
    }

    # 针对查询类语句 获取查询结果.
    public function result(array $map, int $stype) : ?array {
        if($stype == static::SQL_FIND){
            if(null == $this->_result) return null;
            foreach($this->_result as $key=>$val){
                switch($map[$key] ?? 'string'){
                case 'string':
                    $xrow[$key] = $val;break;
                case 'boolean';
                    $xrow[$key] = $val?true:false;break;
                case 'integer':
                    $xrow[$key] = intval($val);break;
                case 'double':
                    $xrow[$key] = floatval($val);break;
                case 'object':
                    $xrow[$key] = json_decode($val, false);break;
                case 'json':
                case 'array':
                    $xrow[$key] = json_decode($val, true);break;
                default:
                    $xrow[$key] = $val;break;
                }
            }
            return $xrow;
        }else{
            if(null === $this->_result) return null;
            foreach($this->_result as &$row){
                foreach($row as $key=>$val){
                    switch($map[$key] ?? 'string'){
                    case 'string':
                        $row[$key] = $val;break;
                    case 'boolean';
                        $row[$key] = $val?true:false;break;
                    case 'integer':
                        $row[$key] = intval($val);break;
                    case 'double':
                        $row[$key] = floatval($val);break;
                    case 'object':
                        $xrow[$key] = json_decode($val, false);break;
                    case 'json':
                    case 'array':
                        $row[$key] = json_decode($val, true);break;
                    default:
                        $row[$key] = $val;break;
                    }
                }
            }
            return $this->_result;
        }
    }

    # 获取最后插入的记录的ID
    public function lastInsertId() : ?int {
        return $this->_insid;
    }

    /** 执行批量查询 */
    public function batch_execute(array $sqls) : int {
        $params = [];$sqlArr = [];
        foreach($sqls as $sql){
            if($sql instanceof SubQuery){
                $params = [...$params, ...$sql->getParams()];
                $sqlArr[] = $sql->getSql();
            }else{
                $sqlArr[] = $sql;
            }
        }
        $allSql = implode(';', $sqlArr);

        # 该 SQL 用于打印日志.
        if(Config::get('app.log_sql')){
            $logSql = SubQuery::buildSql($allSql, $params);
            Log::write($logSql, 'DBX', 'SQL');
        }

        $conn = $this->getConn();
        try{
            $stmt = $conn->prepare($allSql);
            foreach($params ?? [] as $i=>$value){
                $stmt->bindValue(1 + $i, $value[0], $value[1]);
            }
            $stmt->execute();
            $reti = $stmt->rowCount();
            if(0 == $this->_trans_level)
                $this->putConn($conn);
            $conn = null;
            return $reti;
        }catch(PDOException $e){
            if(static::isbreak($e->errorInfo[2])) {
                $this->putConn(null);
            } else {
                $this->putConn($conn);
            }
            throw $e; //继续抛出异常.
        }
    }
}