<?php

# 定义系统常量
define('APP_ROOT', dirname(__DIR__));
define('SITE_ROOT', __DIR__);
define('DEBUG_MODE', true);

# 定义判别是否运行于命令行还是后台服务的宏
define('IS_CLI', $argv[1] ?? 'cli' !== 'systemd');
# 服务名称.
$service_name = $argv[2] ?? 'exti';

require __DIR__ . '/../vendor/autoload.php';


# 创建保存进程句柄的文件.
$pid_file = dirname(__DIR__)."/var/{$service_name}.pid";
file_put_contents($pid_file, getmypid());
chown($pid_file, "php");
chgrp($pid_file, "www");

# 加载环境变量配置
LoadEnvironmentFromFile();
\sys\App::execute();

# 删除进程句柄文件
@unlink($pid_file);

