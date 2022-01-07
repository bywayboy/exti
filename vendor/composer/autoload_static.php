<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit7d4e3350a10d1d90c25974fcfc7ae63e
{
    public static $files = array (
        '9f5c81582447512175c6bbb8c535a7db' => __DIR__ . '/../..' . '/sys/helpers/functions.php',
    );

    public static $prefixLengthsPsr4 = array (
        's' => 
        array (
            'sys\\' => 4,
        ),
        'r' => 
        array (
            'rpc\\' => 4,
        ),
        'a' => 
        array (
            'app\\' => 4,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'sys\\' => 
        array (
            0 => __DIR__ . '/../..' . '/sys',
        ),
        'rpc\\' => 
        array (
            0 => __DIR__ . '/../..' . '/rpc',
        ),
        'app\\' => 
        array (
            0 => __DIR__ . '/../..' . '/app',
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInit7d4e3350a10d1d90c25974fcfc7ae63e::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit7d4e3350a10d1d90c25974fcfc7ae63e::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInit7d4e3350a10d1d90c25974fcfc7ae63e::$classMap;

        }, null, ClassLoader::class);
    }
}
