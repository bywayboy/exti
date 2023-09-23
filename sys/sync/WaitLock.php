<?php
namespace sys\data;

trait WaitLock {
    private int $_cid_ = -1;
    private int $_wait_counter = 0;     # 等待计数器
    protected ?\Swoole\Coroutine\Channel $_wait_lock_channel_ = null;
 
    /**
     * 防并发锁
     * 
     */
    public function waitLock() :static {
        $cid = \Swoole\Coroutine::getCid();

        if($cid === $this->_cid_){
            return $this;
        }

        if($this->_cid_ !== -1){
            $this->_wait_counter++;
            if(null === $this->_wait_lock_channel_){
                $this->_wait_lock_channel_ = new \Swoole\Coroutine\Channel(1);
            }
            $this->_wait_lock_channel_->pop();
            $this->_wait_counter--;
            
            if(0 == $this->_wait_counter){
                $this->_wait_lock_channel_->close();
                $this->_wait_lock_channel_ = null;
            }
        }

        $this->_cid_ = $cid;
        \Swoole\Coroutine::defer(function(){
            $this->_cid_ = -1;
            if($this->_wait_lock_channel_ != null){
                $this->_wait_lock_channel_->push(true);
            }
        });

        return $this;
    }



    /**
     * 等待一组对象空闲
     */
    public function groupWaitLock(array $chains) :static {
        # 1. 所有的锁都是空闲的 锁定所有商户并返回
        $cid = \Swoole\Coroutine::getCid();

        # 获取所有商户
        $chains[] = $this->id;

        # 排序 避免死锁
        sort($chains, SORT_NUMERIC);

        # 获取所有要锁定的商户
        $wait_locks = array_map(function($id){
            return static::get($id);
        }, $chains);

        # 2. 有一个锁被占用，等待
        foreach($wait_locks as $static){
            if($static->_cid_  = -1 || $static->_cid_ == $cid){
                $static->_cid_ = $cid;
                continue;
            }

            $static->_wait_counter++;
            if(null === $static->_wait_lock_channel_){
                $static->_wait_lock_channel_ = new \Swoole\Coroutine\Channel(1);
            }
            $static->_wait_lock_channel_->pop();
            $static->_wait_counter--;
            $static->_cid_ = $cid;

            if(0 == $this->_wait_counter) {
                $this->_wait_lock_channel_->close();
                $this->_wait_lock_channel_ = null;
            }
        }

        # 协程退出后释放所有锁
        \Swoole\Coroutine::defer(function() use($wait_locks){
            foreach($wait_locks as $static){
                $static->_cid_ = -1;
                if($static->_wait_lock_channel_ != null){
                    $static->_wait_lock_channel_->push(true);
                }
            }
        });
        return $this;
    }
}