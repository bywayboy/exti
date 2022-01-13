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

    protected $messages = [
        'require'=>':?为必填项.',
        'number'=>':?必须为数字.',
        'mobile'=>':?手机号码格式错误.',
        'idcard'=>':?证件号码格式错误.',
        'zip'=>':?邮政编码格式错误.',
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
        'requireIfIn'=>':?为必填项.',
        'array'=>':?必须是数组',
        'url'=>':?必须是一个URL地址.',
        'email'=>':?邮箱地址无效.'
    ];

    protected static $cache = [];

    private static function _newrule(array &$rules, string $skey, ?string $name, array $exps) {
        $keys = explode('.', $skey);

        # 设置节点
        foreach($keys as $i=>$key){
            if($i == 0){
                if(!isset($rules[$key])){
                    $rules[$key] = [];
                }
                $rules = &$rules[$key];
            }else{
                if(!isset($rules['child'][$key])){
                    $rules['childs'][$key] = [];
                }
                $rules = &$rules['childs'][$key];
            }
        }
        $rules += ['exps'=>$exps, 'name'=>$name];
    }
/* 生成结构:
    [
        'username'=>[
            'exps=>[
                ['require', args:[]]
            ],
        ]
    ]
*/
    public function __construct(array $rules, ?string $CacheKey_ = null)
    {
        if($CacheKey_ && isset(static::$cache[ $CacheKey_ ])){
            //echo "命中缓存: {$CacheKey_}\n";
            $this->rules = static::$cache[ $CacheKey_ ];
        } else {
            # 第一步 parse rules
            $xrules = [];
            foreach($rules as $key=>$rule){
                @list($key, $name) = explode('|', $key, 2);
                
                $expressions = explode('|', $rule);
                $xexps = [];
                foreach($expressions as $expression){
                    $parts = explode(':', $expression,3); # 限制分割3次  规则:参数... :消息
                    if(count($parts) > 1){
                        $parts[1] = explode(',', $parts[1]);
                    }
                    $xexps[] = $parts;
                }
                static::_newrule($xrules, $key, $name, $xexps);
            }
            echo "生成规则表:".json_encode($xrules, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
            $this->rules = $xrules;
            if(null !== $CacheKey_){
                static::$cache[ $CacheKey_ ] = $xrules;
                //echo "缓存规则表: {$CacheKey_} \n";
            }
        }
    }

    public function jsonSerialize() : array  {
        return empty($this->errMsg) ? ['success'=>true, 'done'=>true, 'message'=>'表单验证通过.'] : ['success'=>false, 'done'=>true, 'message'=>$this->errMsg[0], 'extra'=>$this->errMsg];
    }

    # >>> 验证规则开始.
    protected static function require($value, array $data, ?array $args) : bool{
        if(null === $value || (is_string($value) && trim($value) === ''))
            return false;
        return true;
    }

    # 用法: requireIfIn:wanjia,weixin,alipay,mode  当 mode 为 wanjia,weixin,alipay 时 必填
    protected static function requireIfIn($value, array $data, ?array $args) : bool {
        $field = array_pop($args);
        if(in_array($data[$field] ?? null, $args)){
            return !empty($value);
        }
        return true;
    }

    protected static function in($value, array $data, array $args): bool
    {
        if(null == $value || '' === $value) return true;
        return in_array($value, $args);
    }

    protected static function between($value, array $data, array $args): bool
    {
        if(null == $value || '' === $value) return true;
        return $value >=$args[0] && $value <= $args[1];
    }

    protected static function mobile($value, array $data, ?array $args) : bool{
        if(null === $value || '' === $value) return true;
        return preg_match('/^1[3-9]\d{9}$/', $value)?true:false;
    }

    protected static function zip($value, array $data, ?array $args): bool {
        if(null == $value || '' == $value) return true;
        return preg_match('/\d{6}/', $value)?true:false;
    }

    protected static function url($value, array $data, ?array $args):bool{
        if(null == $value || '' == $value) return true;
        return filter_var($value, FILTER_VALIDATE_URL) ?true:false;
    }
    protected static function email($value, array $data, ?array $args) : bool{
        if(null == $value || '' == $value) return true;
        return filter_var($value, FILTER_VALIDATE_EMAIL) ?true:false;
    }

    protected static function min($value, array $data, ?array $args): bool{
        if(null == $value || '' == $value) return true;
        return $value >= $args[0];
    }

    protected static function max($value, array $data, ?array $args): bool{
        if(null == $value || '' == $value) return true;
        return $value <= $args[0];
    }

    # 正则表达式验证
    protected static function regex($value, array $data, ?array $args) : bool {
        if(null == $value || '' == $value) return true;
        return preg_match($args[0], strval($value))?true:false;
    }

    # 求余=0验证
    protected static function mod($value, array $data, ?array $args):bool{
        if(null == $value || '' == $value) return true;
        return fmod(floatval($value), floatval($args[0])) == 0.0;
    }

    # 最小长度限制验证.
    protected static function minlength($value, array $data, ?array $args):bool{
        if(null == $value || '' == $value) return true;
        return strlen(trim(strval($value))) >= $args[0];
    }

    # 最大长度限制验证
    protected static function maxlength($value, array $data, ?array $args):bool{
        if(null == $value || '' == $value) return true;
        return strlen(trim(strval($value))) <= $args[0];
    }

    # 长度范围验证
    protected static function length($value, array $data, ?array $args):bool{
        if(null == $value || '' == $value) return true;
        $l = strlen(trim(strval($value)));
        return  $l >= $args[0] && $l <= $args[1];
    }

    # 比较字段验证
    protected static function confirm($value, array $data, ?array $args):bool {
        return $value === $data[ $args[0] ];
    }

    # 身份证有效性验证.
    protected static function idcard($value, $data, ?array $args): bool {
        if(null == $value || '' == $value) return true;
        return preg_match('/(^[1-9]\d{5}(18|19|([23]\d))\d{2}((0[1-9])|(10|11|12))(([0-2][1-9])|10|20|30|31)\d{3}[0-9Xx]$)|(^[1-9]\d{5}\d{2}((0[1-9])|(10|11|12))(([0-2][1-9])|10|20|30|31)\d{3}$)/', strval($value))?true:false;
    }

    # 数字(包含浮点和整数)验证
    protected static function number($value, $data, ?array $args) : bool {
        if(null == $value || ''==$value) return true;
        return preg_match('/^\d+(\.\d+){0,1}$/', strval($value)) ? true : false;
    }

    # 整数验证
    protected static function integer($value, $data, ?array $args) : bool {
        if(null == $value || '' == $value) return true;
        return preg_match('/^\d+$/', strval($value)) ? true : false;
    }
    # 数组验证
    protected static function array($value, $data, ?array $arggs) : bool {
        if(null == $value || '' == $value) return true;
        return is_array($value);
    }

    private function check_(string $pfx, array $rules, array $data, bool $all) :array {
        $errMsg = [];
        foreach($rules as $key=>$rule){
            $val = $data[$key] ?? null;

            # 遍历验证表达式
            foreach($rule['exps'] as $exp){
                @list($express, $args, $msg) = $exp;
                if(!$bResult = static::$express($val, $data, $args ?? null)){
                    $errMsg[] = $msg ?? str_replace(':?', $rule['name'] ?? $pfx.$key, $this->messages[$express]);
                }
                if(!$all && !$bResult){
                    return $errMsg;
                }
            }

            # 有递归验证规则.
            if(!empty($rule['childs'])){
                $errMsg = array_merge($errMsg, $this->check_($pfx.$key.'.', $rule['childs'], $val ?? [], $all));
            }
        }
        return $errMsg;
    }

    #############

    /**
     * 对数据进行校验.
     * @param array $data 要验证的数据
     * @param bool $checkAll 是否验证全部规则, false 遇到不合法的停止验证. true 验证完直到最后一项.
     * @return Validator 返回验证器对象.
     */
    public function check(array $data, bool $checkAll = false) : Validator {

        $errMsg = $this->check_('', $this->rules, $data, $checkAll);
        $this->errMsg = $errMsg;
        return $this;
    }
    public function pass() : bool{
        return empty($this->errMsg);
    }
    public function getMessage() : ?string {
        return $this->errMsg[0] ?? null;
    }
}
