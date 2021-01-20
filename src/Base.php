<?php

namespace Impack\WebSocket;

use Closure;

trait Base
{
    protected $client;

    protected $opens = [];

    protected $close = 0;

    /**
     * 判断是否可以退出循环
     *
     * @return bool
     */
    public function canBreak()
    {
        if ($bool = empty($this->sockets)) {
            $this->do('close', '没有可监听的资源');
        }
        return $bool;
    }

    /**
     * 移除数组中某一项
     *
     * @param  mixed  $item
     * @param  array  $array
     */
    public function removeArrItem($item, &$array)
    {
        if (($key = array_search($item, $array)) !== false) {
            unset($array[$key]);
        }
    }

    /**
     * 接收另一端消息并解码
     */
    protected function messaging()
    {
        $frame = $this->decodeDataFrame($this->client);

        // 无法解析出数据帧时应直接关闭套接字
        if (empty($frame)) {
            return $this->closeOpened();
        }

        // 若对方回应关闭帧则执行关闭程序
        if ($this->isByeBye($frame)) {
            $this->close += 1;
            $this->close();
        } else {
            $this->do('message', $frame['payload_data'] ?? '', $frame);
        }
    }

    /**
     * 返回数据帧
     *
     * @param  string  $content
     * @param  int     $mask
     * @param  int     $fin
     * @param  int     $opcode
     * @return string
     */
    protected function getDataFrame(&$content, $mask = 0, $fin = 1, $opcode = 0x1)
    {
        $content = strval($content);
        switch ($content) {
            case 'ping':
                $frame = $this->encodeDataFrame($content, 1, 0x9, $mask);
                break;
            case 'pong':
                $frame = $this->encodeDataFrame($content, 1, 0xA, $mask);
                break;
            default:
                $frame = $this->encodeDataFrame($content, $fin, $opcode, $mask);
                break;
        }
        return $frame;
    }

    /**
     * 关闭客户端
     */
    public function close()
    {
        $this->safeCloseClient(0);
        $this->do('close');
    }

    /**
     * 关闭客户端
     *
     * @param  int      $mask
     * @param  Closure  $callback
     */
    protected function safeCloseClient($mask)
    {
        @socket_send($this->client, $this->encodeDataFrame('Close', 1, 0x8, $mask), 100, 0);
        $this->close += 1;

        if ($this->close > 1) {
            return $this->closeOpened();
        }

        // 另一端没回应关闭帧则先等3秒
        $a = 3;
        do {
            $frame = $this->decodeDataFrame($this->client);
            if ($this->isByeBye($frame)) {
                return $this->closeOpened();
            }
            --$a;
            sleep(1);
        } while ($a);
    }

    /**
     * 关闭已开启的套接字
     */
    protected function closeOpened()
    {
        socket_close($this->client);
        $this->removeArrItem($this->client, $this->opens);
        $this->removeArrItem($this->client, $this->sockets);
    }

    /**
     * 是否可以发送消息
     *
     * @return bool
     */
    protected function canSend()
    {
        if (empty($this->client) || ($code = socket_last_error($this->client)) != 0) {
            $this->do('error', '无法发送消息：' . isset($code) ? "[$code]" . socket_strerror($code) : $code);
            return false;
        }
        return true;
    }

    /**
     * 拒绝连接并回应HTTP状态码
     *
     * @param  int    $code
     * @param  array  $headers
     */
    public function abort($code, $headers = [])
    {
        $this->socketSend($this->client, $this->getHttp()->setHeader($headers)->getErrorHeader($code), MSG_EOF);
        @socket_close($this->client);
    }

    /**
     * 读取所有响应头
     *
     * @return string|false  失败或内容为空时返回false
     */
    protected function readHeader()
    {
        $header = '';

        do {
            $content = socket_read($this->client, 1024);
            $header .= $content;

            if (empty($header)) {
                return false;
            }

            if (strpos($content, "\r\n\r\n") !== false) {
                return $header;
            }
        } while (true);
    }

    /**
     * 是否是关闭帧
     *
     * @param  array  $frame
     * @return bool
     */
    protected function isByeBye(&$frame)
    {
        return isset($frame['opcode']) && $frame['opcode'] == 8;
    }

    /**
     * 编码数据帧
     *
     * @param  string  $data
     * @param  int     $fin
     * @param  int     $opcode  4位16进制
     * @param  int     $mask
     * @return string
     */
    public function encodeDataFrame(&$data, $fin = 1, $opcode = 0x1, $mask = 0)
    {
        $frame  = $fin == 1 ? 0x80 : 0;
        $maskV  = $mask == 1 ? 0x80 : 0;
        $frame  = chr($frame | $opcode);
        $length = strlen($data);

        if ($length <= 125) {
            $frame .= chr($length | $maskV);
        } elseif ($length <= 65535) {
            $frame .= chr(126 | $maskV);
            $frame .= chr($length >> 8) . chr($length & 0xff);
        } else {
            $frame .= chr(127 | $maskV);
            $bit = 64;
            while ($bit) {
                $bit -= 8;
                $frame .= chr($length >> $bit);
            }
        }

        // 若数据需要掩码加密
        if ($mask == 1) {
            $mask = pack('N', rand(1, 0x7fffffff));
            $frame .= $mask;
            for ($i = 0; $i < $length; $i++) {
                $data[$i] = chr(ord($data[$i]) ^ ord($mask[$i % 4]));
            }
        }

        return $frame . $data;
    }

    /**
     * 解码数据帧
     *
     * @param  resource  $socket
     * @return array
     */
    public function decodeDataFrame($socket)
    {
        $buffer = @socket_read($socket, 2);

        if (strlen($buffer) < 2) {
            return [];
        }

        $i     = 0;
        $frame = [
            'fin'            => ord($buffer[$i]) >> 7,
            'opcode'         => ord($buffer[$i]) & 0x0f,
            'mask'           => ord($buffer[++$i]) >> 7,
            'payload_length' => ord($buffer[$i]) & 0x7f,
            'payload_data'   => '',
        ];

        // 有效负载数据长度
        if ($frame['payload_length'] == 126) {
            $buffer .= socket_read($socket, 2);
            $frame['payload_length'] = (ord($buffer[++$i]) << 8) | ord($buffer[++$i]);
        } elseif ($frame['payload_length'] == 127) {
            $buffer .= socket_read($socket, 8);
            $length = 0;
            $bit    = 64;
            while ($bit) {
                ++$i;
                $bit -= 8;
                $length |= ord($buffer[$i]) << $bit;
            }
            $frame['payload_length'] = $length;
        }

        $buffer .= socket_read($socket, $frame['payload_length']);

        // 若使用掩码则进行解密
        $a = 0;
        if ($frame['mask']) {
            $buffer .= socket_read($socket, 4);
            $frame['masking_key'] = [ord($buffer[++$i]), ord($buffer[++$i]), ord($buffer[++$i]), ord($buffer[++$i])];
            while ($a < $frame['payload_length']) {
                $frame['payload_data'] .= chr(ord($buffer[++$i]) ^ $frame['masking_key'][$a % 4]);
                ++$a;
            }
        } else {
            while ($a < $frame['payload_length']) {
                $frame['payload_data'] .= chr(ord($buffer[++$i]));
                ++$a;
            }
        }

        return $frame;
    }
}