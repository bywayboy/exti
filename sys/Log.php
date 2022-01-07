<?php
declare(strict_types=1);
/**
 * 日志类
 * 作者: 龚辟愚
 * 日期: 2021-09-18
 */

namespace sys;

define('MAX_LOG_FILE_SIZE', 2 * 1024 * 1024); #2M

class Log {
    static $fpmem = false;   //内存缓存句柄
    static $fp = false;      //日志文件句柄
    static $logCacheSize = 0;
    static $logFileSize  = 0;   //日志文件大小
    static $workerId = false;

    /**
     * 1. 文件是否达到2M 达到了重新创建一个文件.
     */
    private static function prepare()
    {
        $workerId = static::$workerId;
        $dir = APP_ROOT.date("/\\v\\a\\r/\l\o\g_{$workerId}/Ym/"); //得到当前目录.
        
        if(!is_dir($dir)){
            \mkdir($dir, 0777, true);
            chown($dir, "php");
            chgrp($dir, "www");
        }

        $file = date("d.\\tx\\t");

        if(false !== static::$fp){
            $time = time();
            $newfile = date("d_{$time}.\\tx\\t");
            fclose(static::$fp);
            rename($dir.$file, $dir.$newfile);
        }

        static::$fp = fopen($dir.$file, 'a+');

        static::$logFileSize = \ftell(static::$fp);
        if(false == static::$fp) return null;
        return static::$fp;
    }

    /**
     * 本次请求的所有日志写入磁盘
     **/
    public static function flush() : void
    {
        if(false !== static::$fpmem){
            rewind(static::$fpmem); //指向文件头

            //缓存全部写入文件
            $numWrite = stream_copy_to_stream(static::$fpmem, static::$fp, static::$logCacheSize);
            static::$logCacheSize = 0;
            static::$logFileSize += $numWrite;

            #文件满了, 重新创建日志文件
            if(static::$logFileSize >= MAX_LOG_FILE_SIZE){
                static::prepare();
            }
            rewind(static::$fpmem); //指向文件头
        }
    }

    /**
     * 开始记录日志
     */
    public static function start(string $workerId) : void
    {
        static::$workerId = $workerId;
        static::$fpmem = fopen('php://memory', 'w+');
        if(false == static::$fpmem){
            echo "init log failed!";
        }
        static::prepare(); //创建好一个日志文件.
    }

    /**
     * 关闭日志
     */
    public static function close() : void
    {
        static::flush();
        if(false !== static::$fpmem);
            \fclose(static::$fpmem);
        
        if(false !== static::$fp)
            \fclose(static::$fp);
        static::$fpmem = static::$fp = false;
    }

    public static function write($msg = '', $module='default', $level='INFO'):void
    {
        if(false === static::$fpmem){
            if(false === static::$workerId){
                return;
            }
            static::start(static::$workerId);
        }

        if(is_array($msg) || is_object($msg)){
            $msg = json_encode($msg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }
        $date = date('Y-m-d H:i:s');
        $logstr = "[{$date}] [{$module}] [{$level}]: {$msg}\n";

        if(DEBUG_MODE == true) echo $logstr;
        static::$logCacheSize += fwrite(static::$fpmem, $logstr);

        $totalSize = static::$logFileSize + static::$logCacheSize;

        //达到2M 写入文件
        if($totalSize >= MAX_LOG_FILE_SIZE){
            static::flush(true);
        }
    }
}