<?php
declare(strict_types=1);

namespace sys;

use Swoole\Coroutine\WaitGroup;

class SysEvents {
    protected static $ShutdownEvents = [];

    public static function bind($obj, string $event, string $method)
    {
        switch($event){
        case 'shutdown':
            break;
        default:
            return;
        }
        $id = spl_object_id($obj);
        static::$ShutdownEvents[$event][$id] = [$obj, $method];
    }

    public static function unbind($obj, string $event)
    {
        $id = spl_object_id($obj);
        unset(static::$ShutdownEvents[$event][$id]);
    }

    /**
     * 分发事件
     */
    public static function DispathEvent(string $event) : void
    {
        foreach(static::$ShutdownEvents[$event] ?? [] as $EventList)
        {
            foreach($EventList as $item)
            {
                call_user_func_array($item[0], $item[1], []);
            }
        }
    }
}
