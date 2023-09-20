<?php
declare(strict_types=1);

namespace sys\db;

use sys\Log;
use sys\SqlBuilder;

class SubTask {
    protected int $type;
    protected string $var;
    protected string $table;
    protected SubQuery $query;
    protected SqlBuilder $sqlBuilder;
    protected array $data;
    protected ?int $id = null;
    protected array $vars = [];

    public function __construct(?\sys\Db $db, string $table) {
        if(null !== $db){
            $this->var = '@r_'.spl_object_id($this);
            $this->sqlBuilder = $db->table($table);
        }
        $this->table = $table;
    }

    public static function createInstance(string $table, array $data, int $type) :static {
        $instance = new static(null, $table);
        $instance->data = $data;
        $instance->id = $data['id'];
        $instance->type = $type;
        return $instance;
    }

    public function pkVarName():string{
        return $this->var;
    }

    public function getTableName():string{
        return $this->table;
    }
    public function gettype():int{
        return $this->type;
    }
    public function getId():?int{ return $this->id;}

    public function subInsert(array $data) :static {
        $this->query = $this->sqlBuilder->subInsert($data);
        $this->type = \sys\Db::SQL_INSERT;

        # 需要获取的自增ID
        $this->vars[$this->var] = 'id';

        $this->data = $data;
        foreach($data as $key=>$var){
            if($var instanceof \sys\db\ExpValue){
                $this->vars[(string)$var] = $key;
                break;
            }
        }
        return $this;
    }

    public function insert(array $data){
        $this->query = $this->sqlBuilder->insert($data);
        $this->type = \sys\Db::SQL_INSERT;
        $this->data = $data;
        foreach($data as $key=>$var){
            if($var instanceof \sys\db\ExpValue){
                $this->vars[(string)$var] = $key;
                break;
            }
        }
        return $this;
    }

    public function where(int $id) :static {
        $this->id = $id;
        return $this;
    }

    public function subUpdate(array $data) :static {
        if(is_null($this->id)){
            if(!isset($data['id'])){
                throw new \Exception('primaryKey is null');
            }
            $this->query = $this->sqlBuilder->where(['id', '=', (int)$data['id']])->subUpdate($data);
        }else{
            $this->query = $this->sqlBuilder->where(['id','=', $this->id])->subUpdate($data);
        }
        $this->type = \sys\Db::SQL_UPDATE;
        $this->data = $data;
        $this->data['id'] = $this->id;

        foreach($data as $key=>$var){
            if($var instanceof \sys\db\ExpValue){
                $this->vars[(string)$var] = $key;
                break;
            }
        }
        return $this;
    }

    public function subDelete() :static {
        if(is_null($this->id)){
            throw new \Exception('primaryKey is null');
        }
        $this->query = $this->sqlBuilder->where(['id','=', $this->id])->subDelete();
        $this->type = \sys\Db::SQL_DELETE;
        return $this;
    }

    public function getParams():array {
        return $this->query->getParams();
    }

    public function getSql() : string{
        $t = $this->query->gettype();
        $sql = $this->query->getSql();
        switch($t){
        case \sys\Db::SQL_DELETE:
        case \sys\Db::SQL_UPDATE:
            return $sql .";\nSET {$this->var} = ROW_COUNT()";
        case \sys\Db::SQL_INSERT:
            return $sql .";\nSET {$this->var} = LAST_INSERT_ID()";
        case \sys\Db::SQL_SELECT:
            return $sql .";\nSET {$this->var} = FOUND_ROWS()";
        }
        throw new \Exception("Error Processing Request", 1);
    }

    public function getVars(array &$vars){
        foreach($this->vars as $var=>$value){
            $vars[] = $var;
        }
    }

    public function setResults(array $result){
        switch($this->type){
        case \sys\Db::SQL_UPDATE:
            foreach($this->vars as $var=>$key){
                $this->data[$key] = (int)$result[$var];
            }
            break;
        case \sys\Db::SQL_INSERT:
            foreach($this->vars as $var=>$key){
                $this->data[$key] = (int)$result[$var];
            }
            $this->id = (int)$result[$this->var];
            break;
        }
    }

    public function getData() : array {
        return $this->data;
    }
}