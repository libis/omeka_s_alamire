<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit4614796768038832d4cf2ac90199b123
{
    public static $prefixLengthsPsr4 = array (
        'R' => 
        array (
            'Reference\\' => 10,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Reference\\' => 
        array (
            0 => __DIR__ . '/../..' . '/src',
        ),
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInit4614796768038832d4cf2ac90199b123::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit4614796768038832d4cf2ac90199b123::$prefixDirsPsr4;

        }, null, ClassLoader::class);
    }
}
