<?php
declare(strict_types=1);

namespace sys;

use Swoole\Coroutine;
use Swoole\Timer;
use sys\exception\CrontabException;
use Throwable;

/**
 * 定时任务
  */
class CrontabTask {

    protected static array $jobs = [];

    protected int $timerid = 0;
    
    public function __construct(int $time, string $every, string $at, callable $exec)
    {
        $at = $at ?? '';
        # 解析 every
        if(preg_match('#^(\d+)(\w)$#', $every, $matched)){
            $dt   = $matched[1];
            $unit = $matched[2];
            if($dt < 0){
                Log::write('创建计划任务失败! 间隔不能小于1.', 'Crontab','ERROR');
                throw new CrontabException("创建计划任务失败! 间隔不能小于1.", 1);
                return;
            }

            switch($unit){
            case 'y':
                $this->CreateDateJob("+$dt year", $at, $exec);
                break;
            case 'm':
                $this->CreateDateJob("+$dt month", $at, $exec);
                break;
            case 'd':
                $this->CreateDateJob("+$dt day", $at, $exec);
                break;
            case 'w':
                $ati = static::parseWeekAt($time, $at);
                $this->CreateSecondJob((86400 * 7) * $dt, $ati, $exec);
                break;
            case 'h':
                $ati = static::parseAt($time, $at);
                $this->CreateSecondJob(intval(3600 * $dt), $ati, $exec);
                break;
            case 'u':
                $ati = static::parseAt($time, $at);
                $this->CreateSecondJob(intval(60 * $dt), $ati, $exec);
                break;
            case 's':
                $ati = static::parseAt($time, $at);
                $this->CreateSecondJob(intval($dt), $ati, $exec);
                break;
            default:
                Log::write('创建计划任务失败! 无法识别的间隔单位:'.$unit, 'Crontab','ERROR');
                throw new CrontabException("解析间隔指令失败, 无法识别的间隔单位.", 2);
                break;
            }
            static::$jobs[spl_object_id($this)] = $this;
            return;
        }
        throw new CrontabException("解析every指令失败.", 2);
    }

    /**
     *  停止这个任务
     */
    public function stop() : void {
        Timer::clear($this->timerid);
        $this->timerid = 0;
        unset(static::$jobs[spl_object_id($this)]);
    }


    /**
     * 解析得到执行具体时间
     */
    private static function parseAt(int $time, string $at) : int {
        $base = date('Y-m-d H:i:s', $time);
        $haven = strlen($at);
        $at = $haven < 19 ? substr($base, 0, 19 - $haven) . $at : $at;
        return strtotime($at);
    }

    /**
     * 解析星期时间偏移量 0~6, hh::mm:ss
     */
    private static function parseWeekAt(int $time, string $at) : int {
        if(empty($at)){
            return $time;
        }

        $cdate = date('Y-m-d H:i:s', $time);
        #例如: 0 15:00:00  周一 15:00:00执行

        if(preg_match('#([0~6])\s(\d{2}):(\d{2}):(\d{2})$#', $at, $matches)){
            $ttime = strtotime(substr($cdate, 0, 10) . " {$matches[2]}:{$matches[3]}:{$matches[4]}");

            $tweek = intval(date('w', $time));
            $cweek = intval($matches[1]);
            $days = $tweek < $cweek ? $cweek - $tweek + 7 : $cweek - $tweek;
            
            # 不在同一天, 往后移动 $days 天
            if($days > 0){
                $ttime = strtotime("+{$days} day", $ttime);
            }elseif($ttime < $time){
                # 同一天, 单时间再当日之前, 往后移动一周
                $ttime += (86400 * 7);
            }
            return $ttime;
        }
        return $time;
    }

    /**
     * 创建秒级定时任务
     * @param string $every 执行间隔
     * @param string $at 具体执行时间
     * @param callable $callable 任务执行函数
     * @return void
     */
    protected function CreateDateJob(string $every, string $at, callable $callable) :void {
        
        $closure = function (int $time, \Closure $closure, string $every, callable $callable){
            # 准备下一个任务
            $next = strtotime($every, $time);
            Log::write("创建计划任务: 间隔:{$every}, 执行时间:". date('Y-m-d H:i:s', $next), 'Crontab', 'INFO');
            $this->timerid = Timer::after(max(1000 * ($next - $time), 0), $closure, $next, $closure, $every, $callable);
            # 执行当前任务
            Coroutine::create(function(int $time) use($callable) : void {
                try{
                    call_user_func_array($callable, [$time]);
                }catch(Throwable $e){
                    Log::write("执行计划任务出错:", $e->getMessage()."\n".$e->getTraceAsString(),'Crontab', 'ERROR');
                }
            }, $time);
        };


        $time = time() + 10;
        $at = static::parseAt($time, $at);  # 获取执行时间
        
        while($at < $time){
            $at = strtotime($every, $at);
        }
        $this->timerid = Timer::after(1000 * ($at - $time), $closure, $at, $closure, $every, $callable);
        Log::write("创建计划任务: 间隔:{$every}, 执行时间:". date('Y-m-d H:i:s', $at), 'Crontab', 'INFO');
    }
    
    /**
     * 创建秒级定时任务
     * @param int $every 执行间隔
     * @param int $at 具体执行时间
     * @param callable $callable 任务执行函数
     * @return void
     */
    protected function CreateSecondJob(int $every, int $at, callable $callable) : void{
        $closure = function (int $time, \Closure $closure, int $every, callable $callable){
            # 准备下一个任务
            $next = $time + $every;
            Log::write("创建计划任务: 间隔:{$every}, 执行时间:". date('Y-m-d H:i:s', $next), 'Crontab', 'INFO');
            $this->timerid = Timer::after(max(0, 1000 * ($next - $time)), $closure, $next, $every, $callable);
            # 执行当前任务
            Coroutine::create(function(int $time) use($callable) : void {
                try{
                    call_user_func_array($callable, [$time]);
                }catch(Throwable $e){
                    Log::write("执行计划任务出错:", $e->getMessage()."\n".$e->getTraceAsString(),'Crontab', 'ERROR');
                }
            }, $time);
        };

        $time = time();
        while($at < $time){
            $at += $every;
        }
        Log::write("创建计划任务: 间隔:{$every}, 执行时间:". date('Y-m-d H:i:s', $at), 'Crontab', 'INFO');
        $this->timerid = Timer::after(1000 * ($at - $time), $closure, $at, $closure, $every, $callable);
    }
}
