<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInite371ea7745bb2fc1aa98c92a19597105
{
    public static $prefixLengthsPsr4 = array (
        'C' => 
        array (
            'Composer\\Installers\\' => 20,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'Composer\\Installers\\' => 
        array (
            0 => __DIR__ . '/..' . '/composer/installers/src/Composer/Installers',
        ),
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInite371ea7745bb2fc1aa98c92a19597105::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInite371ea7745bb2fc1aa98c92a19597105::$prefixDirsPsr4;

        }, null, ClassLoader::class);
    }
}
