<?php
/**
 * PHP 守护进程
 *
 */
define('PATH', dirname(__FILE__).'/');

class Daemon {
    const VERSION = '1.0';
    const ERR     = -1;
    const OK      = 1;

    private $pid;
    private $childPids;
    private $pidFile;
    private $handler;
    private $processNum;
    private $argv;


    public function __construct() {
        global $argv;
        $this->processNum = 1;
        $this->argv = $argv;
        $this->setPidFile(PATH . 'daemon.pid');
        self::checkEnv();
    }

    public static function checkEnv() {
        if (!extension_loaded('pcntl')) {
            die("dmq need support of pcntl extension");
        }
        if ('cli' != php_sapi_name()) {
            die("dmq only works in CLI mode");
        }
    }

    public function setPidFile($filename) {
        $this->pidFile = $filename;
    }

    public function setHandle($handler) {
        $this->$handler = $$handler;
    }

    public function setProcessNum($num) {
        $this->processNum = $num;
    }

    public function run() {
        switch($this->argv[1]) {
            case 'start':
                $this->start();
                break;
            case 'stop':
                $this->stop();
                break;
            case 'restart':
                $this->restart();
                break;
            default:
                $this->usage();
                break;
        }
    }

    public function start() {
        if (is_file($this->pidFile)) {
            $this->msg("dmq is running, pid ". file_get_contents($this->pidFile) . ".");
        } else {
            if (empty($this->handler)) {
                $this->msg("process handle unregistered.");
                exit(self::ERR);
            }
            // 转为守护进程模式
            $this->daemonize();

            // 创建Worker进程
            for ($i=1; $i <= $this->processNum; $i++) {
                $pid = pcntl_fork();
                if ($pid == -1) {
                    $this->msg("fork process {$i}", self::ERR);
                } elseif ($pid) {
                    $this->childPids[$pid] = $i;
                } else {
                    return $this->handle($i);
                }
            }

            // 等待子进程
            while(count($this->childPids)) {
                $waitpid = pcntl_waitpid(-1, $status, WNOHANG);
                unset($this->childPids[$waitpid]);
                $this->checkPidFile();
                usleep(1000000);
            }
        }
    }

    public function stop() {
        if (!is_file($this->pidFile)) {
            $this->msg("dmq is not running");
        } else {
            $pid = @file_get_contents($this->pidFile);
            if (!@unlink($this->pidFile)) {
                $this->msg("remove pid file: $this->pidFile", self::ERR);
            }
            sleep(1);
            $this->msg("stopping {$this->argv[0]} {{$pid}}", self::OK);
        }
    }

    public function restart() {
        $this->stop();
        sleep(1);
        $this->start();
    }

    public function usage() {
        global $argv;
        echo str_pad('', 50, '-'),"\n";
        echo "DMQ v".self::VERSION."\n";
        echo "author: wangping<wangping.jx@gmail.com>\n";
        echo str_pad('', 50, '-')."\n";
        echo "usage:\n";
        echo "\t{$argv[0]} start\n";
        echo "\t{$argv[0]} stop\n";
        echo "\t{$argv[0]} restart\n";
        echo str_pad('', 50, '-')."\n";
    }

    //检查PID文件，如果文件不存在，则Kill全部子进程后退出
    private function checkPidFile() {
        clearstatcache();
        if (!is_file($this->pidFile)) {
            foreach ($this->childPids as $pid => $pno) {
                posix_kill($pid, SIGKILL);
            }
            exit;
        }
    }

    // 转为守护进程模式
    private function daemonize() {
        $pid = pcntl_fork();
        if ($pid == -1) {
            $this->msg("create main process", self::ERR);

        // 父进程
        } elseif ($pid) {
            $this->msg("starting {$this->argv[0]}", self::OK);
        // 子进程
        } else {
            posix_setsid();   // 使当前子进程成为session leader
            $this->pid = posix_getpid();
            file_put_contents($this->pidFile, $this->pid);
        }
    }



    // 执行用户处理函数
    private function handle($pno) {
        if ($this->handler) {
            call_user_func($this->handler, $pno);
        }
    }

    // 输出消息
    private function msg($msg, $msgno = 0) {
        if ($msgno == 0) {
            fprintf(STDIN, $msg."\n");
        } else {
            fprintf(STDIN, $msg . ".....");
            if ($msgno == self::OK) {
                fprintf(STDIN, $this->colorize('success', 'green'));
            } else {
                fprintf(STDIN, $this->colorize('failed', 'red'));
            }
            fprintf(STDIN, "\n");
        }
    }

    //在终端输出带颜色的文字
    private function colorize($text, $color, $bold = false) {
        $colors = array_flip(array(30 => 'gray', 'red', 'green', 'yellow', 'blue', 'purple', 'cyan', 'white', 'black'));
        return "\033[" . ($bold ? '1' : '0') . ';' . $colors[$color] . "m$text\033[0m";
    }
}
