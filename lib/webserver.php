<?php
require_once DMQ_ROOT_DIR . 'lib/socket_worker.php';
require_once DMQ_ROOT_DIR . 'lib/protocols/http/http.php';
require_once DMQ_ROOT_DIR . 'lib/client.php';

/**
 *
 *  WebServer
 *  HTTP协议
 *
 * @author walkor <workerman.net>
 */
 class WebServer extends \DMQ\Lib\SocketWorker
 {
     /**
      * 缓存最多多少静态文件
      * @var integer
      */
     const MAX_CACHE_FILE_COUNT = 100;

     /**
      * 大于这个值则文件不缓存
      * @var integer
      */
     const MAX_CACHE_FILE_SIZE = 300000;

     /**
      * 缓存静态文件内容
      * @var array
      */
     public static $fileCache = array();

     /**
      * 默认mime类型
      * @var string
      */
     protected static $defaultMimeType = 'text/html; charset=utf-8';

     /**
      * 服务器名到文件路径的转换
      * @var array ['workerman.net'=>'/home', 'www.workerman.net'=>'home/www']
      */
     //  protected static $serverRoot = array();

     /**
      * 路由配置
      * @var string
      */
     protected static $routes = [
         ['POST',    '/',     'create'],
         ['GET',     '/',     'show'],
         ['DELETE',  '/',     'delete'],
     ];

     /**
      * 默认访问日志目录
      * @var string
      */
     protected static $defaultAccessLog = './logs/access.log';

     /**
      * 访问日志存储路径
      * @var array
      */
     protected static $accessLog = array();

     /**
      * mime类型映射关系
      * @var array
      */
     protected static $mimeTypeMap = array();

     /**
      * 进程启动的时候一些初始化工作
      * @see DMQ.SocketWorker::onStart()
      */
     public function onStart()
     {
         // 初始化HttpCache
         \DMQ\Lib\Protocols\Http\HttpCache::init();
         // 初始化mimeMap
         $this->initMimeTypeMap();
         // 初始化访问路径
         $this->initAccessLog();
     }

     /**
      * 初始化mimeType
      * @return void
      */
     public function initMimeTypeMap()
     {
         $mime_file = \DMQ\Lib\Config::get($this->workerName.'.include');
         if(!is_file($mime_file))
         {
             $this->notice("$mime_file mime.type file not fond");
             return;
         }
         $items = file($mime_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
         if(!is_array($items))
         {
             $this->notice("get $mime_file mime.type content fail");
             return;
         }
         foreach($items as $content)
         {
             if(preg_match("/\s*(\S+)\s+(\S.+)/", $content, $match))
             {
                 $mime_type = $match[1];
                 $extension_var = $match[2];
                 $extension_array = explode(' ', substr($extension_var, 0, -1));
                 foreach($extension_array as $extension)
                 {
                     self::$mimeTypeMap[$extension] = $mime_type;
                 }
             }
         }
     }

     /**
      * 初始化AccessLog
      * @return void
      */
     public function initAccessLog()
     {
         // 虚拟机访问日志目录
         self::$accessLog = \DMQ\Lib\Config::get($this->workerName.'.access_log');
         // 默认访问日志目录
         if($default_access_log =  \DMQ\Lib\Config::get($this->workerName.'.default_access_log'))
         {
             self::$defaultAccessLog = $default_access_log;
         }
     }

     /**
      * 确定数据是否接收完整
      * @see Man\Core.SocketWorker::dealInput()
      */
     public function dealInput($recv_str)
     {
         return \DMQ\Lib\Protocols\Http\http_input($recv_str);
     }

     /**
      * 数据接收完整后处理业务逻辑
      * @see Man\Core.SocketWorker::dealProcess()
      */
     public function dealProcess($recv_str)
     {
          // http请求处理开始。解析http协议，生成$_POST $_GET $_COOKIE
         \DMQ\Lib\Protocols\Http\http_start($recv_str);

         // 记录访问日志
         $this->logAccess($recv_str);

         // 请求的文件
         $url_info = parse_url($_SERVER['REQUEST_URI']);
         if(!$url_info)
         {
             \DMQ\Lib\Protocols\Http\header('HTTP/1.1 400 Bad Request');
             return $this->sendToClient(\DMQ\Lib\Protocols\Http\http_end('<h1>400 Bad Request</h1>'));
         }
         $path = $url_info['path'];

         # 哈哈，一个小型路由
         $index = false;
         foreach (self::$routes as $key => $value) {
            if ($value[0] == $_SERVER['REQUEST_METHOD'] && $value[1] == $path) {
                 $index = $key;
                 break;
             }
         }
         if (false === $index) {
             \DMQ\Lib\Protocols\Http\header("HTTP/1.1 404 Not Found");
             return $this->sendToClient(\DMQ\Lib\Protocols\Http\http_end(json_encode(['code' => 404, 'result' => ''])));
         }
         $action = self::$routes[$index][2];
         $result = $this->$action($_REQUEST);
         \DMQ\Lib\Protocols\Http\header('HTTP/1.1 200 OK');
         return $this->sendToClient(\DMQ\Lib\Protocols\Http\http_end(json_encode(['code' => 200, 'result' => $result])));
     }

     /**
     * 记录访问日志
     * @param unknown_type $recv_str
     */
    public function logAccess($recv_str)
    {
        // 记录访问日志
        $log_data = date('Y-m-d H:i:s') . "\t REMOTE:" . $this->getRemoteAddress()."\n$recv_str";
        if(isset(self::$accessLog[$_SERVER['HTTP_HOST']]))
        {
            file_put_contents(self::$accessLog[$_SERVER['HTTP_HOST']], $log_data, FILE_APPEND);
        }
        else
        {
            file_put_contents(self::$defaultAccessLog, $log_data, FILE_APPEND);
        }
    }

    // 对队列的操作
    public function create($request) {
        $name = $request['name'];
        $data = json_decode($request['data'], true);
        if (empty($name) || empty($data) || empty($data['at'])) {
            return false;
        }
        $objClient = new DMQ\Lib\Client();
        $objClient->zadd($name, $data);
        return true;
    }

    public function show($request) {
        $name = $request['name'];
        $objClient = new DMQ\Lib\Client();
        if (empty($name)) {
            return false;
        }
        return $objClient->zrange($name);
    }

    public function delete($request) {
        // $name = $request['name'];
        // $data = $request['data'];
        // if (empty($name) || empty($data)) {
        //     return false;
        // }
        // $objClient = new DMQ\Lib\Client();
        // $objClient->zrem($name, $data);
        return true;
    }
}
