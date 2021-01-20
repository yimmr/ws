<?php

namespace Impack\WebSocket;

use Impack\WebSocket\Base;
use Impack\WebSocket\Events;
use Impack\WebSocket\Socket;

class Server
{
    use Events, Socket, Base;

    /**
     * 启动服务端
     *
     * @param  string  $address
     * @param  int  $port
     */
    public function server($address, $port = 3000)
    {
        $this->socketCreate();

        $this->socketServer($address, $port);

        echo "Socket服务运行于: {$address}:{$port}";

        $this->socketSelect([$this, 'handleConnection'], [$this, 'canBreak']);
    }

    /**
     * 处理可读写的套接字
     *
     * @param  resource  $socket
     */
    protected function handleConnection($socket)
    {
        if (($code = socket_last_error($socket)) != 0) {
            $this->closeOpened();
            $this->do('error', "不能处理活动的Socket：[$code]" . socket_strerror($code));
            return;
        }

        $this->client = $socket;

        // 从主套接字的状态判断新连接
        if ($this->socket == $socket) {
            if (($client = socket_accept($socket)) !== false) {
                $this->sockets[] = $client;
            }
        } elseif ($this->acceptHandshake()) {
            $this->do('connection');
            $this->messaging();
        }
    }

    /**
     * 响应客户端握手
     *
     * @return bool
     */
    protected function acceptHandshake()
    {
        if (in_array($this->client, $this->opens)) {
            return true;
        }

        // 校验请求头
        if (($header = $this->readHeader()) === false) {
            socket_close($this->client);
            return false;
        }

        $http = new Handshake($header);

        if (!$http->verifyUpgrade()) {
            $this->abort(400);
            return false;
        }

        if ($http->getHeader('Sec-WebSocket-Version') != 13) {
            $this->abort(426, ['Sec-WebSocket-Version' => 13]);
            return false;
        }

        $http->setHeader([
            'Upgrade'               => 'websocket',
            'Connection'            => 'Upgrade',
            'Sec-WebSocket-Accept'  => $http->getAcceptKey($http->getHeader('Sec-WebSocket-Key')),
            'Sec-WebSocket-Version' => 13,
        ]);

        $this->do('handshake', $http);

        $this->socketWrite($this->client, $http->getResponseHeader(101, 'Switching Protocols'));

        $this->opens[] = $this->client;

        return true;
    }

    /**
     * 向客户端发送消息
     *
     * @param  string  $content
     */
    public function send($content)
    {
        if ($this->canSend()) {
            $this->socketWrite($this->client, $this->getDataFrame($content));
        }
    }

    /**
     * 关闭连接
     */
    public function close()
    {
        $this->safeCloseClient(0);
        $this->do('close');
    }
}