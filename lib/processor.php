<?php
require_once DMQ_ROOT_DIR . 'lib/client.php';

class Processor {
    public function __construct() {
        $this->objClient = new DMQ\Lib\Client();
    }

    public function start() {
        try {
            while(true) {
                $this->processOne();
            }
        } catch(Exception $e) {
            echo 'caught exception in function start :(';
        }
    }
    # 处理任务
    public function processOne() {
        $item = $this->fetch();
        if (!empty($item)) {
            $job = json_decode($item[1], true);
            $this->process($job);
        }
    }
    # 拉取任务
    public function fetch() {
        try {
            $item = $this->objClient->brpop();
            # TODO 测试用
            sleep(1);

            return $item;
        } catch(Exception $e) {
            echo 'caught exception in function fetch :(';
            # sleep 1秒再重试
            sleep(1);
        }
    }
    # 执行任务
    public function process($job) {
        try {
            var_dump('----in process----');
            var_dump($job);
        } catch (Expception $e) {
            echo 'caught exception in function process :(';
        }
    }
}
?>
