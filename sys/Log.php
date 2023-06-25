<?php
declare(strict_types=1);

namespace sys;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

class Log {

    # 日志文件最大尺寸
    const MAX_LOG_FILE_SIZE =  10240000;    # 10M
    # 每512kb 写入一次磁盘
    const MAX_MEM_CACHE_SIZE = 512000;      # 512k
    # 日志队列最大长度
    const MAX_QUEUE_SIZE    = 32;
    # 日志文件至少3分钟写一次盘
    const LOG_CACHE_TIMEOUT = 180;

    static ?Channel $channel = null;
    static int $workerId;

    /**
     * 自动创建目录
     */
    protected static function dir($workerId):string{
        $sWorkderId = str_pad((string)$workerId, 2, '0', STR_PAD_LEFT);
        $dir = APP_ROOT.date("/\\v\\a\\r/\l\o\g/{$sWorkderId}/Ym/");
        if(!is_dir($dir)){
            \mkdir($dir, 0777, true);
            # chown($dir, "php");
            # chgrp($dir, "www");
        }
        return $dir;
    }

    /**
     * 内存文件写入磁盘
     */
    protected static function toStorage($fp, $fpmem, int $iCacheSize) : ?int {
        
        if($iCacheSize > 0){
            /*
            rewind($fpmem);
            if(false !== ($content = fread($fpmem, $iCacheSize))){
                rewind($fpmem);
                if(false != ($numWrite = System::fwrite($fp, $content))){
                    return $numWrite;
                }
            }
            */
            rewind($fpmem);
            if(false !== ($numWrite = stream_copy_to_stream($fpmem, $fp, $iCacheSize))){
                rewind($fpmem);
                return $numWrite;
            }
            return null;
        }
        return 0;
    }

    /**
     * 内存日志立即写盘
     */
    public static function flush() {
        null !== static::$channel && static::$channel->push(0);
    }

    public static function console(string $logStr, string $level) : void {
        #字体颜色：30m-37m 黑、红、绿、黄、蓝、紫、青、白
        #背景颜色：40-47 黑、红、绿、黄、蓝、紫、青、白
        $date = date('Y-m-d H:i:s');
        $cid = \Swoole\Coroutine::getCid() ?? '-';
        switch($level){
            case 'ERROR':
                $s = "\x1B[31m[{$date}][{$cid}][{$level}] {$logStr}\x1B[0m\n";
                break;
            case 'WARN':
            case 'WARNING':
                $s = "\x1B[33m[{$date}][{$cid}][{$level}] {$logStr}\x1B[0m\n";
                break;
            case 'SUCCESS':
                $s = "\x1B[32m[{$date}][{$cid}][{$level}] {$logStr}\x1B[0m\n";
                break;
            case 'NOTICE':
            case 'SQL':
                $s = "\x1B[34m[{$date}][{$cid}][{$level}] {$logStr}\x1B[0m\n";
                break;
            case 'DEBUG':
                $s = "\x1B[35m[{$date}][{$cid}][{$level}] {$logStr}\x1B[0m\n";
                break;
            case 'CALL':
                $s = "\x1B[36m[{$date}][{$cid}][{$level}] {$logStr}\x1B[0m\n";
                break;
            case 'INFO':
                $s = "\x1B[37m[{$date}][{$cid}][{$level}] {$logStr}\x1B[0m\n";
                break;
            default:
                $s = "[{$date}][{$cid}][{$level}] $logStr"."\n";
                break;
            }
            echo $s;
    }
    /**
     * 打印日志
     * @param string $msg 日志内容
     */
    public static function write(string $msg, string $level='INFO') : void
    {
        if(null === static::$channel || static::$channel->isFull()){
            return;
        }
        # 如果是控制台模式 同时打印日志到控制台.
        IS_CLI && static::console($msg, $level);

        $date = date('Y-m-d H:i:s');
        $cid = \Swoole\Coroutine::getCid() ?? '-';
        $logstr = "[{$date}][{$cid}][{$level}] {$msg}";

        null !== static::$channel && static::$channel->push($logstr . PHP_EOL);
    }

    public static function start(int $workerId){

        # 日志写入协程
        static::$channel = new Channel(static::MAX_QUEUE_SIZE);

        Coroutine::create(function($channel, int $workerId) {
            $mem = fopen('php://memory', 'w+');
            if(false === $mem){
                echo "[ERROR] [{$workerId}] 打开内存缓存失败, 日志功能将被关闭!\n";
            }
            $day = date('d');

            $iFileSize = $iCacheSize = 0;
            $file = static::dir($workerId).date('d.\tx\t');

            if(false !== ($fp = fopen($file, 'a+'))){
                $iFileSize = \ftell($fp);
                $iCacheSize = max(static::MAX_MEM_CACHE_SIZE - (static::MAX_LOG_FILE_SIZE - $iFileSize), 0);
            }else{
                echo "[ERROR] [{$workerId}] 创建日志文件失败, 日志功能将被关闭!\n";
                $channel->close();
                static::$channel = $channel = null;
            }

            if(null !== $channel){
                while(true) {
                    $logStr = $channel->pop(static::LOG_CACHE_TIMEOUT);
                    if($channel->errCode === SWOOLE_CHANNEL_CLOSED)
                        break;

                    # 如果满足2个条件: 
                    #       1. 内存满了, 
                    #       2. 超时了 且有内存数据内存数据写盘
                    if($iCacheSize >= static::MAX_MEM_CACHE_SIZE || ($channel->errCode == SWOOLE_CHANNEL_TIMEOUT && $iCacheSize > 0)) {
                        if(null !== ($numWrite = static::toStorage($fp, $mem, $iCacheSize))){
                            $iFileSize += $numWrite;
                            $iCacheSize -= $numWrite;
                        }
                    }

                    $d = date('d');
                    # 日期变了, 或者文件满了. 重开日志文件.
                    if($iFileSize >= static::MAX_LOG_FILE_SIZE || $day !== $d){
                        $day = $d;

                        # 关闭旧的,并重命名
                        fclose($fp);
                        $time = time();
                        rename($file, substr($file, 0, strlen($file) - 4) . "_{$time}.txt");

                        # 创建新的
                        $file = static::dir($workerId).date('d.\tx\t');
                        if(false !== ($fp = fopen($file, 'a+'))){
                            $iFileSize = \ftell($fp);
                        }else{
                            echo "[ERROR] [{$workerId}] 创建日志文件失败, 日志功能将被关闭!\n";
                            $channel->close();
                            static::$channel = null;
                            break;
                        }
                    }

                    # 写入磁盘
                    if(is_string($logStr)) {
                        if(false !== ($numWrite = fwrite($mem, $logStr))) {
                            $iCacheSize += $numWrite;
                        }
                    } elseif(is_int($logStr)) {
                        switch($logStr) {
                        case 0: # 外部控制指令, 要求立即将日志写入磁盘.
                            # echo "[LOG] 控制指令要求写盘 {$iCacheSize}.\n";
                            if($iCacheSize > 0){
                                if(null !== ($numWrite = static::toStorage($fp, $mem, $iCacheSize))) {
                                    $iFileSize += $numWrite;
                                    $iCacheSize -= $numWrite;

                                    if($iFileSize >= static::MAX_LOG_FILE_SIZE){
                                        # 关闭旧的,并重命名
                                        fclose($fp);
                                        $time = time();
                                        rename($file, substr($file, 0, strlen($file) - 4) . "_{$time}.txt");

                                        # 创建新的
                                        $file = static::dir($workerId).date('d.\tx\t');
                                        if(false !== ($fp = fopen($file, 'a+'))) {
                                            $iFileSize = \ftell($fp);
                                        } else {
                                            echo "[ERROR] [{$workerId}] 创建日志文件失败, 日志功能将被关闭!\n";
                                            $channel->close();
                                            static::$channel = null;
                                            break;
                                        }
                                    }
                                }
                            }
                            break;
                        }
                    }
                }
            }
            if(false !== $mem && false !== $fp && $iCacheSize > 0){
                # echo "写出内存日志... {$iCacheSize}字节\n";
                static::toStorage($fp, $mem, $iCacheSize);
            }

            if(false !== $mem)
                fclose($mem);
            if(false !== $fp)
                fclose($fp);
        }, static::$channel, $workerId);
    }


    /**
     * 停止记录日志
     */
    public static function end(){
        if(null !== static::$channel){
            while(!static::$channel->isEmpty()){
                Coroutine::sleep(.1);
            }
            static::$channel->close();
        }
    }
}
