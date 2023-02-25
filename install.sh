#!/bin/bash

USER=php
GROUP=www
CURPATH=$(pwd)

# 指定网站文件入口
ENTER_FILE=dist/index.php

PHP_VERSION=`/usr/local/php/bin/php -v | grep "^PHP [0-9]" | cut -c 5-7`

# 服务器必须设置的环境变量
ENV_SECTIONS=("SERVER_NAME" "SERVER_TYPE" "SERVER_ID" "CPUS" "BIND_ADDRESS" "LISTEN_PORT" "GATE_DOMAIN" "GATE_ADDR" "GATE_PORT" "GATE_USE_SSL")

# 服务器必须存在的扩展
PHP_EXTENSIONS=("swoole.so" "pdo_mysql.so" "exif.so")


function CheckEnvConfig() {
    if [ ! -f $CURPATH/scripts/.env ]; then
        echo -e "\033[31m[ERROR] 请先创建环境变量配置文件. scripts/.env \033[0m"
        return 1;
    fi

    echo -e "\033[32m===================== 开始检查配置 =====================\033[0m"
    # 检查服务配置

    CHECK_PASS=true
    for SECTION_NAME in ${ENV_SECTIONS[@]}
    do
        TEMP_VAR=$(sed "/^${SECTION_NAME}=/!d; s/.*=//" ${CURPATH}/scripts/.env 2>/dev/null)
        if [ "$TEMP_VAR" = "" ]; then
            CHECK_PASS=false
            echo -e "\033[31m[ERROR] 在文件 scripts/.env 中, 变量: $SECTION_NAME 没有配置 \033[0m";
        else
            echo -e "\033[32m[PASS] 配置项: ${SECTION_NAME}=${TEMP_VAR}\033[0m"
        fi
    done

    if [ "$CHECK_PASS" = "false" ]; then
        return 1;
    fi
    return 0;
}

function CheckPHPConfig() {
    CHECK_PASS=true
    echo -e "\033[32m==================== 开始检PHP查配置 ====================\033[0m"
    echo -e "\033[34m[NOTICE] PHP配置文件: ${PHP_CONFIG_FILE} \033[0m"
    for EXTENSION_NAME in ${PHP_EXTENSIONS[@]}
    do
        TEMP_VAR=$(sed "/^extension=${EXTENSION_NAME}/!d; s/.*=//" ${PHP_CONFIG_FILE} 2>/dev/null)
        if [ "$TEMP_VAR" = "" ]; then
            echo -e "\033[34m[NOTICE] 未找到 $EXTENSION_NAME 扩展加载指令 自动加入 \033[0m"
            sed -i "/;extension=xsl/i\extension=${EXTENSION_NAME}" ${PHP_CONFIG_FILE}
            TEMP_VAR=$(sed "/^extension=${EXTENSION_NAME}/!d; s/.*=//" ${PHP_CONFIG_FILE} 2>/dev/null)
            if [ "$TEMP_VAR" = "" ]; then
                echo -e "\033[31m[ERROR] 加入扩展 $EXTENSION_NAME : $TEMP_VAR1 加载指令 失败! \033[0m"
            else
                echo -e "\033[32m[PASS] 添加扩展加载指令 $EXTENSION_NAME 完成! \033[0m"
            fi
        else
            echo -e "\033[35m[PASS] 检查扩展 $EXTENSION_NAME \033[0m"
        fi
    done
    echo -e "\033[32m==================== PHP配置检查完成 ====================\033[0m"
}

CheckEnvConfig;



if [ 0 = $? ]; then
    chmod a+x ${CURPATH}/scripts/*.sh

    SERVICE_NAME=$(sed '/^SERVER_NAME=/!d; s/.*=//' $CURPATH/scripts/.env)
    PHP_CONFIG_FILE=/usr/local/php/etc/${SERVICE_NAME}.ini
    cp -f /usr/local/php/etc/php.ini ${PHP_CONFIG_FILE}
    CheckPHPConfig;

    # 开启opcache加速
    sed -i 's#opcache.enable=.*#;opcache.enable=1#' ${PHP_CONFIG_FILE}
    sed -i 's#opcache.enable_cli=.*#;opcache.enable_cli=1#' ${PHP_CONFIG_FILE}

    # 取消内存限制
    sed -i 's#memory_limit = .*#memory_limit = -1#' ${PHP_CONFIG_FILE}

    case ${PHP_VERSION} in
        "8.1"|"8.2")
            echo -e "\033[32m[PASS] 当前PHP版本是 PHP-${PHP_VERSION}. 将开启Opcache+JIT\033[0m"
            # 开启opcache加速, 开启Jit
            sed -i 's#[;]*opcache.enable=.*#opcache.enable=1#' ${PHP_CONFIG_FILE}
            sed -i 's#[;]*opcache.enable_cli=1#opcache.enable_cli=1#' ${PHP_CONFIG_FILE}
            sed -i '/^opcache.enable_cli=1/a\opcache.jit=1025' ${PHP_CONFIG_FILE}
            # OpCache JIT 缓存大小
            sed -i '/^opcache.enable_cli=1/a\opcache.jit_buffer_size=512M' ${PHP_CONFIG_FILE}
            # OpCache 最多可以加速多少个文件
            sed -i 's#[;]*opcache.max_accelerated_files=.*#opcache.max_accelerated_files=120000#' ${PHP_CONFIG_FILE}
        ;;
        *)
            echo -e "\033[31m[ERROR]\033[0m PHP版本: ${PHP_VERSION}, 该PHP版本目前不受支持.";
            exit 0;
        ;;
    esac

    echo -e "\033[32m[PASS] 创建热更新监控脚本: run.sh \033[0m"


    echo -e "\033[32m[PASS] 创建调试模式运行脚本: run.sh \033[0m"
    cat <<EOF > ${CURPATH}/run.sh
#!/bin/bash

echo 正在停止服务: ${SERVICE_NAME} ...
timeout 3 systemctl stop ${SERVICE_NAME}.service
ZOMBE_PROC_NUM=\$(ps ax | grep ${SERVICE_NAME} | grep -v grep | wc -l)
echo 正在清理僵尸进程,共\${ZOMBE_PROC_NUM}个....
ZOMBE_PIDS=\$(ps ax | grep ${SERVICE_NAME} | grep -v grep | awk '{print \$1}')
for ZPID in \${ZOMBE_PIDS}
do
    kill -s 9 \${ZPID}
done

ulimit -n 262140
${CURPATH}/scripts/initenv.sh
/usr/local/php/bin/php -c ${PHP_CONFIG_FILE} -f ${ENTER_FILE} cli ${SERVICE_NAME}
EOF

echo -e "\033[32m[PASS] 重建重启服务器脚本: reload.sh \033[0m"
cat <<EOF > ${CURPATH}/reload.sh
#!/bin/bash

kill -USR1 \$(cat ${CURPATH}/var/${SERVICE_NAME}.pid)
EOF

cat <<EOF > ${CURPATH}/restart.sh
#!/bin/bash

echo 正在停止服务: ${SERVICE_NAME} ...
timeout 3 systemctl stop ${SERVICE_NAME}.service
ZOMBE_PROC_NUM=\$(ps ax | grep ${SERVICE_NAME} | grep -v grep | wc -l)
echo 正在清理僵尸进程,共\${ZOMBE_PROC_NUM}个....
ZOMBE_PIDS=\$(ps ax | grep ${SERVICE_NAME} | grep -v grep | awk '{print \$1}')
for ZPID in \${ZOMBE_PIDS}
do
    kill -s 9 \${ZPID}
done
echo 正在启动服务...
systemctl start  ${SERVICE_NAME}.service
sleep 1s
ps ax | grep ${SERVICE_NAME} | grep -v grep
EOF


    echo -e "\033[32m[PASS] 安装系统服务: ${SERVICE_NAME}.service \033[0m"
    cat  <<EOF 	> /lib/systemd/system/${SERVICE_NAME}.service
# It's not recommended to modify this file in-place, because it
# will be overwritten during upgrades.  If you want to customize,
# the best way is to use the "systemctl edit" command.

[Unit]
Description=The PHP ${SERVICE_NAME} Server
After=network.target
After=syslog.target

[Service]
Type=simple
#User=${USER}
#Group=${GROUP}
LimitNOFILE=262140
PIDFile=${CURPATH}/var/${SERVICE_NAME}.pid
ExecStartPre=${CURPATH}/scripts/initenv.sh
EnvironmentFile=${CURPATH}/scripts/.env
ExecStart=/usr/local/php/bin/php -c /usr/local/php/etc/${SERVICE_NAME}.ini -f ${CURPATH}/${ENTER_FILE} systemd ${SERVICE_NAME}
#ExecStartPost=${CURPATH}/scripts/posttask.sh
#SuccessExitStatus=0 1
ExecReload=/bin/kill -USR1 \$MAINPID
ExecStop=/bin/kill -SIGTERM \$MAINPID
Restart=always

WorkingDirectory=${CURPATH}
#ReadOnlyPaths=/
#ReadWritePaths=${CURPATH}/var:${CURPATH}/public/uploads
#NoNewPrivileges=true


# Set up a new file system namespace and mounts private /tmp and /var/tmp directories
# so this service cannot access the global directories and other processes cannot
# access this service's directories.
PrivateTmp=false

# Mounts the /usr, /boot, and /etc directories read-only for processes invoked by this unit.
ProtectSystem=full

# Sets up a new /dev namespace for the executed processes and only adds API pseudo devices
# such as /dev/null, /dev/zero or /dev/random (as well as the pseudo TTY subsystem) to it,
# but no physical devices such as /dev/sda.
PrivateDevices=true

# Explicit module loading will be denied. This allows to turn off module load and unload
# operations on modular kernels. It is recommended to turn this on for most services that
# do not need special file systems or extra kernel modules to work.
ProtectKernelModules=true

# Kernel variables accessible through /proc/sys, /sys, /proc/sysrq-trigger, /proc/latency_stats,
# /proc/acpi, /proc/timer_stats, /proc/fs and /proc/irq will be made read-only to all processes
# of the unit. Usually, tunable kernel variables should only be written at boot-time, with the
# sysctl.d(5) mechanism. Almost no services need to write to these at runtime; it is hence
# recommended to turn this on for most services.
ProtectKernelTunables=true

# The Linux Control Groups (cgroups(7)) hierarchies accessible through /sys/fs/cgroup will be
# made read-only to all processes of the unit. Except for container managers no services should
# require write access to the control groups hierarchies; it is hence recommended to turn this on
# for most services
ProtectControlGroups=true

# Any attempts to enable realtime scheduling in a process of the unit are refused.
RestrictRealtime=true

# Restricts the set of socket address families accessible to the processes of this unit.
# Protects against vulnerabilities such as CVE-2016-8655
RestrictAddressFamilies=AF_INET AF_INET6 AF_NETLINK AF_UNIX

# Takes away the ability to create or manage any kind of namespace
RestrictNamespaces=true

[Install]
WantedBy=multi-user.target

EOF

if [ ! -d "${CURPATH}/var" ]; then
    mkdir -p ${CURPATH}/var
    chown -R php:www ${CURPATH}/var
fi

systemctl daemon-reload

chmod a+x ${CURPATH}/restart.sh
chmod a+x ${CURPATH}/run.sh
chmod a+x ${CURPATH}/reload.sh
echo -e "\033[32m[PASS] 安装完成! \033[0m"
fi
