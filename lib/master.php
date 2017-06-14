<?php
namespace DMQ\Lib;

if(!defined('DMQ_ROOT_DIR')){
    define('DMQ_ROOT_DIR', realpath(__DIR__)."/");
}

require_once DMQ_ROOT_DIR . 'lib/checker.php';
require_once DMQ_ROOT_DIR . 'lib/config.php';
require_once DMQ_ROOT_DIR . 'lib/log.php';
require_once DMQ_ROOT_DIR . 'lib/redis_conn.php';
require_once DMQ_ROOT_DIR . 'lib/client.php';

class Master {
    /**
     * 版本
     * @var string
     */
    const VERSION = '1.0';

    /**
     * 服务名
     * @var string
     */
    const NAME = 'DMQ';

    /**
     * 服务状态 启动中
     * @var integer
     */
    const STATUS_STARTING = 1;

    /**
     * 服务状态 运行中
     * @var integer
     */
    const STATUS_RUNNING = 2;

    /**
     * 服务状态 关闭中
     * @var integer
     */
    const STATUS_SHUTDOWN = 4;

    /**
     * 服务状态 平滑重启中
     * @var integer
     */
    const STATUS_RESTARTING_WORKERS = 8;

    /**
     * 整个服务能够启动的最大进程数
     * @var integer
     */
    const SERVER_MAX_WORKER_COUNT = 20;

    /**
     * 单个进程打开文件数限制
     * @var integer
     */
    const MIN_SOFT_OPEN_FILES = 10000;

    /**
     * 单个进程打开文件数限制 硬性限制
     * @var integer
     */
    const MIN_HARD_OPEN_FILES = 10000;

    /**
     * 共享内存中用于存储主进程统计信息的变量id
     * @var integer
     */
    const STATUS_VAR_ID = 1;

    /**
     * 发送停止命令多久后worker没退出则发送sigkill信号
     * @var integer
     */
    const KILL_WORKER_TIME_LONG = 4;

    /**
     * 用于保存所有子进程pid ['worker_name1'=>[pid1=>pid1,pid2=>pid2,..], 'worker_name2'=>[pid7,..], ...]
     * @var array
     */
    protected static $workerPidMap = array();

    /**
     * 服务的状态，默认是启动中
     * @var integer
     */
    protected static $serviceStatus = self::STATUS_STARTING;

    /**
     * 用来监听端口的Socket数组，用来fork worker使用
     * @var array
     */
    protected static $listenedSocketsArray = array();

    /**
     * 工作单元数组，用来fork worker使用
     * @var array
     */
    protected static $processorArray = array();

    /**
     * 要重启r的pid数组 [pid1=>time_stamp, pid2=>time_stamp, ..]
     * @var array
     */
    protected static $pidsToRestart = array();

    /**
     * master进程pid
     * @var integer
     */
    protected static $masterPid = 0;

    /**
     * server统计信息 ['start_time'=>time_stamp, 'worker_exit_code'=>['worker_name1'=>[code1=>count1, code2=>count2,..], 'worker_name2'=>[code3=>count3,...], ..] ]
     * @var array
     */
    protected static $serviceStatusInfo = array(
        'start_time' => 0,
        'worker_exit_code' => array(),
    );

    /**
     * 服务运行
     * @return void
     */
    public static function run() {
        // 输出启动信息
        self::notice("DMQ starting...\n");
        // 初始化
        self::init();
        // 检查环境
        self::checkEnv();
        // 变成守护进程
        self::daemonize();
        // 保存进程pid
        self::savepid();
        // 安装信号
        self::installSignal();
        // 创建监听socket
        self::createListeningSockets();
        // 创建Worker进程
        self::createWorkers();
        // 输出启动信息
        self::notice("DMQ start success\n");
        // 标记服务状态为运行中
        self::$serviceStatus = self::STATUS_RUNNING;
        // 关闭标准输出
        // self::resetStdFd();
        // 主进程循环
        self::loop();
    }

    /**
     * 初始化 配置、进程名、共享内存、消息队列等
     * @return void
     */
    public static function init() {
        // 获取配置文件
        // 设置进程名称
        self::setProcTitle(self::NAME.':master');
        // 初始化进程共享内存消息队列
    }

    public static function notice($msg) {
        echo $msg;
    }

    // 设置进程名称，需要proctitle支持或者php>=5.5
    protected static function setProcTitle($title) {
        if (function_exists('cli_set_process_title')) {
            @cli_set_process_title($title);
        } elseif ( extension_loaded('proctitle') && function_exists('setproctitle') ) {
            @setproctitle($title);
        }
    }

    public static function checkEnv() {
        // 检查PID文件
        Checker::checkPidFile();
        // 检查扩展支持情况
        Checker::checkExtension();
        // 检查函数禁用情况
        Checker::checkDisableFunction();
        // 检查log目录是否可读
        // Log::init();
        // 检查配置和语法错误
        // Checker::checkConfig();
        // 检查文件限制
        // Checker::checkLimit();
    }

    // 使进程脱离终端，变为守护进程
    protected static function daemonize() {
        umask(0);
        // fork子进程
        $pid = pcntl_fork();
        if (-1 == $pid) {
            exit("Can not fork");
        } elseif ($pid > 0) {
            // 父进程退出
            exit(0);
        }
        // 成为session leader
        if (-1 == posix_setsid()) {
            // 出错退出
            exit("Setsid fail");
        }
        // 再fork一次
        $pid2 = pcntl_fork();
        if (-1 == $pid2) {
            exit("Can not fork");
        } elseif ( 0 !=  $pid2) {
            exit(0);
        }
        // 记录服务启动时间
        self::$serviceStatusInfo['start_time'] = time();
    }

    /**
     * 保存主进程pid
     * @return void
     */
    public static function savePid() {
        // 保存在变量中
        self::$masterPid = posix_getpid();

        // 保存到文件中，实现停止、重启
        if (false === @file_put_contents(DMQ_PID_FILE, self::$masterPid)) {
            exit("Can not save pid to pid file(" . DMQ_PID_FILE .") Server start fail\n");
        }

        // 更改权限
        chmod(DMQ_PID_FILE, 0644);
    }

    /**
     * 安装信号控制器
     * @return void
     */
     protected static function installSignal() {
        //  设置终止信号处理函数
        pcntl_signal(SIGINT,  array('DMQ\Lib\Master', 'signalHandler'), false);
        // 设置SIGUSR1信号处理函数,测试用
        pcntl_signal(SIGUSR1, array('DMQ\Lib\Master', 'signalHandler'), false);
        // 设置SIGHUP信号处理函数,平滑重启Server
        pcntl_signal(SIGHUP,  array('DMQ\Lib\Master', 'signalHandler'), false);
        // 设置子进程退出信号处理函数
        pcntl_signal(SIGCHLD, array('DMQ\Lib\Master', 'signalHandler'), false);

        // 设置忽略信号
        pcntl_signal(SIGPIPE, SIG_IGN);
        pcntl_signal(SIGTTIN, SIG_IGN);
        pcntl_signal(SIGTTOU, SIG_IGN);
        pcntl_signal(SIGQUIT, SIG_IGN);
        pcntl_signal(SIGALRM, SIG_IGN);
     }

     /**
      * 根据配置文件，创建监听套接字
      * @return void
      */
     protected static function createListeningSockets() {
         foreach (\DMQ\Lib\Config::getAllWorkers() as $worker_name => $config) {
             if (isset($config['listen'])) {
                 $flags     = substr($config['listen'], 0, 3) == 'udp' ? STREAM_SERVER_BIND : STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
                 $error_no  = 0;
                 $error_msg = '';
                 // 创建监听socket
                 self::$listenedSocketsArray[$worker_name] = stream_socket_server($config['listen'], $error_no, $error_msg, $flags);
                 if (!self::$listenedSocketsArray[$worker_name]) {
                     \DMQ\Lib\Log::add("can not create socket {$config['listen']} info:{$error_no} {$error_msg} Server start fail");
                     exit("\nCan not create socket {$config['listen']} {$error_msg}, Server start fail\n");
                 }
             }
         }
     }

     /**
      * 创建 Workers 进程
      * @return void
      */
     protected static function createWorkers() {
         foreach (\DMQ\Lib\Config::getAllWorkers() as $worker_name => $config) {
            // 初始化
            if (empty(self::$workerPidMap[$worker_name])) {
                self::$workerPidMap[$worker_name] = array();
            }

            while(count(self::$workerPidMap[$worker_name]) < $config['start_workers']) {
                // 子进程退出
                if (self::createWorker($worker_name) == 0) {
                    self::notice("worker exit unexpected");
                    exit(500);
                }
            }
         }
     }

    /**
     * 创建一个 worker 进程
     * @param string $worker_name 服务名
     * @return int  父进程: >0 得到新的 worker pid; < 0 出错; 子进程始终为0;
     */
    protected static function createWorker($worker_name) {
        echo "=> create worker: $worker_name\n";

        // 创建子进程
        $pid = pcntl_fork();

        // 先处理收到的信号
        pcntl_signal_dispatch();

        // 父进程
        if ($pid > 0) {
            // 初始化master相关
            self::$workerPidMap[$worker_name][$pid] = $pid;
            return $pid;
        } elseif ($pid === 0){
            // 忽略信号
            self::ignoreSignal();

            // 关闭不用的监听socket TODO why?
            foreach (self::$listenedSocketsArray as $tmp_worker_name  => $tmp_socket) {
                if ($tmp_worker_name != $worker_name) {
                    fclose($tmp_socket);
                }
            }

            // 尝试以指定的用户运行worker进程
            if ($worker_user = Config::get($worker_name.'.user')) {
                self::setProcUser($worker_user);
            }
            // 关闭输出
            // self::resetStdFd(Config::get($worker_name.'.no_debug'));

            // 尝试设置子进程名称
            self::setWorkerProcTitle($worker_name);

            // 包含必要文件

            // 查找worker文件
            $worker_file = Config::get($worker_name.'.worker_file');
            $class_name = basename($worker_file, '.php');

            // 如果有语法错误 sleep 5秒 避免狂刷日志
            if (Checker::checkSyntaxError($worker_file, $class_name)) {
                sleep(5);
            }
            require_once $worker_file;
            // 创建实例
            $worker = new $class_name();

            // 如果改worker有配置监听端口，则将监听端口的socket传递给子进程
            if (isset(self::$listenedSocketsArray[$worker_name])) {
                $worker->setListendSocket(self::$listenedSocketsArray[$worker_name]);
            }

            // 使worker开始服务
            $worker->start();
            return 0;

        } else { // 出错
             self::notice("create worker fail worker_name:$worker_name detail:pcntl_fork fail");
             return $pid;
        }
    }

    /**
     * 设置运行用户
     * @param string $worker_user
     * @return void
     */
    protected static function setProcUser($worker_user) {
        $user_info = posix_getpwnam($worker_user);
        if ($user_info['uid'] != posix_getuid() || $user_info['gid'] != posix_getpid()) {
            // 尝试设置 gid uid
            if (!posix_setgid($user_info['gid']) || !posix_setuid($user_info['uid'])) {
                self::notice( 'Notice : Can not run woker as '.$worker_user." , You shuld be root\n", true);
            }
        }
    }

    /**
     * 关闭标准输入输出(TODO 不太明白)
     * @return void
     */
    protected static function resetStdFd($force = false) {
        // 如果此进程配置是no_debug, 则关闭输出
        if (!$force) {
            // 开发环境不关闭标准输出，用于调试
            if (Config::get('dmq.debug') === 1 && posix_ttyname(STDOUT)) {
                return ;
            }
        }
        global $STDOUT, $STDERR;
        @fclose(STDOUT);
        @fclose(STDERR);
        // 将标准输出重定向到/dev/null
        $STDOUT = fopen('/dev/null', 'rw+');
        $STDERR = fopen('/dev/null', 'rw+');
    }

    /**
     * 设置忽略信号
     * @return void
     */
    protected static function ignoreSignal() {
        // 设置忽略信号
        pcntl_signal(SIGPIPE, SIG_IGN);
        pcntl_signal(SIGTTIN, SIG_IGN);
        pcntl_signal(SIGTTOU, SIG_IGN);
        pcntl_signal(SIGQUIT, SIG_IGN);
        pcntl_signal(SIGALRM, SIG_IGN);
        pcntl_signal(SIGINT,  SIG_IGN);
        pcntl_signal(SIGUSR1, SIG_IGN);
        pcntl_signal(SIGHUP,  SIG_IGN);
    }
    /**
     * 设置子进程的名称
     * @param string $worker_name
     * @return void
     */
    public static function setWorkerProcTitle($worker_name) {
         self::setProcTitle(self::NAME.":worker $worker_name");
    }

    /**
     * 主进程主循环，监听子进程退出、服务终止、平滑重启等
     * @return void
     */
    public static function loop() {
        while(true) {
            sleep(1);
            // 检查是否有进程退出
            self::checkWorkers();
            // 触发信号处理
            pcntl_signal_dispatch();
        }
    }

    /**
     * 设置server信号处理函数
     * @param int $signal
     * @return void
     */
    public static function signalHandler($signal) {
        switch($signal) {
            // 停止服务信号：
            case SIGINT:
                self::notice("DMQ is shutting down\n");
                self::stop();
                break;
            // 测试用
            case SIGUSR1:
                break;
            // worker退出信号
            case SIGCHLD:
                break;
            // 平滑重启server
            case SIGHUP:
                Config::reload();
                self::notice("DMQ reloading");
                $pid_worker_name_map = self::getPidWorkerNameMap();
                $pids_to_restart     = array_keys($pid_worker_name_map);
                self::addToRestartPids($pids_to_restart);
                self::restartPids();
                break;
        }
    }

    /**
     * 停止服务
     * @return void
     */
    public static function stop() {
        // 如果么有子进程则直接退出
        $all_worker_pid = self::getPidWorkerNameMap();
        if (empty($all_worker_pid)) {
            exit(0);
        }
        // 标记server开始关闭
        self::$serviceStatus = self::STATUS_SHUTDOWN;

    }

    /**
     * 获取 pid 到 worker_name 的映射
     * @return ['pid1' => 'worker_name1', 'pid2' => 'worker_name2']
     */
    public static function getPidWorkerNameMap() {
        $all_pid = array();
        foreach (self::$workerPidMap as $worker_name => $pid_array ) {
            foreach ($pid_array as $pid) {
                $all_pid[$pid] = $worker_name;
            }
        }
        return $all_pid;
    }

    /**
     * 监控worker进程状态，退出重启
     */
    public static function checkWorkers() {
        // 由于SIGCHLD信号可能重叠导致信号丢失，所以这里要循环获取所有退出的进程id
        while (($pid = pcntl_waitpid(-1, $status, WUNTRACED | WNOHANG)) != 0) {
            // 如果是重启的进程，则继续重启进程
            if (isset(self::$pidsToRestart[$pid]) && self::$serviceStatus != self::STATUS_SHUTDOWN) {
                unset(self::$pidsToRestart[$pid]);
                self::restartPids();
            }

            // 出错
            if ($pid < 0) {
                self::notice('pcntl_waitpid return '.$pid.' and pcntl_get_last_error = '.pcntl_get_last_error());
                return $pid;
            }

            // 查找子进程对应的workername
            $pid_worker_name_map = self::getPidWorkerNameMap();
            $worker_name = isset($pid_worker_name_map[$pid]) ? $pid_worker_name_map[$pid] : '';
            // 没有找到workername说明出错了
            if (empty($worker_name)) {
                self::notcie("child exist but not found worker_name pid:$pid");
                break;
            }

            // 进程退出状态不是0，说明有问题
            if ($status != 0) {
                self::notice("worker[$pid:$worker_name] exit with status $status");
            }

            // 记录进程退出状态
            self::$serviceStatusInfo['worker_exit_code'][$worker_name][$status] = isset(self::$serviceStatusInfo['worker_exit_code'][$worker_name][$status]) ? self::$serviceStatusInfo['worker_exit_code'][$worker_name][$status] + 1 : 1;
            // 更新状态到共享内存 TODO for what？

            // 清理进程数据
            self::clearWorker($worker_name, $pid);

            // 如果服务不是关闭中
            if (self::$serviceStatus != self::STATUS_SHUTDOWN) {
                // 重新创建worker
                self::createWorkers();

            } else {
                $all_worker_pid = self::getPidWorkerNameMap();
                if (empty($all_worker_pid)) {
                    // 删除共享内存 TODO
                    self::notice("DMQ stoped");
                    @unline(DMQ_PID_FILE);
                    exit(0);
                }
            }
        }
    }

    /**
     * worker进程退出时，master进程的一些清理工作
     * @param string $worker_name
     * @param int    $pid
     * @return void
     */
    protected static function clearWorker($worker_name, $pid) {
        // 释放一些不用数据
        unset(self::$pidsToRestart[$pid], self::$workerPidMap[$worker_name][$pid]);
    }

    /**
     * 加入重启队列中
     * @param array $restart_pids
     * @return void
     */
    public static function addToRestartPids($restart_pids) {
        if (!is_array($restart_pids)) {
            self::notice("addToRestartPids(".var_export($restart_pids, true).") \$restart_pids not array");
            return false;
        }

        // 将 pid 放入重启队列
        foreach ($restart_pids as $pid) {
            if (!isset(self::$pidsToRestart[$pid])) {
                // 重启时间=0
                self::$pidsToRestart[$pid] = 0;
            }
        }
    }

    /**
     * 重启pid
     * @return void
     */
     public static function restartPids() {
        //  标记server状态
        if (self::$serviceStatus != self::STATUS_RESTARTING_WORKERS && self::$serviceStatus != self::STATUS_SHUTDOWN) {
            self::$serviceStatus = self::STATUS_RESTARTING_WORKERS;
        }

        if (empty(self::$pidsToRestart)) {
            self::$serviceStatus = self::STATUS_RUNNING;
            self::notice("DMQ restart success");
            return true;
        }

        // 遍历并重启，记录重启时间
        foreach (self::$pidsToRestart as $pid => $stop_time) {
            if ($stop_time == 0) {
                self::$pidsToRestart[$pid] = time();
                posix_kill($pid, SIGHUP);
                # TODO 超时则强制杀掉
                break;
            }
        }
    }
}
?>
