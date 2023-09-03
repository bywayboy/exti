<?php
declare(strict_types=1);

namespace lib\sync;

use Swoole\Coroutine;
use sys\Log;
use sys\services\JsonWebSocket;
class Synchronize {
    protected int   $mid;
    protected array $changes = [];

    protected static $version_cached = [];
    protected static $libs = [
        'Merchant'      => '\lib\Merchant',                         # 商户
        'GoodsCategory' => '\lib\data\GoodsCategory',               # 产品分类
        'Goods'         => '\lib\data\Goods',                       # 产品
        'Store'         => '\lib\data\Store',                       # 货架 仓库
        'StoreGoods'    => '\lib\data\StoreGoods',                  # 产品库存
        'User'          => '\lib\User',                             # 用户

        # 虚拟数据(数据库直通同步)
        'Documents'     => '\lib\data\virtual\Documents',            # 单据
        'GoodsOrder'    => '\lib\data\virtual\GoodsOrder',          # 订单
        'OrderFlow'     => '\lib\data\virtual\OrderFlow',           # 订单流水(金额变动表)
        'GoodsFlow'     => '\lib\data\virtual\GoodsFlow',           # 单据流水(商品变动表)
        'OrderDist'     => '\lib\data\virtual\OrderDist',           # 订单配货数据
        'Shift'         => '\lib\data\virtual\Shift',               # 班次数据
        'GoodsCashout'  => '\lib\data\virtual\GoodsCashout',        # 提现申请
        'Messages'      => '\lib\data\virtual\Messages',            # 消息系统
    ];

    # 支持 $db->task() 的方式同步的数据类型
    protected static $tables = [
        'tp_nb_goods_documents'             => 'Documents',          # 单据
        'tp_nb_goods_order'                 => 'GoodsOrder',         # 订单
        'tp_nb_goods_order_flow'            => 'OrderFlow',          # 订单流水记录
        'tp_nb_goods_changes'               => 'GoodsFlow',          # 商品流水记录
        'tp_nb_goods_order_distribution'    => 'OrderDist',          # 订单配货数据
        'tp_nb_goods_cashout_request'       => 'GoodsCashout',       # 班次数据
        'tp_nb_goods_shift'                 => 'Shift',              # 提现申请
        'tp_nb_merchant_goods'              => 'Merchant',           # 商户
        'tp_nb_goods_messages'              => 'Messages',           # 消息系统
        
    ];

    protected static function broadcast(int $mid, array $msg) : void {
        Log::write('请实现 Synchronize::$broadcast 方法', 'ERROR');
    }

    public function __construct(?\sys\Db $db = null, int $mid) {
        $this->mid = $mid;
        if(null !== $db){
            \Swoole\Coroutine::defer(function() use($mid){
                if($this->hasSyncData()){
                    $msg = ['event'=>'sync', 'data'=>$this->makeSyncData(null)];
                    static::broadcast($mid, $msg);
                    Log::write('Synchronize::defer'.json_encode($msg),'DEBUG');
                    $this->changes = [];
                }
            });
        }
    }

    /**
     * 记录覆盖、全量跟新
     */
    public function put(string $cat, $record){
        $this->changes[$cat]['put'][] = $record;
    }

    /**
     * 记录删除
     */
    public function rm(string $cat, int $id) {
        $this->changes[$cat]['rm'][] = $id;
    }

    /**
     * 记录部分更新
     */
    public function up(string $cat, int $id, mixed $changed){
        $this->changes[$cat]['up'][] = ($changed + ['id'=>$id]);
    }

    /**
     * 是否有同步数据
     */
    public function hasSyncData() : bool {
        return !empty($this->changes);
    }

    /**
     * 创建一个同步包.
     */
    public function makeSyncData(?\sys\Db $db) :?array {
        if(empty($this->changes))
            return null;
        
        $mid = $this->mid;
        $sqls = [];

        foreach($this->changes as $key=>$changed){
            if(!static::$libs[$key]::alwaysFullSync()){
                if(null === $db){
                    $db = new \sys\Db();
                }
                if(!isset(static::$version_cached[$this->mid][$key])){
                    static::$version_cached[$this->mid][$key] = 1;
                    $sqls[] = $db->table('tp_nb_goods_dataversion')->subInsert(['mid' => $mid,'key' => $key,'version' => 1]);
                }else{
                    static::$version_cached[$this->mid][$key] += 1;
                    $sqls[] = $db->table('tp_nb_goods_dataversion')->where([['mid', '=', $mid], ['key','=', $key]])->inc('version', 1)->subUpdate();
                }

                # 保存同步日志
                $sqls[] = $db->table('tp_nb_goods_sync')->subInsert([
                    'mid'           => $mid,
                    'key'           => $key,
                    'version'       => static::$version_cached[$mid][$key],
                    'data'          => serialize($changed),
                    'createtime'    => time(),
                ]);
                
                $ret[] = array_merge(['name'=>$key, 'full'=>false,'version'=>static::$version_cached[$mid][$key]], $changed);
                continue;
            }
            $ret[] = array_merge(['name'=>$key, 'full'=>false], $changed);
        }
        $this->changes = [];

        if(count($sqls) > 0){
            $db->batch_query($sqls, \sys\Db::SQL_UPDATE);
        }
        return $ret;
    }

    public static function setVersions(array $versions)
    {
        foreach($versions as $row){
            if(!isset(static::$version_cached[$row['mid']]['Orders'])){
                static::$version_cached[$row['mid']]['Orders'] = 1;
            }
            static::$version_cached[$row['mid']][$row['key']] = $row['version'];
        }
    }

    /**
     *  对比客户端版本号, 如果不一致则触发一次全量同步.
     */
    public static function Synchronize(int $mid, array $remoteVersions, ?JsonWebSocket $ws = null) : bool {
        if(isset(static::$version_cached[$mid]))
        {
            Coroutine::defer(function() use($remoteVersions, $ws, $mid){
                $ret = [];
                $serverVersions = static::$version_cached[$mid];
                foreach($remoteVersions as $key=>$remoteVer){
                    if(isset($serverVersions[$key])){
                        $serverVer = $serverVersions[$key];
                        if(static::$libs[$key]::alwaysFullSync()){
                            $ret[] = ['name'=>$key, 'full'=>true, 'put'=>static::$libs[$key]::getAll($mid)];
                        }elseif($serverVer !== $remoteVer){
                            $virtualSyncs[$key] = static::$libs[$key];
                        }
                    }
                }

                if(!empty($ret)){
                    Log::console('推送内存同步数据... ', 'DEBUG');
                    $ws->push(['event'=>'sync', 'data'=>$ret]);
                }

                if(isset($virtualSyncs)){
                    # 最早数据三个月前的 1月1日 0点
                    $time = strtotime('-3 month', strtotime(date('Y-m-01 00:00:00')));
                    $db = new \sys\Db();
                    foreach($virtualSyncs as $key=>$class){
                        $remoteVersion = $remoteVersions[$key];
                        $remoteVersion = $class::Synchronize(
                            $db, $ws, $mid, 
                            $remoteVersion, 
                            static::$version_cached[$mid][$key],
                            $time
                        );
                    }
                    # 通知客户端同步完成
                    Log::console('推送同步完成事件... ', 'DEBUG');
                    $ws->push(['event'=>'syncdone']);
                }else{
                    # 通知客户端同步完成
                    $ws->push(['event'=>'syncdone']);
                }
            });
        }
        return true;
    }

    /**
     * 生成同步
     */
    public function makeSyncTask(array $tasks) : void {
        foreach($tasks as $task){
            if(is_array($task)){
                $this->makeSyncTask($task);
            }elseif($task instanceof \sys\db\SubTask){
                $key = static::$tables[$task->getTableName()]; // 得到同步类别名
                switch($task->gettype()) {
                case \sys\Db::SQL_UPDATE:
                    $this->changes[$key]['up'][] = $task->getData();
                    break;
                case \sys\Db::SQL_INSERT:
                    $this->changes[$key]['put'][] = $task->getData();
                    break;
                case \sys\Db::SQL_DELETE:
                    $this->changes[$key]['rm'][] = $task->getId();
                    break;
                }
            }
        }
    }
}