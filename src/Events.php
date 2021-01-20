<?php

namespace Impack\WebSocket;

use Closure;

trait Events
{
    protected $events;

    /**
     * 添加事件回调
     *
     * @param  string   $name
     * @param  Closure  $callback
     */
    public function on($name, Closure $callback)
    {
        if (!isset($this->events[$name])) {
            $this->events[$name] = [];
        }

        array_push($this->events[$name], $callback);
    }

    /**
     * 执行事件回调
     *
     * @param  mixed  $params
     */
    function do($name, ...$params) {
        if (empty($this->events[$name])) {
            return;
        }

        foreach ($this->events[$name] as $callback) {
            call_user_func($callback, ...$params);
        }
    }
}