<?php

# 定义系统常量
define('APP_ROOT', dirname(__DIR__));
define('SITE_ROOT', __DIR__);
define('DEBUG_MODE', true);

define('IS_CLI', $argv[1] ?? 'cli' !== 'systemd');

$service_name = $argv[2] ?? 'netbarfee';

require __DIR__ . '/../vendor/autoload.php';

$pid_file = dirname(__DIR__).'/var/exti.pid';

file_put_contents($pid_file, getmypid());
chown($pid_file, "php");
chgrp($pid_file, "www");

# 加载环境变量配置
LoadEnvironmentFromFile();
\sys\App::execute();

@unlink($pid_file);

