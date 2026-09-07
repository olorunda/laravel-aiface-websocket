<?php

$loader = require __DIR__ . '/../../laravel-zkteco-push/vendor/autoload.php';

// Register PSR-4 prefix for AiFace\WebSocket\
$loader->addPsr4('AiFace\\WebSocket\\', __DIR__ . '/../src/');
$loader->addPsr4('AiFace\\WebSocket\\Tests\\', __DIR__ . '/');
