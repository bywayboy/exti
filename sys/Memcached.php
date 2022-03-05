<?php
declare(strict_types=1);

namespace sys;

use Swoole\ConnectionPool;
use sys\exception\MemcachedException;

class Memcached {

    protected string $key;
    protected $pool;
    # 连接池
    protected static array $conn_pool = [];

    /**
     * 创建|打开一个Memcached连接池.
     * @param string $host 主机IP地址
     * @param int $port 主机端口.
     * @param int $size 连接池的连接个数 第一次创建时有效.
     */
    public function __construct(string $host, int $port = 11211, int $size = 16)
    {
        $this->key = "{$host}:{$port}";
        $this->pool = static::$conn_pool[$this->key] ?? null;
        
        if(null === $this->pool){
            $this->pool = static::$conn_pool[$this->key] = new ConnectionPool(function() use($host, $port){
                $client = new \Swoole\Coroutine\Client(SWOOLE_SOCK_TCP);
                $client->set(array(
                    'open_eof_check'=>true,
                    'package_eof'=>"\r\n"
                ));
                echo "connect memcached: " . $client->connect($host, $port) . "\n";
                return $client;
            }, $size);
        }
    }

    protected function store(string $cmd, string $key, mixed $value, int $expire = 0) : bool {
        $value = serialize($value);
        $len = strlen($value);

        $cmd = "{$cmd} {$key} 0 {$expire} {$len}\r\n{$value}\r\n";

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
     * 读取一项数据.
     */
    public function get(string $key) : mixed {
        $conn = $this->pool->get();

        $cmd = "get {$key}\r\n";
        $cmdlen = strlen($cmd);
        if($cmdlen === $conn->send($cmd)){
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
                        if($recived >= $bytes){
                            $this->pool->put($conn);
                            return unserialize(substr(implode('', $payload), 0, intval($bytes)));
                        }
                    }
                }else{
                    $this->pool->put($conn);
                    return false;
                }
            }
        }
        $conn->close();
        $conn = null;
        $this->pool->put($conn);
        throw new MemcachedException("连接丢失", 1);
    }

    public function set(string $key, mixed $data, int $expire = 0) : bool {
        return $this->store('set', $key, $data, $expire);
    }

    public function add(string $key, mixed $data, int $expire = 0) : bool {
        return $this->store('add', $key, $data, $expire);
    }

    public function replace(string $key, mixed $data, int $expire = 0) : bool {
        return $this->store('replace', $key, $data, $expire);
    }

    /**
     * 删除一项数据
     * @access public 
     * @param string $key 要删除的数据的键值
     */
    public function del(string $key) : bool {
        $cmd = "del {$key}\r\n";
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
