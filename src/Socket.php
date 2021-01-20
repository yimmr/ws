<?php

namespace Impack\WebSocket;

use Exception;

trait Socket
{
    protected $socket;

    protected $sockets = [];

    /**
     * 下线主服务或指定套接字
     *
     * @param  resource  $socket
     */
    public function shutdown($socket = null)
    {
        socket_shutdown($socket ?? $this->socket, 2);
        socket_close($socket ?? $this->socket);
    }

    /**
     * 处理活动状态的套接字
     *
     * @param  callable  $callback
     * @param  callable  $break
     */
    protected function socketSelect($callback, $break = null)
    {
        while (true) {
            $changes = $this->sockets;
            $write   = null;
            $except  = null;
            @socket_select($changes, $write, $except, null);

            foreach ($changes as $socket) {
                call_user_func($callback, $socket);
            }

            if (is_callable($break) && $break()) {
                break;
            }
        }
    }

    /**
     * 创建Socket资源
     *
     * @param  int  $domain
     * @param  int  $type
     * @param  int  $protocol
     *
     * @throws Exception
     */
    protected function socketCreate($domain = AF_INET, $type = SOCK_STREAM, $protocol = SOL_TCP)
    {
        $this->socket = socket_create($domain, $type, $protocol);

        if (!$this->socket) {
            throw new Exception(socket_strerror(socket_last_error()));
        }

        $this->sockets = [$this->socket];
    }

    /**
     * 启动服务端
     *
     * @param  string  $address
     * @param  int     $port
     *
     * @throws Exception
     */
    protected function socketServer($address, $port = 3000)
    {
        socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);

        if (!socket_bind($this->socket, $address, $port)) {
            throw new Exception(socket_strerror(socket_last_error()));
        }

        if (!socket_listen($this->socket)) {
            throw new Exception(socket_strerror(socket_last_error()));
        }
    }

    /**
     * 连接服务端
     *
     * @param  string  $address
     * @param  int     $port
     *
     * @throws Exception
     */
    protected function socketConnect($address, $port = 3000)
    {
        if (!socket_connect($this->socket, $address, $port)) {
            throw new Exception(socket_strerror(socket_last_error()));
        }
    }

    /**
     * 写入全部数据到缓冲区
     *
     * @param  resource  $socket
     * @param  string  $buffer
     */
    protected function socketWrite($socket, $buffer)
    {
        $length = strlen($buffer);

        while (true) {
            $sent = @socket_write($socket, $buffer, $length);

            if ($sent === false || $sent >= $length) {
                break;
            }

            $buffer = substr($buffer, $sent);
            $length -= $sent;
        }
    }

    /**
     * 直接发送全部数据
     *
     * @param  resource  $socket
     * @param  string    $buf
     * @param  int       $len
     * @param  int       $flags
     * @return int|false
     */
    protected function socketSend($socket, $buf, $flags = MSG_OOB)
    {
        return socket_send($socket, $buf, strlen($buf), $flags);
    }
}