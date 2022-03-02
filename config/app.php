<?php

return [
    # 应用模块列表
    'modules'=>[
        # 默认模块
        'index' => [
            'enabled'       => true,                                        # 模块是否开启
            'worker_num'    =>intval(getenv('CPUS') ?? 1),                  # 工作进程数目
            'server_id'     =>intval(getenv('SERVER_ID') ?? 0),             # 服务器节点ID(每台服务器一个独立ID)
            'bind_address'  =>trim(getenv('BIND_ADDRESS') ?? '127.0.0.1'),  # 服务器监听IP地址
            'listen_port'   =>intval(getenv('LISTEN_PORT') ?? 8400),        # 服务器监听起始端口
            'share_port'    => true,                                        # 每个工作进程共享起始端口, 如果设置为 false 则每个工作进程绑定独立端口 listen_port + 进程ID(从零开始) 
            'user'          =>'php:www',                                    # 工作进程绑定到指定用户和组,
            'protocol'      => \sys\servers\HttpServer::class,              # 服务器角色, 这是HTTP 服务器角色
            'ssl'           =>false,                                        # 是否启用SSL安全连接
            # 模板引擎配置
            'tpl'           => [
                # 这里是相对于站点根目录等路径
            ]
        ]
    ]
];
