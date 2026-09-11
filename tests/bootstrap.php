<?php

$autoloadPaths = [
    __DIR__ . "/../vendor/autoload.php",
    __DIR__ . "/../../laravel-zkteco-push/vendor/autoload.php",
    __DIR__ . "/../../ttp/vendor/autoload.php",
];

$loader = null;
foreach ($autoloadPaths as $path) {
    if (file_exists($path)) {
        $loader = require $path;
        break;
    }
}

if ($loader) {
    // Register PSR-4 prefix for AiFace\WebSocket\
    $loader->addPsr4("AiFace\\WebSocket\\", __DIR__ . "/../src/");
    $loader->addPsr4("AiFace\\WebSocket\\Tests\\", __DIR__ . "/");
}