<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Impack\WebSocket\Client;

$ws = new Client();

$ws->on('open', function () use ($ws) {
    echo "\r\n已连通发送：你好！\r\n";
    $ws->send('你好!');
});

$ws->on('message', function ($msg) use ($ws) {
    echo "\r\n对方来信：$msg\r\n";
    static $i = 100000;
    $ws->send($i);
    ++$i;
    sleep(2);
});

$ws->on('close', function ($msg) {
    echo "\r\n$msg\r\n";
});

$ws->online('ws://localhost:3000');