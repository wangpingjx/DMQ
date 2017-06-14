<?php
require_once DMQ_ROOT_DIR . 'lib/socket_worker.php';
/**
 *  SocketWorker 监听某个端口，对外提供网络服务的worker
 */
class Server extends \DMQ\Lib\SocketWorker {

    public static function check($buffer) {
        // 方案一
        // 根据协议, 如果最后一个字符是\n代表数据读取完整，返回0
        // if($buffer[strlen($buffer)-1] === "\n") {
        //     // 说明还有请求数据没收到，但是由于不知道还有多少数据没收到，所以只能返回1，因为有可能下一个字符就是\n
        //     return 0;
        // }
        // // 说明还有请求数据没收到，但是由于不知道还有多少数据没收到，所以只能返回1，因为有可能下一个字符就是\n
        // return 1;

        // 方案二
        // 接收到的数据长度
        // $recv_len = strlen($buffer);
        //
        // // 如果接收的长度还不够四字节，那么要等够四字节才能解包到请求长度
        // if($recv_len < 4)
        // {
        //     return 4 - $recv_len; // 不够四字节，等够四字节
        // }
        // // 从请求数据头部解包出整体数据长度
        // $unpack_data = unpack('Ntotal_len', $buffer);
        // $total_len = $unpack_data['total_len'];
        //
        // // 返回还差多少字节没有收到，这里可能返回0，代表请求接收完整
        // return $total_len - $recv_len;
    }

    // 打包
    public static function encode($data)
    {
        // 选用json格式化数据
        $buffer = json_encode($data);
        // 包的整体长度为json长度加首部四个字节(首部数据包长度存储占用空间)
        $total_length = 4 + strlen($buffer);
        return pack('N', $total_length) . $buffer;
    }

    // 解包
    public static function decode($buffer)
    {
        $buffer_data = unpack('Ntotal_length', $buffer);
        // 得到这次数据的整体长度（字节）
        $total_length = $buffer_data['total_length'];
        // json的数据
        $json_string = substr($buffer, 4);
        return json_decode($json_string, true);
    }

    // 请求周期第一步，根据应用层协议判断数据是否接收完毕
    public function dealInput($recv_buffer){
        // 判断数据是否接收完毕
        return self::check($recv_buffer);
    }

    // workerman请求周期第二步，请求接收完毕后根据接收到的数据运行对应的业务逻辑
    public function dealProcess($recv_buffer){
        // 方案一
        // 去除末尾\n，得到完整json字符串
        $json_str = trim($recv_buffer);
        // 根据json字符长解析出$req_data
        $req_data = json_decode($json_str, true);

        // 根据$req_data的值进入不同的处理逻辑
        // ...............

        // 方案二
        // // 得到json数据
        // $json_data = self::decode($recv_buffer);
        //
        // /**
        //   *  这里根据你的json_data内容出处理不同的业务逻辑
        //  **/
        //
        // // 如果有需要，可以向客户端发送结果
        // $this->sendToClient(self::encode(array('code'=>0, 'msg'=>'ok')));
    }
}
