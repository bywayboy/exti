<?php
declare(strict_types=1);

namespace sys;

use Closure;
use Swoole\ConnectionPool;
use sys\exception\MemcachedException;

class Memcached {

    protected string $key;
    protected $pool;

    protected Closure $serialize;
    protected Closure $unserialize;

    # 连接池
    protected static array $conn_pool = [];

    /**
     * 创建|打开一个Memcached连接池.
     * @param string $host 主机IP地址
     * @param int $port 主机端口.
     * @param int $size 连接池的连接个数 第一次创建时有效.
     * @param ?Closure $serialize
     * @param ?Closure $unserialize
     */
    public function __construct(string $host, int $port = 11211, int $size = 16, ?Closure $serialize = null, ?Closure $unserialize = null)
    {
        # 兼容 php_memcached.so 扩展的序列化方法
        $this->serialize = $serialize ?? function(mixed $value, int &$flags) : string {
            $type = gettype($value);
            switch($type){
            case 'string':
                $flags = 0;
                return $value;
            case 'integer':
                $flags = 1;
                return $value;
            case 'double':
                $flags = 2;
                return $value;
            case 'boolean':
                $flags = 3;
                return $value?1:0;
            default:
                $flags = 4;
                return serialize($value);
            }
        };

        $this->unserialize = $unserialize ?? function(int $flag, string $value){
            #echo "FLAG={$flag}, VALUES={$value}\n";
            switch($flag){
            case 0:
                return $value;
            case 1:
                return intval($value);
            case 2:
                return floatval($value);
            case 3:
                return $value=='1'?true:false;
            default:
                return unserialize($value);
            }
        };

        $this->key = "{$host}:{$port}";
        $this->pool = static::$conn_pool[$this->key] ?? null;
        if(null === $this->pool){
            $this->pool = static::$conn_pool[$this->key] = new ConnectionPool(function() use($host, $port){
                $client = new \Swoole\Coroutine\Client(SWOOLE_SOCK_TCP);
                $client->set(array(
                    'open_eof_check'=>true,
                    'package_eof'=>"\r\n"
                ));
                if(false === $client->connect($host, $port)){
                    Log::write("Memcached: 连接到: {$this->key} 失败!", 'ERROR');
                }
                return $client;
            }, $size);
        }
    }

    protected function store(string $cmd, string $key, mixed $value, int $expire = 0) : bool {
        $flags = 0;
        $value = ($this->serialize)($value, $flags);
        $len = strlen($value);

        $cmd = "{$cmd} {$key} {$flags} {$expire} {$len}\r\n{$value}\r\n";

        $conn = $this->pool->get();
        if(strlen($cmd) === $conn->send($cmd)){
            $ret = $conn->recv(3.0);
            if($ret){
                # STORED NOT_STORED
                return $ret === 'STORED';
            }
        }
        $conn->close();
        $this->pool->put(null);
        throw new MemcachedException("连接丢失", 1);
    }

    /**
     * 读取一项数据.成功返回 数据内容
     * @param string $key 键名
     * @return mixed 成功返回数据, 失败返回 null
     */
    public function get(string $key) : mixed {

        $cmd = "get {$key}\r\n";
        $cmdlen = strlen($cmd);
        $conn = $this->pool->get();
        if($cmdlen === ($sret = $conn->send($cmd))){
            if(false !== $result = $conn->recv()){
                @list($stat, $key, $flags, $bytes) =  explode(' ', $result);
                if($stat === 'VALUE'){
                    $recived = 0;
                    while(true){
                        $r = $conn->recv();
                        if(false == $r)
                            break;

                        $payload[] = $r;
                        $recived += strlen($r);
                        if($recived >= $bytes + 5){ # 数据以 END\r\n 结束 因此需增加5个 byte
                            $this->pool->put($conn);
                            return ($this->unserialize)(intval($flags), substr(implode('', $payload), 0, intval($bytes)));
                        }
                    }
                }else{
                    $this->pool->put($conn);
                    return null;
                }
            }
        }
        $conn->close();
        $conn = null;
        $this->pool->put($conn);
        throw new MemcachedException("连接丢失", 1);
    }

    /**
     * 设置一项数据, 不管是否存在
     * @param string $key 键名
     * @param mixed $data 数据
     * @param int $expire 过期时间
     * @return bool 成功返回 true 失败返回 false 
     */
    public function set(string $key, mixed $data, int $expire = 0) : bool {
        return $this->store('set', $key, $data, $expire);
    }

    /**
     * 添加一项数据 如果已经存在 返回 false
     * @param string $key 键名
     * @param mixed $data 数据
     * @param int $expire 过期时间
     * @return bool 成功返回 true 失败返回 false 
     */
    public function add(string $key, mixed $data, int $expire = 0) : bool {
        return $this->store('add', $key, $data, $expire);
    }

    /**
     * 替换一项数据, 如果数据不存在 返回 false, 如果出错会抛出一个异常.
     * @param string $key 键名
     * @param mixed $data 数据
     * @param int $expire 过期时间
     * @return bool 成功返回 true 数据不存在返回false 
     */
    public function replace(string $key, mixed $data, int $expire = 0) : bool {
        return $this->store('replace', $key, $data, $expire);
    }

    /**
     * 删除一项数据
     * @access public 
     * @param string $key 要删除的数据的键值
     */
    public function del(string $key) : bool {
        $cmd = "delete {$key}\r\n";
        $conn = $this->pool->get();
        if(strlen($cmd) === $conn->send($cmd)){
            if(false !== $ret = $conn->recv()){
                # DELETED NOT_FOUND
                $this->pool->put($conn);
                return $ret === 'DELETED';
            }
        }
        $conn->close();
        $this->pool->put(null);
        throw new MemcachedException("连接丢失", 1);
    }
}
