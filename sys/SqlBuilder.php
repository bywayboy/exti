<?php
declare(strict_types=1);

namespace sys;

use Exception;

class SqlBuilder {
    protected $_table;                  # 要构造SQL的表名
    protected array $_where = [];       # WHERE 条件数组
    protected array $_types = [];       # 字段类型表
    protected array $fields = [];       # 检索字段表 SELECT ...
    protected array $_update = [];      # 更新字段表 UPDATE 
    protected array $_join = [];        # 要关联的表名
    protected $_order;                  # 检索排序条件
    protected $_lock;                   # FOR UPDATE/LOCK IN SHARE MODE
    protected array $_params = [];      # Sql 绑定参数
    protected array $_conds  = [];      # WHERE绑定参数

    protected \sys\Db $db;

    /**
     * 参数绑定类型映射
     * @var array
     */
    protected static $bindType = [
        'integer'   => \PDO::PARAM_INT,
        'string'    => \PDO::PARAM_STR,
        'binary'    => \PDO::PARAM_LOB,
        'double'    => \PDO::PARAM_STR,
        'boolean'   => \PDO::PARAM_BOOL,
        'null'      => \PDO::PARAM_NULL,
    ];

    protected static $tjson_ = ['json'=>true,'array'=>true,'object'=>true];

    public function __construct(\sys\Db $db, string $table, array $types)
    {
        $this->db = $db;
        $this->_table = str_replace('.', '`.`', $table);
        $this->_types = $types;
    }

    private function _appendConds(array $pms){
        foreach($pms as $item){
            $this->_conds[] = $item;            
        }
    }

    public function __toString() :string {
        return $this->sql ?? 'fuck you ';
    }


    protected function  _parseValue($value, string $type)
    {
        if(static::$tjson_[$type] ?? false)
            return [json_encode($value), \PDO::PARAM_STR];
        return [$value, static::$bindType[ $type ] ?? \PDO::PARAM_STR];
    }

    /**
     * 查询处理 IN NOT IN 表达式
     * @access protected
     * @param  string       $field      字段名
     * @param  string       $cond       运算符
     * @param  mixed        $value      查询值
     * @param  string|null       $type       字段类型
     * @return string
     */
    private function _parseIn(string $field, string $cond, $value, ?string $type = null){

        if($value instanceof \sys\db\SubQuery){
            $this->_appendConds($value->getParams());
            return "`{$field}` {$cond} (".$value->getSql().')';
        }
        if(is_array($value)){
            foreach ($value as $i=>$item) {
                $this->_conds[] = $this->_parseValue($item, $type ?? gettype($item));
            }

            return "`{$field}` {$cond} (" . substr(str_repeat(',?', count($value)), 1) . ')';
        }
        throw new Exception("IN, NOT IN 的条件只支持 SubQuery 或者 Array 类型.");
    }


    private function _parseBetween(array $value, $type){
        $parts = ['BETWEEN '];
        $from   = $value[0];
        $to  = $value[1];

        if($from instanceof \sys\db\SubQuery){
            $parts[] = '('.$from->getSql().') AND ';
            $this->_appendConds($from->getParams());
        }else{
            $this->_conds[] = $this->_parseValue($from, $type);
            $parts[] = '? AND ';
        }

        if($from instanceof \sys\db\SubQuery){
            $parts[] = '(' . $to->getSql().')';
            $this->_appendConds($to->getParams());
        }else{
            $this->_conds[] = $this->_parseValue($to, $type);
            $parts[] = '?';
        }
        return implode('', $parts);
    }

    # 比较类运算符生成
    private function _parseEq(string $field, string $cond, array $exp, string $type) : string
    {
        $value = $exp[2];
        if($value === null) return "`{$field}` is NULL";
        if($value instanceof \sys\db\SubQuery){
            $this->_appendConds($value->getParams());
            return "`{$field}` {$cond} (".$value->getSql().')';
        }
        $this->_conds[] = $this->_parseValue($value, $type);
        return "`{$field}` {$cond} ?";
    }

    # EXP 类表达式生成方式
    private function _parseExp(array $values, string $type) : string
    {
        $parts = [];
        foreach($values as $i=>$item){
            if($item instanceof \sys\db\SubQuery){
                # echo json_encode($this->_params)."\n";
                $this->_appendConds($item->getParams());
                $parts[] = '(' . $item->getSql() . ')';
            }else{
                $parts[] = $item;
            }
        }
        return implode(' ', $parts);
    }

    protected function _parseExpection(array $exp) :string {
        $fieldParts = explode('.', $exp[0]);
        $cond = strtoupper($exp[1]);
        $fullFieldName = implode('`.`', $fieldParts);

        $field = $fieldParts[ count($fieldParts) - 1];

        switch($cond){
        case '=':
        case '<>':
        case '>':
        case '<':
        case '>=':
        case '<=':
            $type = $this->_types[ $field ] ?? gettype($exp[2]);
            return $this->_parseEq($fullFieldName, $cond, $exp, $type);
        case 'LIKE':
            return $this->_parseEq($fullFieldName, $cond, $exp, 'string');
        case 'IN':
        case 'NOT IN':
            $type = $this->_types[ $field ] ?? null;
            return $this->_parseIn($field, $cond, $exp[2], $type);
        case 'IS':
            return "`{$fullFieldName}` is NULL";
        case 'IS NOT':
            return "`{$fullFieldName}` is NOT NULL";
            break;
        case 'BETWEEN':
            $type = $this->_types[ $field ] ?? gettype($exp[2]);
            return "`{$fullFieldName}` " . $this->_parseBetween($exp[2], $type);
        case 'EXP':
            $type = $this->_types[ $field ] ?? gettype($exp[2]);
            return $this->_parseExp(array_slice($exp, 2), $type);
        default:
            throw new Exception('无法识别的表达式: \"'. $cond . '\"');
            break;
        }
    }

    protected function _parseWhere(array $cond): ?string {
        $numElm = count($cond);
        $xcond = []; $hasExp = true;
        if($numElm >= 3 && is_string($cond[0]) && is_string($cond[1])){
            //这是一个表达式.
            return $this->_parseExpection($cond);
        }else{
            foreach($cond as $exp){
                if(is_string($exp)){
                    $exp = strtoupper($exp);
                    if($exp =='AND' || $exp == 'OR'){
                        if(true== $hasExp){
                            throw new \Exception('前一个逻辑运算符已经存在!'.$exp);
                        }
                        $xcond[] = $exp;
                        $hasExp = true;
                    }else{
                        throw new \Exception('无法识别的条件运算符:'.$exp);
                    }
                }elseif(is_array($exp)){
                    if(count($exp) > 0){
                        if(!$hasExp) $xcond[] = 'AND';
                        $xcond[] = '('.$this->_parseWhere($exp).')';
                        $hasExp = FALSE;
                    }
                }
            }
            if(!empty($xcond))
                return implode(' ', $xcond);
        }
    }

    protected function _where(string $logic, array $cond) :\sys\SqlBuilder {
        $xcond = [];
        if(is_array($cond)){
            $numElm = count($cond);
            if($numElm >= 3 && is_string($cond[0]) && is_string($cond[1])){
                //这是一个表达式 
                $xcond[] = $this->_parseExpection($cond);
            } elseif ($numElm > 0) {
                $xcond[] = '('.$this->_parseWhere($cond).')';
            }
        }else if(is_string($cond)){
            $xcond[] = $cond;
        }

        if(!empty($xcond)){
            if(empty($this->_where)){
                $this->_where[] = implode(' ', $xcond);
            }else{
                $this->_where[] = $logic . '(' . implode(' ', $xcond) . ')';
            }
        }
        return $this;
    }

    public function where(array $cond) :\sys\SqlBuilder {
        return $this->_where(' AND ', $cond);
    }

    public function whereOr(array $cond) :\sys\SqlBuilder {
        return $this->_where(' OR ', $cond);
    }

    public function join(string $table, string $condition, string $type = 'INNER JOIN') :\sys\SqlBuilder {
        $this->_join[] = [' ' . $type . ' ', $table, $condition];
        return $this;
    }

    public function lock(bool $forUpdate = true) : \sys\SqlBuilder {
        $this->_lock = $forUpdate? ' FOR UPDATE':' LOCK IN SHARE MODE';
        return $this;
    }

    public function order(string $order) :\sys\SqlBuilder {
        $this->_order = $order;
        return $this;
    }


    public function field($fields) :\sys\SqlBuilder {
        $this->_fields = is_array($fields)? implode(', ', $fields): $fields;
        return $this;
    }

    //注意: 该方法不支持和 join 一起使用.
    public function withoutField($fields) : \sys\SqlBuilder {
        $fields = \is_string($fields)? explode(',', $fields) : $fields;
        $fields = \array_map(function ($v){ return \trim($v);}, $fields);
        if(!empty($fields)){
            $this->_fields = implode(', ', \array_diff(\array_keys($this->_types), $fields));
        }
        return $this;
    }

    public function limit(int $offset, ?int $num=null) :\sys\SqlBuilder {
        $this->_limit = is_null($num) ? ' LIMIT ' . $offset : ' LIMIT ' . $offset . ', ' . $num;
        return $this;
    }

    public function page(int $page, int $pageSize = 20) :\sys\SqlBuilder {
        $this->_limit = ' LIMIT '.(max($page - 1, 0) * $pageSize) .',' . $pageSize;
        return $this;
    }

    public function inc(string $field, $value) :\sys\SqlBuilder{
        $rawtype = gettype($value);
        echo "raw = {$rawtype}\n";
        if($rawtype == 'object' && $value instanceof \sys\db\ExpValue){
            $this->_update[] = '`' . $field. '` = `' . $field .'` + ' . strval($value);
        }else{
            $this->_update[] = '`' . $field. '` = `' . $field .'` + ?';
            $this->_params[] = $this->_parseValue($value, $this->_types[ $field ] ?? $rawtype);
        }
        return $this;
    }

    public function dec(string $field, $value) :\sys\SqlBuilder{
        $rawtype = gettype($value);
        if($rawtype == 'object' && $value instanceof \sys\db\ExpValue){
            $this->_update[] = '`' . $field. '` = `' . $field .'` - ' . strval($value);
        }else{
            $this->_update[] = '`' . $field. '` = `' . $field .'` - ?';
            $this->_params[] = $this->_parseValue($value, $this->_types[ $field ] ?? $rawtype);
        }
        return $this;
    }

    protected function selectSql() {
        $sql_parts = ['SELECT ', $this->_fields ?? '*', ' FROM `', $this->_table,'`'];

        if(!empty($this->_join)){
            foreach($this->_join as $join){
                array_push($sql_parts, $join[0], $join[1], ' ON ', $join[2]);
            }
        }
        if(!empty($this->_where)){
            $sql_parts[] = ' WHERE ';
            $sql_parts[] = implode('', $this->_where);
        }

        if(!empty($this->_order)){
            $sql_parts[] = ' ORDER BY '. $this->_order .' ';
        }

        $sql_parts[] = $this->_limit ?? '';

        if(!empty($this->_lock)){
            $sql_parts[] = $this->_lock;
        }

        return implode('', $sql_parts);
    }

    protected function findSql(): string {
        $sql_parts = ['SELECT ', $this->_fields ?? '*', ' FROM `', $this->_table, '`'];

        if(!empty($this->_join)){
            foreach($this->_join as $join){
                array_push($sql_parts, $join[0], $join[1], ' ON ', $join[2]);
            }
        }

        if(!empty($this->_where)){
            $sql_parts[] = ' WHERE ';
            $sql_parts[] = implode('', $this->_where);
        }
        if(!empty($this->_order)){
            $sql_parts[] = ' ORDER BY '. $this->_order .' ';
        }
        $sql_parts[] = ' LIMIT 1';

        if(!empty($this->_lock)){
            $sql_parts[] = $this->_lock;
        }
        
        return implode('', $sql_parts);
    }

    protected function insertSql(array $data) :string {
        $this->_params = [];
        $sql_parts = ['INSERT INTO `', $this->_table, '` SET '];

        $values = [];
        foreach ($data as $key => $val) {
            if(null === $val){
                $values[] = '`' . $key . '` = NULL' ;
            }else{
                $rawtype = gettype($val);
                if('object' == $rawtype && $val instanceof \sys\db\ExpValue){
                    $values[] = '`' . $key . '` = '. strval($val);
                }else{
                    $values[] = '`' . $key . '` = ?' ;
                    $this->_params[] = $this->_parseValue($val, $this->_type[$key] ?? $rawtype);
                }
            }
        }
        $sql_parts[] = \implode(',', $values);
        return implode('', $sql_parts);
    }

    public function updateSql(array $data = []): string {
        $sql_parts = ['UPDATE `', $this->_table, '` SET '];
        $update = $this->_update;
        foreach ($data as $key => $val) {
            if(null === $val){
                $update[] = '`' . $key . '` = NULL' ;
            }else{
                $rawtype = gettype($val);
                if('object' == $rawtype && $val instanceof \sys\db\ExpValue){
                    $update[] = '`' . $key . '` = '. strval($val);
                }else{
                    $update[] = '`' . $key . '` = ?' ;
                    $this->_params[] = $this->_parseValue($val, $this->_type[$key] ?? $rawtype);
                }
            }
        }
        $sql_parts[] = \implode(', ', $update);

        if(!empty($this->_where)){
            $sql_parts[] = ' WHERE ';
            $sql_parts[] = implode('', $this->_where);
        }
        
        $sql_parts[] = $this->_limit ?? '';

        return implode('', $sql_parts);
    }

    /**
     * 构造删除记录SQL, 用于 PDO
     * @access private
     * @return string 返回Sql语句
     */
    private function deleteSql() : string {
        $sql_parts = ['DELETE FROM `', $this->_table, '` WHERE '];

        if(empty($this->_where)){
            throw new \Exception("删除数据记录没有提供条件.");
        }
        $sql_parts[] = implode('', $this->_where);

        $sql_parts[] = $this->_limit ?? '';
        return implode('', $sql_parts);
    }

    /**
     * 插入一条记录到数据库中
     * @access public
     * @param array $data 要插入到数据
     * @return ?int 成功返回 1 失败返回 null
     */
    public function insert(array $data) : ?int
    {
        $sql = $this->insertSql($data);
        if(1 == $this->db->execute($sql, $this->_params, \sys\Db::SQL_INSERT)){
            return $this->db->lastInsertId();
        }
        return null;
    }

    /**
     * 查找符合条件的单条记录
     * @access public
     * @return ?array 成功返回符合条件的结果, 失败或者未找到返回 null;
     */
    public function find() : ?array {
        $sql = $this->findSql();
        if(1 == $this->db->execute($sql, [...$this->_params, ...$this->_conds], \sys\Db::SQL_FIND)){
            return $this->db->result($this->_types, \sys\Db::SQL_FIND);
        }
        return null;
    }

    /**
     * 查找符合条件的记录
     * @access public
     * @return ?array 成功返回符合条件的结果, 失败或者未找到返回 null;
     */
    public function select() : ? array {
        $sql = $this->selectSql();
        if(0 < $this->db->execute($sql, $this->_conds, \sys\Db::SQL_SELECT)){
            return $this->db->result($this->_types, \sys\Db::SQL_SELECT);
        }
        return null;
    }

    /**
     * 修改符合条件的记录
     * @access public
     * @param array $data 要修改的记录
     * @return >int 成功返回被修改的记录数, 失败返回 null
     */
    public function update(array $data = []) : ? int {
        $sql = $this->updateSql($data);
        return $this->db->execute($sql, [...$this->_params, ...$this->_conds], \sys\Db::SQL_UPDATE);
    }

    /**
     * 删除记录
     * @access public
     * @return ?int 成功返回被删除的记录数, 失败返回 null
     */
    public function delete() : ?int {
        $sql = $this->deleteSql();
        return $this->db->execute($sql, $this->_conds, \sys\Db::SQL_DELETE);
    }

    # ==   缓存查询方法 用于子查询 或者 批量查询场合 ===

    public function subInsert(array $data = []) : \sys\db\SubQuery {
        $sql = $this->insertSql($data);
        return new \sys\db\SubQuery($sql, $this->_params, \sys\Db::SQL_INSERT);
    }

    public function subSelect() :\sys\db\SubQuery {
        $sql = $this->selectSql();
        return new \sys\db\SubQuery($sql, $this->_conds, \sys\Db::SQL_SELECT);
    }

    public function subFind() :\sys\db\SubQuery {
        $sql = $this->findSql();
        return new \sys\db\SubQuery($sql, $this->_conds, \sys\Db::SQL_FIND);
    }

    public function subUpdate(array $data = []) :\sys\db\SubQuery {
        $sql = $this->updateSql($data);
        return new \sys\db\SubQuery($sql, [...$this->_params, ...$this->_conds], \sys\Db::SQL_UPDATE);
    }

    public function subDelete() : \sys\db\SubQuery {
        $sql = $this->deleteSql();
        return new \sys\db\SubQuery($sql, $this->_conds, \sys\Db::SQL_DELETE);
    }

}
