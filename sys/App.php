<?php
declare(strict_types=1);
/*
    框架入口文件
    作者: 龚辟愚
    时间: 2021-09-18
*/
namespace sys;

use Swoole\Coroutine;
use \Swoole\Coroutine\Barrier;
use Throwable;

class App {
    protected static int $workerId;

    public static function getWorkerId(): int {return static::$workerId;}

    /**
     * 在 创建工作进程之前执行
     */
    private static function beforeWorkerManagerCreate() : void
    {
        # 避免连接池污染子进程, 这里新开一个进程处理.
        $process = new \Swoole\Process(function(){
            if(false === Config::get('app.fields_lazy_cache', true)){
                //第一步: 初始化表结构.
                Helpers::CreateDataBaseStructCache();
            }
        }, false, 0, true);
        $process->start();
        $process->wait(true);
    }

    /**
     * 在工作进程创建之初执行.
     * @access private
     * @param string $module 模块名称
     * @param int $serverId 服务器ID
     * @param int $workerId 进程ID
     */
    private static function beforeWorkerStart(string $module, int $serverId, int $workerId):void {
        $barrier = Barrier::make();
        Coroutine::create(function () use($barrier, $module, $serverId, $workerId){
            try{
                $time = time();
                $class = "\\app\\{$module}\\BootStarup::onWorkerStart";
                call_user_func_array($class, [$serverId, $workerId]);
                $crontab = Config::get('crontab', []);
                foreach($crontab as $task){
                    if(!isset($task['workerId']) || $task['workerId'] == $workerId){
                        crontab($task['every'], $task['at'] ?? '', $task['exec'], $time);
                    }
                }
            }catch(Throwable $e) {
                Log::console(implode([$e->getMessage(),"\nFILE: ", $e->getFile(), "\nLINE: ", $e->getLine(),"\nTRACE: ", $e->getTraceAsString(),"\n"]),'ERROR');
            }
        });
        Barrier::wait($barrier);
    }

    /**
     * 在工作进程关闭之前之初执行.
     * @access private
     * @param string $module 模块名称
     * @param int $serverId 服务器ID
     * @param int $workerId 进程ID
     */
    private static function beforeWorkeShutdown(string $module, int $serverId, int $workerId):void {
        $barrier = Barrier::make();
        Coroutine::create(function () use($barrier, $module, $serverId, $workerId){
            try{
                $class = "\\app\\{$module}\\BootStarup::onWorkerShutdown";
                call_user_func_array($class, [$serverId, $workerId]);
            }catch(Throwable $e){
                Log::console(implode([$e->getMessage(),"\nFILE: ", $e->getFile(), "\nLINE: ", $e->getLine(),"\nTRACE: ", $e->getTraceAsString(),"\n"]),'ERROR');
            }
        });
        Barrier::wait($barrier);
    }

    /**
     * 应用程序入口
     * @access public
     * @return void
     */
    public static function execute()
    {
        $modules_conf = Config::get('app.modules');

        # 在这里执行系统启动之前的任务.
        Log::console('=== Server Start ===','DEBUG');
        static::beforeWorkerManagerCreate();

        # 允许使用原生函数
        \Swoole\Runtime::enableCoroutine(true, SWOOLE_HOOK_ALL | SWOOLE_HOOK_NATIVE_CURL);

        ini_set('serialize_precision', '15'); # json 浮点设置

        $pm = new \Swoole\Process\Manager();

        
        foreach($modules_conf as $module=>$config) {
            if($config['enabled']) {
                $pm->addBatch($config['worker_num'], function($pool, $workerId) use ($module, $config) {
                    $workerId += $config['worker_id'];
                    static::$workerId = $workerId;
                    # 指定工作进程的运行角色
                    if(!empty($config['user']))
                        Helpers::setUser($config['user']);
                    
                    # 启动日志模块
                    Log::start($workerId);

                    # 工作进程初始化函数.
                    static::beforeWorkerStart($module, $config['server_id'], $workerId);

                    # 启动服务器角色
                    $protocol = $config['protocol'];
                    $server = new $protocol($config, $module, $workerId);
                    
                    # 收到15信号关闭服务
                    \Swoole\Process::signal(SIGTERM, function () use($server, $module, $config, $workerId){
                        echo "=== Server Shutdown by SIGTERM\n";
                        SysEvents::DispathEvent('shutdown');
                        $server->shutdown();
                        static::beforeWorkeShutdown($module, $config['server_id'], $workerId);
                        \Swoole\Timer::clearAll(); //清理所有队列中的任务
                        Log::end();
                    });

                    # 收到13信号关闭服务 Ctrl + C
                    \Swoole\Process::signal(SIGINT, function () use($server, $module, $config, $workerId){
                        echo "=== Server Shutdown by Crtl+C\n";
                        SysEvents::DispathEvent('shutdown');
                        $server->shutdown();
                        static::beforeWorkeShutdown($module, $config['server_id'], $workerId);
                        \Swoole\Timer::clearAll(); //清理所有队列中的任务
                        Log::end();
                    });
                    # 启动服务
                    $server->start();
                }, true);
            }
        }
        $pm->start();
    }
}
