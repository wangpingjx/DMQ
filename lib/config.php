<?php
namespace DMQ\Lib;

/**
 * 配置
 */
class Config {
    // 配置文件名称
    public static $configFile;

    // 配置数据
    public static $config = array();

    // 示例
    protected static $instances = null;

    private function __construct() {
        $config_file = DMQ_ROOT_DIR . 'conf/dmq.conf';
        if (!file_exists($config_file)) {
            throw new \Exception('Configuration file "' . $config_file . '" not found"');
        }
        self::$config['dmq'] = self::parseFile($config_file);
        self::$configFile = realpath($config_file);
        foreach(glob(DMQ_ROOT_DIR . 'conf/conf.d/*.conf') as $config_file) {
            $worker_name = basename($config_file, '.conf');
            self::$config[$worker_name] = self::parseFile($config_file);
        }
    }

    // 解析配置文件
    protected static function parseFile($config_file) {
        $config = parse_ini_file($config_file, true);
        if (!is_array($config) || empty($config)) {
            throw new \Exception('Invalid configuration format');
        }
        return $config;
    }

    // 获取实例
    public static function instance() {
        if (!self::$instances) {
            self::$instances = new self();
        }
    }

    // 获取配置
    public static function get($uri) {
        $node  = self::$config;
        $paths = explode('.', $uri);
        while(!empty($paths)) {
            $path = array_shift($paths);
            if (!isset($node[$path])) {
                return null;
            }
            $node = $node[$path];
        }
        return $node;
    }

    // 重新载入配置
    public static function reload() {
        self::$Instances = null;
        self::instance();
    }

    /**
     * 获取所有的workers
     * @return Array
     */
    public static function getAllWorkers() {
        $copy = self::$config;
        unset($copy['dmq']);
        return $copy;
    }
}
