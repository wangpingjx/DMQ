<?php
namespace DMQ\Lib;

class RedisConn {
    protected $objRedis = null;

    public function __construct() {
        $this->objRedis = new \Redis();
        $this->objRedis->connect('127.0.0.1', 6688);
    }
}
