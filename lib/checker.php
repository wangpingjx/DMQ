<?php
namespace DMQ\Lib;

/**
 * 环境检查
 */
class Checker {
    // 检查配置的pid文件是否可写
    public static function checkPidFile() {
        # 已经有进程pid可能server已经启动
        if ($content = @file_get_contents(DMQ_PID_FILE)) {
            Master::notice("DMQ already started\n");
            exit;
        }
        if (is_dir("DMQ_PID_FILE")) {
            exit("pid file ".DMQ_PID_FILE." is directory, DMQ start faild\n");
        }

        $pid_dir = dirname(DMQ_PID_FILE);
        if (!is_dir($pid_dir)) {
            if (!mkdir($pid_dir, 0777, true)) {
                exit("Create dir $pid_idr fail\n");
            }
        }
        if (!is_writable($pid_dir)) {
            exit("$pid_dir is not writable, can't write pid file. DMQ start failed\n");
        }
    }

    // 检查扩展支持情况
    public static function checkExtension() {
        $need_map = [
            'posix'     => true,
            'pcntl'     => true,
            'proctitle' => false,
        ];
        // 检查每个扩展支持情况
        $pad_length = 26;
        foreach($need_map as $ext_name => $required) {
            $support = extension_loaded($ext_name);
            if ($required && !$support) {
                echo "-----EXTENSION-----\n";
                Master::notice($ext_name. " [NOT SUPORT BUT REQUIRED] \tYou have to enable {$ext_name} \tDMQ start fail\n");
                exit(str_pad('* '.$ext_name, $pad_length) . " [NOT SUPORT BUT REQUIRED] \tYou have to enable {$ext_name} \tDMQ start fail\n");
            }

            if (!$support) {
                if (!isset($had_print_ext_info)) {
                    $had_print_ext_info = '';
                    echo "-----EXTENSION-----\n";
                }
                echo '* ', str_pad($ext_name, $pad_length), " [NOT SUPORT]\n";
            }
        }
    }

    // 检查禁用的函数
    public static function checkDisableFunction() {
        // 可能禁用的函数
        $check_func_map = [
            'pcntl_signal_dispatch',
            'exec',
        ];
        // 获取php.ini中设置的禁用函数
        if ($disable_func_string = ini_get("disable_functions")) {
            $disable_fuc_map = array_flit(explode(',', $disable_func_string));
        }
        // 遍历查看是否有禁用的函数
        foreach ($check_func_map as $func) {
            if (isset($disable_fuc_map[$func])) {
                Master::notice("Function $func may be disabled\tPlease check disable_functions in php.ini \t DMQ start fail\n");
                exit("\nFunction $func may be disabled\tPlease check disable_functions in php.ini \t DMQ start fail");
            }
        }
    }

    /**
     * 检查worker文件是否有语法错误
     * @param string $worker_name
     * @return int 0:无语法错误 其它:可能有语法错误
     */
    public static function checkSyntaxError($file, $class_name = null) {
        $pid = pcntl_fork();
        // 父进程
        if ($pid > 0) {
            // 退出状态不为0说明可能有语法错误
            $pid = pcntl_wait($status);
            return $status;
        // 子进程
        } elseif ($pid == 0) {
            ini_set('display_errors', 'off');
            // 载入对应worker
            require $file;
            if ($class_name && !class_exists($class_name)) {
                throw new \Exception("Class $class_name not exists");
            }
            exit(0);
        }
    }
}
