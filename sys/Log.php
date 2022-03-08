<?php
declare(strict_types=1);

namespace sys;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\System;

class Log {

    # 日志文件最大尺寸
    const MAX_LOG_FILE_SIZE =  4 * 1024 * 1024;
    # 每512kb 写入一次磁盘
    const MAX_MEM_CACHE_SIZE = 512 * 1024;

    static ?Channel $channel = null;
    static int $workerId;

    /**
     * 自动创建目录
     */
    protected static function dir($workerId):string{
        $dir = APP_ROOT.date("/\\v\\a\\r/\l\o\g_{$workerId}/Ym/");
        if(!is_dir($dir)){
            \mkdir($dir, 0777, true);
            chown($dir, "php");
            chgrp($dir, "www");
        }
        return $dir;
    }

    /**
     * 内存文件写入磁盘
     */
    protected static function flush($fp, $fpmem, int $iCacheSize) : ?int {
        if($iCacheSize > 0){
            rewind($fpmem);
            if(false !== ($content = fread($fpmem, $iCacheSize))){
                rewind($fpmem);
                if(false != ($numWrite = System::fwrite($fp, $content))){
                    return $numWrite;
                }
            }
            return null;
        }
        return 0;
    }

    protected static function console(string $logStr, string $level) : void {
        #字体颜色：30m-37m 黑、红、绿、黄、蓝、紫、青、白
        #背景颜色：40-47 黑、红、绿、黄、蓝、紫、青、白
        switch($level){
            case 'ERROR':
                $s = "\x1B[31m{$logStr}\x1B[0m\n";
                break;
            case 'WARN':
            case 'WARNING':
                $s = "\x1B[33m{$logStr}\x1B[0m\n";
                break;
            case 'SUCCESS':
                $s = "\x1B[32m{$logStr}\x1B[0m\n";
                break;
            case 'NOTICE':
            case 'SQL':
                $s = "\x1B[34m{$logStr}\x1B[0m\n";
                break;
            case 'DEBUG':
                $s = "\x1B[35m{$logStr}\x1B[0m\n";
                break;
            case 'CALL':
                $s = "\x1B[36m{$logStr}\x1B[0m\n";
                break;
            case 'INFO':
                $s = "\x1B[47;30m{$logStr}\x1B[0;0m\n";
                break;
            default:
                $s = $logStr."\n";
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
        $date = date('Y-m-d H:i:s');
        $cid = \Swoole\Coroutine::getCid() ?? '-';
        $logstr = "[{$date}][{$cid}][{$level}] {$msg}";
        
        # 如果是控制台模式 同时打印日志到控制台.
        IS_CLI && static::console($logstr, $level);

        static::$channel->push($logstr);
    }

    public static function start(int $workerId){

        # 日志写入协程
        static::$channel = new Channel(20);

        Coroutine::create(function($channel, int $workerId){
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
                while($logStr = $channel->pop()) {
                    if($channel->errCode === SWOOLE_CHANNEL_CLOSED)
                        break;

                    # 日期变了, 重开日志文件.
                    if($day !== ($d = date('d'))){
                        $day = $d;

                        static::flush($fp, $mem, $iCacheSize);
                        # 关闭旧的
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

                    if(false !== ($numWrite = fwrite($mem, $logStr))){
                        $iCacheSize += $numWrite;
                    }

                    # 内存缓冲区满了, 写入文件.
                    if($iCacheSize > static::MAX_MEM_CACHE_SIZE){
                        if(null !== ($numWrite = static::flush($fp, $mem, $iCacheSize))){
                            $iFileSize += $numWrite;
                            $iCacheSize -= $numWrite;
                        }else{
                            # TODO: 丢弃缓存
                        }
                    }

                    # 文件满了 重建文件.
                    if($iFileSize > static::MAX_LOG_FILE_SIZE){
                        fclose($fp);
                        $time = time();
                        rename($file, substr($file, 0, strlen($file) - 4) . "_{$time}.txt");

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
                }
            }
            if(false !== $mem && false !== $fp)
                static::flush($fp, $mem, $iCacheSize);
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
        static::$channel->close();
    }
}
