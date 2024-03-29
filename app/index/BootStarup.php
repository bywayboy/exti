<?php
declare(strict_types=1);

namespace app\index;

use sys\Log;

class BootStarup {
    public static int $start_time;
    /**
     * 当工作进程启动的时候执行该方法.
     * @param int $serveId 服务器ID
     * @param int $workerId 工作进程ID: 0~n
     */
    public static function onWorkerStart(int $serverId, int $workerId) : void
    {
        static::$start_time = time();

        
        echo "Worker BootStrap {$serverId}, {$workerId}\n";
        //Log::write("log write ... test", 'INFO');
        //Log::write("log write ... test", 'ERROR');
        //Log::write("log write ... test", 'SQL');
    }

    /**
     * 当工作进程退出的时候执行该方法.
     * @param int $serverId 服务器ID
     * @param int $workerId 工作进程ID: 0~n
     */
    public static function onWorkerShutdown(int $serverId, int $workerId): void
    {
        echo "Worker Stop {$serverId}, {$workerId}\n";
    }
}
