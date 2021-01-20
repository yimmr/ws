<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Impack\WebSocket\Server;

$ws = new Server;

$ws->on('message', function ($msg, $frame) use ($ws) {
    echo "\r\n收到消息：$msg \r\n";
    $ws->send("已收到$msg");
});

$ws->on('error', function ($msg) {
    echo "\r\n$msg\r\n";
});

register_shutdown_function(function () use ($ws) {
    $ws->shutdown();
});

$ws->server('127.0.0.1', 3000);