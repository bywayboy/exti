<?php
declare(strict_types=1);

namespace sys;

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
    const SQL_INSERT  = 0;
    const SQL_UPDATE  = 1;
    const SQL_FIND    = 2;
    const SQL_SELECT  = 3;
    const SQL_DELETE  = 4;
    const SQL_UNKNOWN = -1;

    protected static $pool = [];
    protected $name;
    protected $conn = null;     # 只有在事务模式下, 才会去持有一个PDO连接, 直到对象销毁.
    protected int $_trans_level = 0;
    protected string $errStr = '';
    protected string $_tableGenPfx;
    
    protected string $dbname = '';
    protected bool $logSql = true;
    protected int|bool $cid = false;

    protected static $cidMark = [];         # 死锁检测机制

    # 执行结果保存
    protected $_result = null;
    protected int $_insid = 0;
    protected int $size   = 0;

    # 数据库是否断开连接判断标识
    private static $connBreakDict = [
        'MySQL server has gone away'=>true,
        'Lost connection to MySQL server'=>true, 
        'Error while sending QUERY packet'=>true
    ];

    public function __construct(string $connection = 'db.default') {
        $this->name = $connection;
        $conf = Config::get($this->name);

        $this->dbname = $conf['dbname'];
        $this->logSql = $conf['log_sql'] ?? true;
        $this->size   = $conf['size'] ?? 16;

        $this->_tableGenPfx = "tables_gen.{$this->dbname}";
        if(empty(self::$pool[$this->name])) {
            # Log::write("Connection Size : {$this->size}",  'INFO');
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
                    //\PDO::ATTR_PERSISTENT=>true,                      # 定义为持久连接(在连接池里面不需要)
                ])
                ,$this->size # 默认创建16个数据库连接
            );
        }
    }

    public function __destruct()
    {
        if(null !== $this->conn){
            $this->putConn($this->conn);
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
        return $this->conn = static::$pool[$this->name]->get();
    }

    /**
     * @param $conn null|PDOProxy
     */
    protected function putConn(?PDOProxy $conn) {
        if($this->_trans_level > 0 && null !== $conn) {
            Log::write('错误! 您有忘记提交的事务,该次数据库操作将被丢弃!!!!', 'ERROR');
            $this->_trans_level = 0;
            try{
                $this->conn->rollback();                                # 回滚
                $this->conn->setAttribute(\PDO::ATTR_AUTOCOMMIT, 1);    # 开启自动提交
                # throw new Exception('错误! 您有忘记提交的事务, 该次数据库操作将被丢弃!!!!', 0);
            }catch(\Throwable $e){
                Log::write('发生异常: Object: ' .spl_object_id($this->conn). "\nLine: " . $e->getLine()."\nFILE: ". $e->getFile()."\n:Message: ". $e->getMessage().'Trace:' . $e->getTraceAsString(), 'ERROR');
                Log::flush();
            }
        }

        if(null !== $this->conn){
            static::$pool[$this->name]->put($conn);
            $this->conn = null;
        }

        # 死锁检测标记删除
        if(false !== $this->cid){
            unset(static::$cidMark[$this->cid]);
        }
    }

    # 判断是否为连接丢失异常.
    static private function isbreak(string $str, string $name, int $i) : bool {
        Log::write("数据库异常: {$name}, ({$i}) {$str}", 'ERROR');
        return static::$connBreakDict[$str] ?? false;
    }

    public function startTrans() {
        $this->_trans_level ++;
        if($this->_trans_level == 1){
            $times = $this->size + 1;
            do{
                try{
                    $conn = $this->getConn();
                    $conn->setAttribute(\PDO::ATTR_AUTOCOMMIT,0);     # 关闭自动提交
                    $conn->beginTransaction();                        # 开启事务
                    return;
                }catch(PDOException $e){
                    if($this->isbreak($e->errorInfo[2] ?? '', $this->name, $times)){
                        $this->putConn(null);
                        if(0 == $times){
                            throw $e;
                        }
                    }else{
                        Log::write('发生异常: Object: ' .spl_object_id($conn). "\nLine: " . $e->getLine()."\nFILE: ". $e->getFile()."\n:Message: ". $e->getMessage().'Trace:' . $e->getTraceAsString(), 'ERROR');
                        Log::flush();
                        throw $e;
                    }
                }
                $times --;
            }while($times > 0);
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
                    if($this->isbreak($e->errorInfo[2] ?? '', $this->name, 0)){
                        $this->putConn(null);
                    }else{
                        $this->putConn($this->conn);
                    }
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
                    return true;
                }catch(PDOException $e){
                    if($this->isbreak($e->errorInfo[2] ?? '', $this->name, 0)){
                        $this->putConn(null);
                    }else{
                        $this->putConn($this->conn);
                    }
                    Log::write('发生异常: ' . $e->getMessage().'trace:' . $e->getTraceAsString(), __CLASS__, 'ERROR');
                    Log::flush();
                    throw $e;
                }
            }
        }
        return false;
    }

    private function lazyCreateTableStruct(string $tb) : bool {
        if(0 < $this->execute("SELECT `TABLE_NAME`, `COLUMN_NAME`, `DATA_TYPE`, `COLUMN_COMMENT` FROM information_schema.COLUMNS WHERE TABLE_SCHEMA= ? AND TABLE_NAME= ?", [[$this->dbname,\PDO::PARAM_STR], [$tb,\PDO::PARAM_STR]], Db::SQL_SELECT)){
            Helpers::lazyAppendFieldsCache($this->dbname, $this->_result);
            return true;
        }
        return false;
    }

    public function table(string $tables) : \sys\SqlBuilder
    {
        $tableArr = array_map(function($v){return trim($v," \t\n\r");}, explode(',', $tables));
        $prefix = $this->_tableGenPfx;

        $table = $tableArr[0]; $type = [];
        $defined = Config::get($prefix);
        foreach($tableArr as $tb){
            if(isset($defined[$tb])){
                $type += $defined[$tb];
                continue;
            }
            if(!$this->lazyCreateTableStruct($tb)){
                Log::write("表: {$prefix}.{$tb} 没有找到类型定义.", 'SqlBuilder', 'ERROR');
                continue;
            }
            $type += Config::get("{$prefix}.{$tb}", null);
        }
        return new \sys\SqlBuilder($this, $table, $type);
    }

    /**
     * 执行一条SQL语句. 返回受影响的记录行数, 失败抛出异常.
     * 针对查询语句 有数据返回 获取到的记录数, 无数据返回 0.
     */
    public function execute(string $sql, array $params, int $operation, int $flags = \PDO::FETCH_ASSOC) : ?int {
        if($this->logSql){
            $logSql = SubQuery::buildSql($sql, $params);
            # Log::write($sql . json_encode($params, JSON_PRETTY_PRINT), 'DBX', 'SQL');
            Log::write($logSql, 'SQL');
        }

        $conn = $this->getConn();
        $affert_rows = null;
        $times = $this->size + 1;
        do{
            try{
                $stmt = $conn->prepare($sql);
                /** @var \Swoole\Database\PDOStatementProxy $stmt */
                foreach($params ?? [] as $i=>$value){
                    $stmt->bindValue(1 + $i, $value[0], $value[1]);
                }
                $this->_result = null;
                $stmt->execute(); # 成功 TRUE 失败返回 FALSE
                switch($operation){
                case static::SQL_INSERT:
                    $this->_insid = intval($conn->lastInsertId());
                    $affert_rows = $stmt->rowCount();
                    break;
                case static::SQL_UPDATE:
                case static::SQL_DELETE:
                    $affert_rows = $stmt->rowCount();
                    break;
                case static::SQL_SELECT:
                    $this->_result = $stmt->fetchAll($flags);
                    $affert_rows = $this->_result == null ? 0 : count($this->_result);
                    break;
                case static::SQL_FIND:
                    $this->_result = $stmt->fetch($flags);
                    $affert_rows = $this->_result == null ? 0 : 1;
                    break;
                default:
                    break;
                }
                $stmt->closeCursor();
                if(0 == $this->_trans_level){
                    $this->putConn($conn);
                }
                return $affert_rows;
            }catch(\PDOException $e){
                //TODO: 连接断开判断.
                if(static::isbreak($e->errorInfo[2] ?? '', $this->name, $times)){
                    $this->putConn(null);
                    if($this->_trans_level > 0 || $times == 0){
                        throw $e;
                    }
                }else{
                    $this->putConn($conn);
                    throw $e; //继续抛出异常.
                }
            }
            $times--;
        }while($times > 0);
        return null;
    }

    # 针对查询类语句 获取查询结果.
    public function result(array $map, int $stype) : ?array {
        if($stype == static::SQL_FIND){
            if(null == $this->_result) return null;
            foreach($this->_result as $key=>$val){
                if(null !== $val){
                    switch($map[$key] ?? 'string'){
                    case 'string':
                        $xrow[$key] = $val;break;
                    case 'boolean';
                        $xrow[$key] = $val?true:false;break;
                    case 'integer':
                        $xrow[$key] = intval($val);break;
                    case 'double':
                    case 'float':
                        $xrow[$key] = floatval($val);break;
                    case 'object':
                        $xrow[$key] = json_decode($val, false);break;
                    case 'json':
                    case 'array':
                        $xrow[$key] = json_decode($val, true);break;
                    default:
                        $xrow[$key] = $val;break;
                    }
                }else{
                    $xrow[$key] = null;
                }
            }
            return $xrow;
        }else{
            if(null === $this->_result) return null;
            foreach($this->_result as &$row){
                foreach($row as $key=>$val){
                    if(null !== $val){
                        switch($map[$key] ?? 'string'){
                        case 'string':
                            $row[$key] = $val;break;
                        case 'boolean';
                            $row[$key] = $val?true:false;break;
                        case 'integer':
                            $row[$key] = intval($val);break;
                        case 'double':
                        case 'float':
                            $row[$key] = floatval($val);break;
                        case 'object':
                            $row[$key] = null === $val ? $val : json_decode($val, false);break;
                        case 'json':
                        case 'array':
                            $row[$key] = null === $val ? $val : json_decode($val, true);break;
                        default:
                            $row[$key] = $val;break;
                        }
                    }else{
                        $row[$key ] = null;
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

    private function makeSqls(array $sqls, array &$execSqls, array &$tasks, array &$params) : int {
        $numSqls = 0;
        foreach($sqls as $sql){
            if($sql instanceof SubQuery){
                $params = [...$params, ...$sql->getParams()];
                $execSqls[] = $sql->getSql();
                $numSqls++;
            }elseif($sql instanceof \sys\db\SubTask){
                $params = [...$params, ...$sql->getParams()];
                $execSqls[] = $sql->getSql();
                $numSqls+=2;
                $tasks[] = $sql;
            }elseif(is_string($sql)){
                $execSqls[] = $sql;
                $numSqls++;
            }elseif(is_array($sql)){
                $numSqls += $this->makeSqls($sql, $execSqls, $tasks, $params);
            }
        }
        return $numSqls;
    }
    /**
     * 执行批量查询
     * @param array $sqls  sql 语句数组.
     * @param int $retmode 最后一条sql返回类型. Db::SQL_INSERT ...
     */
    public function batch_query(array $sqls, int $retmode = Db::SQL_UNKNOWN, int $flags = \PDO::FETCH_ASSOC) :?int
    {
        $params = [];$sqlArr = []; $tasks = []; $num = count($sqls);
        $num = $this->makeSqls($sqls, $sqlArr, $tasks, $params);
        if($num == 0) return 0;
        $allSql = implode(";\n", $sqlArr);

        # 是否记录SQL日志
        if($this->logSql){
            $logSql = SubQuery::buildSql($allSql, $params);
            Log::write($logSql, 'SQL');
        }

        $times = $this->size + 1;
        do{
            $conn = $this->getConn();
            try{
                # 在批量执行Sql的时候, 中间可能夹杂有 select 的情况.
                # PDO在执行一条新的SQL的时候, 会检查是否存在尚未拉取完的结果集. 如果存在会报 Cannot execute queries while other unbuffered queries are active. 错误.
                # 解决方法是 开启查询缓存, 这样 PDO在执行sql的时候会自动拉取所有结果集.
                $stmt = $conn->prepare($allSql, [\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY=>true]);
                foreach($params ?? [] as $i=>$value){
                    $stmt->bindValue(1 + $i, $value[0], $value[1]);
                }
                $this->_result = null;
                $stmt->execute();
                $affert_rows = null;
                switch($retmode) {
                case Db::SQL_INSERT:
                    $this->_insid = $affert_rows = (int)$conn->lastInsertId();
                    break;
                case Db::SQL_FIND:
                    do{
                        $row = $stmt->fetch($flags);
                    }while ($stmt->nextRowset());
                    $this->_result = is_array($row) ? $row : null;
                    $affert_rows = $this->_result === null ? 0 : 1;
                    break;
                case Db::SQL_SELECT:
                    do{
                        $rows = $stmt->fetchAll($flags);
                    }while ($stmt->nextRowset());
                    $this->_result = $rows ?? null;
                    $affert_rows = null == $this->_result ? 0 : count($this->_result);
                    break;
                default:
                    do{
                    }while ($stmt->nextRowset());
                    $affert_rows = $stmt->rowCount();
                    break;
                }
                $stmt->closeCursor();
                if(0 == $this->_trans_level) $this->putConn($conn);
                return $affert_rows;
            }catch(PDOException $e){
                Log::write(implode([$e->getMessage(),"\nFILE: ", $e->getFile(), "\nLINE: ", $e->getLine(),"\nTRACE: ", $e->getTraceAsString(),"\n"]),'ERROR');
                if(static::isbreak($e->errorInfo[2] ?? '', $this->name, $times)) {
                    $this->putConn(null);
                    if($this->_trans_level > 0 || $times == 0){
                        throw $e;
                    }
                } else {
                    $this->putConn($conn);
                    throw $e; //继续抛出异常.
                }
            }
            $times --;
        }while($times > 0);
        return null;
    }

    // 它能批量执行 insert 和update 语句. 并将结果返回.
    /**
     * 执行批量查询
     * @param array $sqls  sql 语句数组.
     * @param int $retmode 最后一条sql返回类型. Db::SQL_INSERT ...
     */
    public function batch_execute(array $sqls) :bool
    {
        $params = [];$sqlArr = []; $tasks = []; $num = 0;
        $numSqls = $this->makeSqls($sqls, $sqlArr, $tasks, $params);
        if($numSqls == 0) return 0;
        $numTasks = count($tasks);

        # 存在任务, 则需要获取任务的返回值.
        if($numTasks > 0){                    
            $fields = [];
            foreach($tasks as $task){
                $task->getVars($fields);
            }
            $numSqls++;
            $sqlArr[] = 'SELECT '. implode(',', array_unique($fields));
        }
        $allSql = implode(";\n", $sqlArr);

        # 是否记录SQL日志
        if($this->logSql){
            $logSql = SubQuery::buildSql($allSql, $params);
            Log::write($logSql, 'SQL');
        }

        $times = $this->size + 1;
        do{
            $conn = $this->getConn();
            try{
                # 在批量执行Sql的时候, 中间可能夹杂有 select 的情况.
                # PDO在执行一条新的SQL的时候, 会检查是否存在尚未拉取完的结果集. 如果存在会报 Cannot execute queries while other unbuffered queries are active. 错误.
                # 解决方法是 开启查询缓存, 这样 PDO在执行sql的时候会自动拉取所有结果集.
                $stmt = $conn->prepare($allSql, [\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY=>true]);
                foreach($params ?? [] as $i=>$value){
                    $stmt->bindValue(1 + $i, $value[0], $value[1]);
                }
                $this->_result = null;
                $stmt->execute();
                $numResult = 0;
                do{
                    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
                    $numResult ++;
                }while ($stmt->nextRowset());
                Log::console("numResult: {$numResult}, numTasks: {$numSqls}",'DEBUG');
                if($numTasks > 0){
                    if(is_array($row)){
                        foreach($tasks as $task){
                            $task->setResults($row);
                        }
                        return true;
                    }
                    return false;
                }
                return $numResult == $numSqls;
            }catch(PDOException $e){
                if(static::isbreak($e->errorInfo[2] ?? '', $this->name, $times)) {
                    $this->putConn(null);
                    if($this->_trans_level > 0 || $times == 0){
                        throw $e;
                    }
                } else {
                    $this->putConn($conn);
                    throw $e; //继续抛出异常.
                }
            }
            $times --;
        }while($times > 0);
        return false;
    }
    public function task(string $table): \sys\db\SubTask {
        return new \sys\db\SubTask($this, $table);
    }
}