<?php
declare(strict_types=1);

namespace sys;

use JsonSerializable;

abstract class Data implements JsonSerializable {

    //定义数据记录操作
    const OP_CREATE =  0;
    const OP_UPDATE =  1;
    const OP_REMOVE =  2;

    //定义权限标识
    const ATTR_ALLOW_REMOVE = 2;
    const ATTR_ALLOW_UPDATE = 1;
    const ATTR_ALLOW_ALL    = 0;


    # 创建对象必须字段
    static protected array $propNamesC = [];
    # 插入数据库必须字段
    static protected array $propNamesI = [];
    # 可更新字段
    static protected array $propNamesU = [];
    # JSON输出字段
    static protected array $propNamesJ = [];
    static protected $allowCreate      = true;
    static protected $withPub          = false;

    public function __construct(array $data)
    {
        foreach(static::$propNamesC as $prop){
            $this->$prop = $data[$prop] ?? null;
        }
    }
    
    protected static function get(int $id) :?static {
        return null;
    }

    /**
     * 创建一个实例, 如果对象中已经存在 直接返回先前的实例.
     */
    public static function createInstance(array $data, bool $reload = false) : static {
        if(null === ($u = static::get($data['id']))){
            return new static($data);
        }
        $reload && $u->update($data);
        return $u;
    }

    public static function allowCreateFields(array $data): array{
        $ret = [];
        $tplFields = count(static::$propNamesI) == 0 ? static::$propNamesC : static::$propNamesI;
        foreach($tplFields as $key){
            if(isset($data[$key]))
                $ret[$key] = $data[$key];
        }
        return $ret;
    }

    protected function onUpdate(array &$newvalue, array $oldvalue) :void 
    {

    }

    public static function alwaysFullSync() : bool {
        return false;
    }

    public function jsonSerialize(): mixed
    {
        $ret = [];
        $props = empty(static::$propNamesJ) ? static::$propNamesC : static::$propNamesJ;
        foreach($props as $prop){
            $ret[ $prop ] = $this->$prop;
        }
        return $ret;
    }
   
    /**
     * 检查是否符合更新条件.
     */
    public function where(array $cond) : static | \sys\data\Deny{
        foreach($cond as $prop=>$value){
            if(is_callable($value)){
                if(!$value($this->$prop)){
                    return new \sys\data\Deny();
                }
                return $this;
            }elseif($this->$prop != $value){
                return new \sys\data\Deny();
            }
        }
        return $this;
    }

    /**
     * 更新字段.
     * @param array $data 要更新的数据.
     * @param bool $setNull false 表示存在则更新, true 表示, 不存在则设置为 null.
     * @return ?array 有变更返回 变更数组, 无变更返回 null.
     */
    public function update(array $data, bool $setNull = false, &$oldValue = null) : ?array {
        $newvalue = null;
        $oldvalue = null;
        # 比较值

        foreach(static::$propNamesU as $prop){
            if(array_key_exists($prop, $data)){
                if(value_compare($data[$prop], $this->$prop)){
                    continue;
                }
                $oldvalue[$prop] = $this->$prop;
                $newvalue[$prop] = $this->$prop = $data[$prop];
            }else if(true === $setNull){
                $oldvalue[$prop] = $this->$prop;
                $newvalue[$prop] = $this->$prop = null;
            }
        }
    

        # 触发更新事件, 用于 Traits 重建索引.
        if(null !== $newvalue){
            $this->onUpdate($newvalue, $oldvalue);
        }

        return $newvalue;
    }

    /**
     * 检查字段
     */
    public function checkUpdate(array $data, bool $setNull = false) : ?array {
        $newvalue = null;
        $oldvalue = null;
        # 比较值

        foreach(static::$propNamesU as $prop){
            if(array_key_exists($prop, $data)){
                if(value_compare($data[$prop], $this->$prop)){
                    continue;
                }
                $oldvalue[$prop] = $this->$prop;
                $newvalue[$prop] = $data[$prop];
            }else if(true === $setNull){
                $oldvalue[$prop] = $this->$prop;
                $newvalue[$prop] = null;
            }
        }
        return $newvalue;
    }

    public function __get($name) : mixed
    {
        return $this->$name ?? null;
    }
}