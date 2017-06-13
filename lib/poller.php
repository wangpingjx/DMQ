<?php
require_once DMQ_ROOT_DIR . 'lib/client.php';

class Poller {
    public function __construct() {
        $this->objClient = new DMQ\Lib\Client();
    }

    public function start() {
        # 随机等待时间，避免同时触发Redis IO引发雪崩
        $this->initial_wait();

        while(true) {
            # 任务入队
            $this->enqueue();
            # 休眠随机时间
            $this->wait();
        }
    }

    # 随机等待时间，避免同时触发 Redis IO
    public function initial_wait() {
        $total = 3 * rand(0, 10)/10;
        sleep($total);
    }

    # 眠时间最好能将所有进程错开: sidekiq 进程数量 x 平均拉取时间 average_scheduled_poll_interval
    public function wait() {
        $time = 1 + rand(0, 10) / 10;
        sleep($time);
    }

    public function enqueue() {
        try {
            $this->enqueue_jobs();
        } catch (Exception $e) {
            echo 'caught exception in function enqueue :(';
        }
    }

    # 取出到达计划执行时间的任务，将它们加入工作队列，暂时不考虑重试队列
    public function enqueue_jobs() {
        echo "enqueue jobs...\n";
        while($job = $this->objClient->zrangebyscore() && !empty($job)) {
            # 将它从集合中删除，并加入工作队列
            if($this->objClient->zrem($job)) {
                $this->objClient->enqueue_to_queue($job);
            }
        }
    }
}
?>
