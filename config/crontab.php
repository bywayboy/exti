<?php

return [
    # every: n unit, 例如: 1y 每年, 1m 1个月 1w 一周 1d 每天, 1h 每小时, 1u 每分钟 1s 每秒
    # at: y 必须是 mm-dd hh:mm:ss    月日 时:分:秒 
    # at: m 必须是 dd hh:mm:ss      几日 时:分:秒
    # at: w 必须是 dd hh:mm:ss      周几 时:分:秒
    # at: d 必须是 hh:mm:ss             时:分:秒
    # at: h 必须是 mm:ss            小时    分:秒
    # at: u 必须是 ss               分钟       秒   代表每分钟第x秒执行
    # [ 'every'=>'1d', 'at'=>'15:06:00',  'exec'=>[ \lib\Crontab::class, 'day_reward' ] ],
];
