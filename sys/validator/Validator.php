<?php
declare(strict_types=1);

namespace sys\validator;

use JsonSerializable;

/*
 :用法
    Validator::validate([
        'name|姓名'=>'required'
    ])->check($data)
 */
class Validator implements JsonSerializable {
    protected $rules;
    protected $errMsg = [];

    protected static array $messages = [
        'require'=>':?为必填项.',
        'number'=>':?必须是数字.',
        'unumber'=>':?必须是无符号数字.',
        'boolean'=>':?必须是逻辑类型.',
        'mobile'=>':?手机号码格式错误.',
        'tel'=>':?电话号码格式错误.',
        'idcard'=>':?证件号码格式错误.',
        'nospace'=>':?不允许有空格',
        'chs'=>':?只允许中文、字母、数字 _-.',
        'ens'=>':?只允许字母、数字、_-.',
        'zip'=>':?邮政编码格式错误.',
        'ean13'=>':?商品条码格式错误.',
        'in'=>':?的值无效.',
        'between'=>':?的值不在有效范围内.',
        'min'=>':?的值太小了.',
        'max'=>':?的值太大了.',
        'mod'=>':?的值无效.',
        'minlength'=>':?的长度太短了.',
        'maxlength'=>':?的长度太短了.',
        'length'=>':?的长度不在有效范围.',
        'regex'=>':?的格式错误.',
        'confirm'=>':?两次输入不一致.',
        'integer'=>':?的值必须是整数.',
        'uinteger'=>':?的值必须是无符号整数.',
        'requireIf'=>':?为必填项.',
        'requireIfNot'=>':?为必填项.',
        'array'=>':?必须是数组',
        'list'=>':?必须是列表',
        'intlist'=>':?必须是整数列表',
    ];

    protected static $cache = [];

    public function __construct(array $rules_, ?string $CacheKey_ = null)
    {
        if($CacheKey_ && isset(static::$cache[ $CacheKey_ ])){
            //echo "命中缓存: {$CacheKey_}\n";
            $this->rules = static::$cache[ $CacheKey_ ];
        } else {
            # 第一步 parse rules
            $rules = [];
            foreach($rules_ as $key=>$rule){
                @list($key, $name) = explode('|', $key, 2);
                
                if(is_string($rule)){
                    $expressions = explode('|', $rule);
                    $xexps = [];
                    foreach($expressions as $expression){
                        $parts = explode(':', $expression,3); # 限制分割3次  规则:参数... :消息
                        if(count($parts) > 1){
                            $parts[1] = explode(',', $parts[1]);
                        }
                        $xexps[] = $parts;
                    }
                }else{
                    $xexps = [];
                    foreach($rule as $expression){
                        $xexps[] = $expression;
                    }
                }
                static::_newrule($rules, $key, $name, $xexps);
            }
            //echo "生成规则表:".json_encode($rules, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
            $this->rules = $rules;
            if(null !== $CacheKey_){
                static::$cache[ $CacheKey_ ] = $rules;
                //echo "缓存规则表: {$CacheKey_} \n";
            }
        }
    }

    private static function _newrule(array &$rules, string $skey, ?string $name, array $exps) {
        $keys = explode('.', $skey);
        
        # 设置节点
        foreach($keys as $i=>$key){
            if($i == 0){
                $rules[$key] = $rules[$key] ?? [];
                $rules = &$rules[$key];
            }else{
                $rules['childs'][$key] = $rules['childs'][$key] ?? [];
                $rules = &$rules['childs'][$key];
            }
        }
        $rules = ['exps'=>$exps, 'name'=>$name, ...$rules];
    }

    public function jsonSerialize() : array  {
        return empty($this->errMsg) ? ['success'=>true, 'done'=>true, 'message'=>'表单验证通过.'] : ['success'=>false, 'done'=>true, 'message'=>$this->errMsg[0], 'extra'=>$this->errMsg];
    }

    # >>> 验证规则开始.
    /**
     * 表单中不存在该字段, 字段值为 null|空文本|空数组 都会视为验证不通过.
     */
    protected static function require($value, array $data = null, ?array $args = null) : bool{
        if(null === $value || (is_string($value) && trim($value) === '') || (is_array($value) && count($value) == 0))
            return false;
        return true;
    }

    # 用法: requireIf:wanjia,weixin,alipay,mode  当 mode 为 wanjia,weixin,alipay 时 必填
    protected static function requireIf($value, array $data, ?array $args) : bool {
        $field = array_pop($args);
        $reqVal = isset($data[ $field ]) ? $data[ $field ] : null;
        if(empty($args)){
            if(static::require($reqVal))
                return !(null === $value || '' === $value);
        }else{
            if(in_array($reqVal, $args)){
                return !(null === $value || '' === $value);
            }
        }
        return true;
    }
    # 用法: 
    protected static function requireIfNot($value, array $data, ?array $args) : bool {
        $field = array_pop($args);
        $reqVal = isset($data[ $field ]) ? $data[ $field ] : null;
        if(empty($args)){
            if(!static::require($reqVal))
            return static::require($value);
        }else{
            if(!in_array($reqVal, $args)){
                return static::require($value);
            }
        }
        return true;
    }

    protected static function in($value, array $data, array $args): bool
    {
        if(null === $value || '' === $value) return true;
        return in_array($value, $args);
    }

    protected static function between($value, array $data, array $args): bool
    {
        if(null === $value || '' === $value) return true;
        return $value >=$args[0] && $value <= $args[1];
    }

    protected static function mobile($value, array $data, ?array $args) : bool{
        if(null === $value || '' === $value) return true;
        return preg_match('/^1[3-9]\d{9}$/', $value)?true:false;
    }

    protected static function tel($value, array $data, ?array $args) : bool {
        if(null === $value || '' === $value) return true;
        
        # 1xxxxxxxxx  手机号码格式
        # 0730-xxxxxxx 区号+电话格式
        # xxxxxxxx  电话号码格式
        if(preg_match('/^(1[3-9]\d{9})|(\d{3,4}\-\d{7,8})|(\d{7,8})$/', $value)) return true;
        return false;
    }

    protected static function zip($value, array $data, ?array $args): bool {
        if(null === $value || '' === $value) return true;
        return preg_match('/\d{6}/', $value)?true:false;
    }

    # 商品条码验证
    protected static function ean13($value, array $data, ?array $args):bool{
        if(null === $value || '' === $value) return true;
        if(!preg_match('/^\d{13}$/', $value))
            return false;
        $length = strlen($value);
        $sum = 0; $sum1 = 0; $sum2 = 0;
        for($i = 0; $i < 12; $i +=2){
            $sum1 += $value[$i];
            $sum2 += $value[$i + 1];
        }
        $sum = (10 - (($sum1 + $sum2 * 3) % 10) % 10);
        return $sum == $value[$length - 1];
    }

    protected static function min($value, array $data, ?array $args): bool{
        if(null === $value || '' === $value) return true;
        return $value >= $args[0];
    }

    protected static function max($value, array $data, ?array $args): bool{
        if(null === $value || '' === $value) return true;
        return $value <= $args[0];
    }

    # 正则表达式验证
    protected static function regex($value, array $data, ?array $args) : bool {
        if(null === $value || '' === $value) return true;
        return preg_match($args[0], strval($value))?true:false;
    }

    # 求余=0验证
    protected static function mod($value, array $data, ?array $args):bool{
        if(null == $value || '' == $value) return true;
        return fmod(floatval($value), floatval($args[0])) == 0.0;
    }

    # 最小长度限制验证.
    protected static function minlength($value, array $data, ?array $args):bool{
        if(null === $value || '' === $value) return true;
        return mb_strlen(trim(strval($value)), 'UTF-8') >= $args[0];
    }

    # 最大长度限制验证
    protected static function maxlength($value, array $data, ?array $args):bool{
        if(null === $value || '' === $value) return true;
        return mb_strlen(trim(strval($value)), 'UTF-8') <= $args[0];
    }

    # 长度范围验证
    protected static function length($value, array $data, ?array $args):bool{
        if(null === $value || '' === $value) return true;
        $len = mb_strlen(trim(strval($value)), 'UTF-8'); $argc = count($args);
        if(1 == $argc){
            return $len >= intval($args[0]);
        }elseif(2 == $argc){
            return $len >= min($args[0], $args[1]) && $len <= max($args[0], $args[1]);
        }
        return false;
    }

    # 比较字段验证
    protected static function confirm($value, array $data, ?array $args):bool {
        return $value === $data[ $args[0] ];
    }

    # 身份证有效性验证.
    protected static function idcard($value, $data, ?array $args): bool {
        if(null === $value || '' === $value) return true;
        return preg_match('/(^[1-9]\d{5}(18|19|([23]\d))\d{2}((0[1-9])|(10|11|12))(([0-2][1-9])|10|20|30|31)\d{3}[0-9Xx]$)|(^[1-9]\d{5}\d{2}((0[1-9])|(10|11|12))(([0-2][1-9])|10|20|30|31)\d{3}$)/', strval($value))?true:false;
    }

    # 空格验证
    protected static function nospace($value, $data, ?array $args) : bool {
        if(null === $value || '' === $value) return true;
        return $value === preg_replace('#\s#', '', strval($value));
    }

    # 中文、数字、字母
    protected static function chs($value, array $data, ?array $args) : bool {
        if(null === $value || '' === $value) return true;
        return preg_match('/^[\x{4e00}-\x{9fa5}a-zA-Z0-9]+$/u', $value)?true:false;
    }

    # 字母、数字、下划线
    protected static function ens($value, array $data, ?array $args) : bool {
        if(null === $value || '' === $value) return true;
        return preg_match('/^[a-zA-Z0-9-_]+$/u', $value)?true:false;
    }

    # 数字(包含浮点和整数)验证
    protected static function number($value, $data, ?array $args) : bool {
        if(null === $value || ''=== $value) return true;
        return preg_match('/^[\+\-]{0,1}\d+(\.\d+){0,1}$/', strval($value)) ? true : false;
    }

    # 无符号数字(包含浮点和整数)验证
    protected static function unumber($value, $data, ?array $args) : bool {
        if(null === $value || ''=== $value) return true;
        return preg_match('/^\d+(\.\d+){0,1}$/', strval($value)) ? true : false;
    }

    # 整数验证
    protected static function integer($value, $data, ?array $args) : bool {
        if(null === $value || '' === $value) return true;
        return preg_match('/^[\+\-]{0,1}\d+$/', strval($value)) ? true : false;
    }

    # 无符号整数验证
    protected static function uinteger($value, $data, ?array $args) : bool {
        if(null === $value || '' === $value) return true;
        return preg_match('/\d+$/', strval($value)) ? true : false;
    }

    # 逻辑验证
    protected static function boolean($value, $data, ?array $args) : bool {
        if(empty($value)) return true; 
        return is_bool($value);
    }


    # 数组验证
    protected static function array($value, $data, ?array $args) : bool {
        if(empty($value)) return true; 
        return is_array($value);
    }

    # 列表验证
    protected static function list($value, $data, ?array $args) : bool {
        if(null === $value || ''=== $value) return true;
        if(is_array($value) && array_is_list($value)){
            if(isset($args[0])){
                $num = count($value); $arg1 = (int)$args[0];
                if(isset($args[1])){
                    $arg2 = (int)$args[1];
                    return $num >= min($arg1,$arg2) && $num <= max($arg1, $arg2);
                }
                return $num >= $arg1;
            }
            return true;
        }
        return false;
    }

    # 整数列表验证
    protected static function intlist($value, $data, ?array $args) : bool {
        if(null === $value || ''=== $value) return true;
        if(static::list($value, $data, $args)){
            foreach($value as $v){
                if(!is_integer($v)) return false;
            }
            return true;
        }
        return false;
    }

    #############

    private function check_(string $pfx, array $rules, array $data, bool $all) :array {
        $errMsg = [];
        foreach($rules as $key=>$rule){
            $val = isset($data[$key]) ? $data[$key] : null;
            $islist = false;
            # 遍历验证表达式
            if(isset($rule['exps'])){
                foreach($rule['exps'] as $exp){
                    @list($express, $args, $msg) = $exp;
                    if(!$bResult = static::$express($val, $data, $args ?? null)){
                        $errMsg[] = $msg ?? str_replace(':?', $rule['name'] ?? $pfx.$key, static::$messages[$express]);
                    }
                    if(!$all && !$bResult){
                        return $errMsg;
                    }
                    if($express ==='list') $islist = true;
                }
            }

            # 有递归验证规则.
            if(!empty($rule['childs'])){
                if($islist){
                    foreach($val ?? [] as $item){
                        $errMsg = array_merge($errMsg, $this->check_($pfx.$key.'.', $rule['childs'], is_array($item)? $item : [], $all));
                        if(!$all && !empty($errMsg)){
                            return $errMsg;
                        }
                    }
                }else{
                    if(is_array($val)){
                        $errMsg = array_merge($errMsg, $this->check_($pfx.$key.'.', $rule['childs'], $val, $all));
                        if(!$all && !empty($errMsg)){
                            return $errMsg;
                        }
                    }
                }
            }
        }
        return $errMsg;
    }

    #############

    /**
     * 对数据进行校验.
     * @access public
     * @param array $data 要验证的数据
     * @param bool $checkAll 是否验证全部规则, false 遇到不合法的停止验证. true 验证完直到最后一项.
     * @return Validator 返回验证器对象.
     */
    public function check(array $data, bool $checkAll = false) : Validator {

        $errMsg = $this->check_('', $this->rules, $data, $checkAll);
        $this->errMsg = $errMsg;
        return $this;
    }
    /**
     * 表单验证是否通过
     * @access public
     * @return bool true:验证通过 false:验证没通过
     */
    public function pass() : bool{
        return empty($this->errMsg);
    }

    /**
     * 获取第一个验证失败的消息
     * @access public
     * @return string 获取第一条验证失败消息.
     */
    public function getMessage() : ?string {
        return $this->errMsg[0] ?? null;
    }
}
