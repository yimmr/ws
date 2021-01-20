<?php

namespace Impack\WebSocket;

use Impack\WebSocket\Base;
use Impack\WebSocket\Events;
use Impack\WebSocket\Socket;

class Client
{
    use Events, Socket, Base;

    protected $url;

    /**
     * 连接并在线
     *
     * @param  string  $url
     */
    public function online($url)
    {
        $this->parseUrl($url);
        $this->socketCreate();
        $this->socketConnect($this->url['ip'], $this->url['port']);
        $this->handleConnection($this->socket);
    }

    /**
     * 处理可读写的套接字
     *
     * @param  resource  $socket
     */
    protected function handleConnection($socket)
    {
        $this->client = $socket;

        if ($this->launchHandshake()) {
            $this->do('open');
            $this->socketSelect(function ($socket) {
                $this->client = $socket;
                $this->messaging();
            }, [$this, 'canBreak']);
        }
    }

    /**
     * 客户端发起握手
     *
     * @return bool
     */
    protected function launchHandshake()
    {
        if (in_array($this->client, $this->opens)) {
            return true;
        }

        ($http = new Handshake)->setHeader([
            'Host'                  => 'localhost',
            'Upgrade'               => 'websocket',
            'Connection'            => 'Upgrade',
            'Sec-WebSocket-Key'     => base64_encode(openssl_random_pseudo_bytes(16)),
            'Sec-WebSocket-Version' => '13',
        ]);

        $this->do('handshake', $http);

        $this->socketWrite($this->client, $http->getRequestHeader('GET', $this->url['uri'], '1.1'));

        // 校验响应头
        if (($header = $this->readHeader()) === false) {
            socket_close($this->client);
            return false;
        }

        $http->setResponseHeader($header);

        if (!$http->verifyUpgrade(false)) {
            socket_close($this->client);
            return false;
        }

        $this->opens[] = $this->client;

        return true;
    }

    /**
     * 解析URL各部分信息
     *
     * @param  string  $url
     */
    protected function parseUrl($url)
    {
        $url = parse_url($url);

        if (!isset($url['port'])) {
            $url['port'] = $url['scheme'] == 'ws' ? 80 : 443;
        }

        $url['ip']  = gethostbyname($url['host']);
        $url['uri'] = ($url['path'] ?? '/') . (empty($url['query']) ? '' : '?' . $url['query']);

        $this->url = $url;
    }

    /**
     * 向客户端发送消息
     *
     * @param  string  $content
     */
    public function send($content)
    {
        if ($this->canSend()) {
            $this->socketWrite($this->client, $this->getDataFrame($content, 1));
        }
    }

    /**
     * 关闭连接
     */
    public function close()
    {
        $this->safeCloseClient(1);
        $this->do('close');
    }
}