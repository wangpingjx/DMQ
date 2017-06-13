#!/usr/bin/php
<?php
error_reporting(E_ALL);
ini_set('display_errors', 'on');
ini_set('limit_memory','512M');
ini_set('opcache.enable', false);
date_default_timezone_set('Asia/Shanghai');

if(empty($argv[1]))
{
    echo "Usage: dmq {start|stop|restart|reload|kill|status}".PHP_EOL;
    exit;
}

$cmd = $argv[1];

define('DMQ_ROOT_DIR', realpath(__DIR__."/../")."/");
chdir(DMQ_ROOT_DIR);

// 检查系统及PHP版本
if(0 === strpos(strtolower(PHP_OS), 'win')){
    exit("DMQ can not run on Windows operating system\n");
}
if (version_compare(PHP_VERSION,'5.3.0','<')){
    exit("DMQ PHP >= 5.3.0 required \n");
}

require_once DMQ_ROOT_DIR . 'lib/Master.php';

// pid file
require_once DMQ_ROOT_DIR . 'lib/Config.php';
DMQ\Lib\Config::instance();

if (!($pid_file = DMQ\Lib\Config::get('dmq.pid_file'))) {
    $pid_file = '/var/run/dmq.pid';
}
define('DMQ_PID_FILE', $pid_file);

// log dir
if (!($log_dir = DMQ\Lib\Config::get('dmq.log_dir'))) {
    $log_dir = DMQ_ROOT_DIR . 'log/';
}
define('DMQ_LOG_DIR', $log_dir .'/');

// 检查PID对应进程是否存在，不存在则删除PID文件
if ($cmd != 'status' && is_file(DMQ_PID_FILE)) {
    // 检查权限
    if (!posix_access(DMQ_PID_FILE, POSIX_W_OK)) {
        if ($stat = stat(DMQ_PID_FILE)) {
            if ( ($start_pwuid = posix_getpwuid($stat['uid'])) && ($current_pwuid = posix_getpwuid(posix_getuid())) ){
                exit("DMQ is started by user {$start_pwuid['name']}, {$current_pwuid['name']} can not $cmd DMQ, Permission denied.\nDMQ $cmd failed\n");
            }
        }
        exit("Can not $cmd DMQ, Permission denied\n");
    }
    // 检查pid进程是否存在
    if ($pid = @file_get_contents(DMQ_PID_FILE)) {
        if (false === posix_kill($pid, 0)) {
            if (!unlink(DMQ_PID_FILE)) {
                exit("Can not $cmd DMQ");
            }
        }
    }
}

// 必须是root启动 TODO 暂时注释
// if ($user_info = posix_getpwuid(posix_getuid())) {
//     if ($user_info['name'] !== 'root') {
//         exit("You should ran DMQ as root, Permission denied\n");
//     }
// }

switch($cmd) {
    case 'start':
        DMQ\Lib\Master::run();
        break;
    case 'stop':
        $pid = @file_get_contents(DMQ_PID_FILE);
        if (empty($pid)) {
            exit("DMQ not running\n");
        }
        stop_and_wait();
        break;
    case 'restart':
        stop_and_wait();
        DMQ\Lib\Master::run();
        break;
    case 'reload':
        $pid = @file_get_contents(DMQ_PID_FILE);
        if (empty($pid)) {
            exit("DMQ not running\n");
        }
        posix_kill($pid, SIGHUP);
        break;
    default:
        echo "Usage: dmq {start|stop|restart|reload}\n";
        exit;
}

function stop_and_wait($wait_time = 1) {
    $pid = @file_get_contents(DMQ_PID_FILE);
    if (empty($pid)) {
        // do nothing
    } else {
        $start_time = time();
        posix_kill($pid, SIGINT);
        while(is_file(DMQ_PID_FILE)) {
            clearstatcache();
            usleep(1000);
            if ( time() - $start_time >= $wait_time) {
                force_kill();
                unlink(DMQ_PID_FILE);
                usleep(500000);
                break;
            }
        }
        echo "DMQ stopped\n";
    }
}

function force_kill() {
    $ret = $match = array();
    exec("ps aux | grep -E '".DMQ\Lib\Master::NAME.":|DMQ' | grep -v grep", $ret);
    $this_pid  = posix_getpid();
    $this_ppid = posix_getppid();
    foreach ($ret as $line) {
        if (preg_match("/[\s]+\s+(\d+)\s+/", $line, $match)) {
            $tmp_pid = $match[1];
            echo "kill pid: ".$tmp_pid."\n";
            if ($this_pid != $tmp_pid && $this_ppid != $tmp_pid) {
                posix_kill($tmp_pid, SIGKILL);
            }
        }
    }
}
