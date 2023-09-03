<?php
namespace lib\sync;

use sys\services\JsonWebSocket;

class SyncableData extends \sys\Data {
    const ONE_SHOT_SIZE = 4096; # 一次推送的数据量
    protected static string $SyncKey;
    protected static string $TableName;

    /**
     * 获取同步数据的名称
     */
    public function getSyncName():string{
        return static::$SyncKey;
    }

    /**
     * 是否总是全量同步
     */
    public static function alwaysFullSync():bool {return false;}


    public static function Synchronize(\sys\Db $db, JsonWebSocket $ws, int $mid, int $remoteVersion, int $serverVersion, int $time) :void {

        # 服务器没有同步记录，说明服务器不存在数据变更历史，需要全量同步
        if($serverVersion > $remoteVersion) {
            # 1. 本地版本号大于远程版本号，说明本地数据有变动，需要同步
            $part = $db->table('tp_nb_goods_sync')->where([
                ['mid', '=', $mid],
                ['key', '=', static::$SyncKey],
                ['version', 'BETWEEN', [$remoteVersion + 1, $serverVersion]],
            ])->order('version ASC')->limit(static::ONE_SHOT_SIZE)->field('id, version, data')->select();
            $numParts = null == $part ? 0 : count($part);

            if($numParts > 0){
                $firstVersion   = $part[0]['version'];
                if($firstVersion !== $remoteVersion + 1){
                    # 说明中间有数据丢失，需要全量同步
                    static::FullSynchronize($db, $ws, $mid, $serverVersion, $time);
                    return;
                }

                do{
                    $nextVersion    = $part[$numParts-1]['version'];
                    $changed = ['name'=>static::$SyncKey, 'full'=>false, 'version'=>$nextVersion];

                    foreach($part as $row) {
                        $ch = $row['data'];
                        if(isset($ch['put'])){
                            if(!isset($changed['put'])){
                                $changed['put'] = $ch['put'];
                            }else{
                                $changed['put'] =  [...$changed['put'], ...$ch['put']];
                            }
                        }
                        if(isset($ch['up'])){
                            if(!isset($changed['up'])){
                                $changed['up'] = $ch['up'];
                            }else{
                                $changed['up'] =  [...$changed['up'], ...$ch['up']];
                            }
                        }
                        if(isset($ch['rm'])){
                            if(!isset($changed['rm'])){
                                $changed['rm'] = $ch['rm'];
                            }else{
                                $changed['rm'] =  [...$changed['rm'], ...$ch['rm']];
                            }
                        }
                    }
                    $ws->push(['event'=>'sync','data'=>[$changed]]);

                    if($nextVersion >= $serverVersion){
                        break;
                    }

                    # 继续获取下一批数据
                    $part = $db->table('tp_nb_goods_sync')->where([
                        ['mid', '=', $mid],
                        ['key', '=', static::$SyncKey],
                        ['version', 'BETWEEN', [$nextVersion, $serverVersion]],
                    ])->order('version ASC')->limit(1000)->field('id, version, data')->select();
                    $numParts = null == $part ? 0 : count($part);
                }while($numParts = static::ONE_SHOT_SIZE);
            }else{
                static::FullSynchronize($db, $ws, $mid, $serverVersion, $time);
            }
        }
    }

    static protected function buildSyncSelect(\sys\Db $db, int $mid, int $time, int $lastId = 0) :\sys\SqlBuilder {
        if( 0 == $lastId){
            return $db->table(static::$TableName)->where([
                ['mid', '=', $mid],
                ['createtime', '>=', $time]
            ])->where(['createtime', '>=', $time])->order('id ASC');
        }
        return $db->table(static::$TableName)->where([
            ['id','>', $lastId],
            ['mid', '=', $mid],
        ])->order('id ASC');
    }

    # 执行一次全量同步
    protected static function FullSynchronize(\sys\Db $db, JsonWebSocket $ws, int $mid, int $serverVersion, int $time) :void {
        $parts = static::buildSyncSelect($db, $mid, $time, 0)->limit(static::ONE_SHOT_SIZE)->select();
        $numParts = null == $parts ? 0 : count($parts);

        if($numParts > 0){
            $ws->push(['event'=>'sync','data'=>[[
                'name'=>static::$SyncKey,
                'full'=>true,
                'version'=>0,
                'put'=>$parts,
            ]]]);

            while($numParts >= static::ONE_SHOT_SIZE){
                $lastId = $parts[$numParts-1]['id'];
                $parts = static::buildSyncSelect($db, $mid, $time, $lastId)->limit(static::ONE_SHOT_SIZE)->select();
                $numParts = null == $parts ? 0 : count($parts);
                if($numParts > 0 ){
                    $ws->push(['event'=>'sync','data'=>[[
                        'name'=>static::$SyncKey,
                        'full'=>false,
                        'version'=>0,
                        'put'=>$parts,
                    ]]]);
                }
            };
        }
        # 发送同步完成事件
        $ws->push(['event'=>'sync','data'=>[[
            'name'=>static::$SyncKey,
            'full'=>false,
            'version'=>$serverVersion,
        ]]]);
    }
}