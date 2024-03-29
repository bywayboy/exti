<?php

return [
    # 应用模块列表
    'modules'=>[
        # 默认模块
        'index' => [
            'enabled'       => true,                                        # 模块是否开启
            'worker_num'    =>intval(getenv('CPUS') ?? 1),                  # 工作进程数目
            'server_id'     =>intval(getenv('SERVER_ID') ?? 0),             # 服务器节点ID(每台服务器一个独立ID)
            'worker_id'     =>intval(getenv('WORKER_ID') ?? 0),             # 计算节点ID(工作进程ID起始编号)
            'bind_address'  =>trim(getenv('BIND_ADDRESS') ?? '127.0.0.1'),  # 服务器监听IP地址
            'listen_port'   =>intval(getenv('LISTEN_PORT') ?? 8400),        # 服务器监听起始端口
            'share_port'    => false,                                       # 每个工作进程共享起始端口, 如果设置为 false 则每个工作进程绑定独立端口 listen_port + 进程ID(从零开始) 
            'user'          =>'php:www',                                    # 工作进程绑定到指定用户和组,
            'protocol'      => \sys\servers\HttpServer::class,              # 服务器角色, 这是HTTP 服务器角色
            'ssl'           =>false,                                        # 是否启用SSL安全连接
            # URL 重写 利用 正则表达式替换实现.
            'rewrite'       =>[
                '#^/app/#'  =>'/',
                '#^/fun/#'  =>'/so/',
            ],
            # 模板引擎配置
            'tpl'           => [
                # 这里是相对于站点根目录等路径
            ]
        ]
    ],

    # 是否开启 Sql日志记录
    'log_sql' => true,

    # 是否开启表结构动态缓存(该功能将禁用磁盘缓存)
    'fields_lazy_cache' => true
];
