<?php

namespace Impack\WebSocket;

class Handshake
{
    protected $responseHeader;

    protected $headers = [];

    protected $error = [
        400 => 'Bad Request',
        403 => 'Forbidden',
        404 => 'Not Found',
        426 => 'Upgrade Required',
    ];

    public function __construct($responseHeader = null)
    {
        if (!is_null($responseHeader)) {
            $this->responseHeader = $responseHeader;
        }
    }

    /**
     * 设置响应头，以便提取部分信息
     *
     * @param  string  $header
     */
    public function setResponseHeader(string $header)
    {
        $this->responseHeader = $header;
    }

    /**
     * 验证是否要升级到ws协议
     *
     * @param  bool  $server  是否是服务端验证
     * @return bool
     */
    public function verifyUpgrade($server = true)
    {
        if (
            strtolower($this->getHeader('Upgrade')) != 'websocket'
            || strtolower($this->getHeader('Connection')) != 'upgrade'
        ) {
            return false;
        }

        if ($server) {
            return !empty($this->getHeader('Sec-WebSocket-Key'));
        }

        return $this->getHeader('Sec-WebSocket-Accept') == $this->getAcceptKey($this->headers['Sec-WebSocket-Key']);
    }

    /**
     * 生成Sec-WebSocket-Accept
     *
     * @param  string  $key  Sec-WebSocket-Key
     */
    public function getAcceptKey($key)
    {
        return base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
    }

    /**
     * 返回HTTP错误的响应头
     *
     * @param  int  $code
     */
    public function getErrorHeader($code)
    {
        return $this->getResponseHeader($code, $this->error[$code] ?? $this->error[400]);
    }

    /**
     * 返回请求头格式的字符串
     *
     * @param  string  $method
     * @param  string  $path
     * @param  string  $version
     * @return string
     */
    public function getRequestHeader($method = 'GET', $path = '/', $version = '1.1')
    {
        return strtoupper($method) . " {$path} HTTP/{$version}\r\n" . $this->formatHeader() . "\r\n";
    }

    /**
     * 返回响应头格式的字符串
     *
     * @param  int     $code
     * @param  string  $message
     * @param  string  $version
     * @return string
     */
    public function getResponseHeader($code = 200, $message = 'OK', $version = '1.1')
    {
        return "HTTP/{$version} {$code} {$message}\r\n" . $this->formatHeader() . "\r\n";
    }

    /**
     * 请求头数组转字符串格式
     *
     * @return string
     */
    public function formatHeader()
    {
        $header = '';
        foreach ($this->headers as $key => $value) {
            $header .= "{$key}: {$value}\r\n";
        }
        return $header;
    }

    /**
     * 设置请求头
     *
     * @param  string|array
     * @param  mixed
     * @return $this
     */
    public function setHeader($key, $value = '')
    {
        if (is_array($key)) {
            $this->headers = $key;
        } elseif ($key) {
            if (is_null($value)) {
                unset($this->headers[$key]);
            } else {
                $this->headers[$key] = $value;
            }
        }

        return $this;
    }

    /**
     * 返回指定响应头信息
     *
     * @param  string  $key
     * @return string|array
     */
    public function getHeader($key)
    {
        preg_match_all('/' . $key . ':([^\r]*)\r\n/', $this->responseHeader, $match);

        if (isset($match[1])) {
            return count($match[1]) > 1 ? $match[1] : trim($match[1][0] ?? '');
        }

        return '';
    }

    /**
     * 返回HTTP响应状态码
     *
     * @return int|false
     */
    public function getCode()
    {
        if (empty($this->responseHeader) || ($offset = strpos($this->responseHeader, "\r\n")) === false) {
            return false;
        }

        $array = explode(' ', substr($this->responseHeader, 0, $offset));

        return isset($array[1]) ? intval($array[1]) : false;
    }
}