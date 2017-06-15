<?php
namespace DMQ\Lib;

class Client {
    const QUEUE_KEY    = 'dmq:queue';
    const SCHEDULE_KEY = 'dmq:schedule';
    // const RETRY_KEY    = 'dmq:retry';

    private $objRedis = nil;

    public function __construct() {
        $this->objRedis = new \Redis();
        $this->objRedis->connect('127.0.0.1', 6688);
    }
    #----- 将任务存储在有序集合 -----#
    public function zadd($queuename, $job) {
        $this->objRedis->zadd($queuename, $job['at'], json_encode($job));
    }
    public function zrange($queuename) {
        $ret = $this->objRedis->zrange($queuename, 0, -1);
        $result = [];
        foreach ((array)$ret as $job) {
            $result[] = json_decode($job, true);
        }
        return $result;
    }
    public function enqueue_to_schedule($job) {
        $this->zadd(self::SCHEDULE_KEY, $job);
    }
    // public function enqueue_to_retry($job) {
    //     $this->zadd(self::RETRY_KEY, $job);
    // }
    # 取出待执行任务
    public function zrangebyscore() {
        $ret = $this->objRedis->zrangebyscore(self::SCHEDULE_KEY, '-inf', time(), [0, 1]);
        return empty($ret) ? [] : $ret[0];
    }
    # 从集合中删除任务
    public function zrem($job) {
        return $this->objRedis->zrem(self::SCHEDULE_KEY, $job);
    }
    #----- 将任务压入待执行队列 -----#
    public function lpush($queuename, $job) {
        $this->objRedis->lpush($queuename, $job);
    }
    public function enqueue_to_queue($job) {
        $this->lpush(self::QUEUE_KEY, $job);
    }
    # 阻塞式拉取需要处理的任务
    public function brpop() {
        $this->objRedis->brpop(self::QUEUE_KEY, 1);
    }
}
?>
