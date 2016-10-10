<?php

/**
 *
 * 用这个worker实现短信服务
 * 每次从redis取一条数据，处理成功后写入数据库
 * 数据表根据月份分开创建
 *
 * @author walkor <walkor@workerman.net>
 */
class SmsWorker
{
    protected $redis = null;
    protected $sql_connect = null;
    static public $config = null;

    /**
     * 该worker进程开始服务的时候会触发一次
     * @return bool
     */
    public function start()
    {
        //echo getmypid()." start initRedis\n";
        $this->initRedis();
        $redis_key = $this->getConf('Sms_redis_key');
        //echo $redis_key."\n";
        while (true) {
            $data = $this->redis->lPop($redis_key);
            if (empty($data)) {
                usleep(300);
                continue;
            }
            $this->dealSms($data);
        }
        $this->end();
        return true;
    }

    /**
     * 获取配置信息
     * @param $str
     * @return mixed
     */
    protected function getConf($str)
    {
        return isset(self::$config[$str]) ? self::$config[$str] : '';
    }

    /**
     * 初始化redis
     *
     */
    protected function initRedis()
    {
        $this->redis = new Redis();
        //echo $this->getConf('redis_ip')."   ".$this->getConf('redis_port')."\n";
        $this->redis->connect($this->getConf('redis_ip'), $this->getConf('redis_port'));
        $this->redis->auth($this->getConf('requirepass'));
        $this->redis->select($this->getConf('redis_db'));
    }

    /**
     * 初始化mysql
     *
     */
    protected function initSql()
    {
        if($this->sql_connect == null) {
            $this->sql_connect = mysql_connect($this->getConf('db_ip'),$this->getConf('db_username'),$this->getConf('db_password'));
            mysql_select_db($this->getConf('db'), $this->sql_connect);
            mysql_query("set character set 'utf8'");//读库
            mysql_query("set names 'utf8'");//写库
        } else if(!mysql_ping()) {
            mysql_close($this->sql_connect);
            $this->sql_connect = mysql_connect($this->getConf('db_ip'),$this->getConf('db_username'),$this->getConf('db_password'));
            mysql_select_db($this->getConf('db'), $this->sql_connect);
            mysql_query("set character set 'utf8'");//读库
            mysql_query("set names 'utf8'");//写库
        }
    }

    /**
     * 回收资源
     *
     */
    protected function end()
    {
        $this->redis->close();
        mysql_close($this->sql_connect);
    }

    protected function sock_post($url,$query)
    {
        $data = "";
        $info=parse_url($url);
        $fp=fsockopen($info["host"],80,$errno,$errstr,30);
        if(!$fp){
            return $data;
        }
        $head="POST ".$info['path']." HTTP/1.0\r\n";
        $head.="Host: ".$info['host']."\r\n";
        $head.="Referer: http://".$info['host'].$info['path']."\r\n";
        $head.="Content-type: application/x-www-form-urlencoded\r\n";
        $head.="Content-Length: ".strlen(trim($query))."\r\n";
        $head.="\r\n";
        $head.=trim($query);
        $write=fputs($fp,$head);
        $header = "";
        while ($str = trim(fgets($fp,4096))) {
            $header.=$str;
        }
        while (!feof($fp)) {
            $data .= fgets($fp,4096);
        }
        return $data;
    }

    protected function send_sms($text, $mobile){
        $apikey = $this->getConf('sms_apikey');
        $url = $this->getConf('sms_send_url');
        $encoded_text = urlencode("$text");
        $post_string="apikey=$apikey&text=$encoded_text&mobile=$mobile";
        return $this->sock_post($url, $post_string);
    }

    /**
     * 处理短信
     * @param $sms_info
     * @return bool
     */
    protected function dealSms($sms_info)
    {
        //echo $sms_info."\n";
        $data = json_decode($sms_info,true);
        if (!$data) {
            return false;
        }
        $res = $this->send_sms($data['text'], $data['mobile']);
        //echo $res."\n";
        $result = json_decode($res, true);
        if(empty($result) || $result['code'] != 0) {
            if(!isset($data['send_reply'])) {
                $data['send_reply'] = 1;
                $this->redis->rPush($this->getConf('Sms_redis_key'), json_encode($data));
            } else {
                if(++$data['send_reply'] < 5) {
                    $this->redis->rPush($this->getConf('Sms_redis_key'), json_encode($data));
                } else {
                    //写入数据库
                    $cur_index = date('Y_m');
                    $title = "发送失败短信数据库";
                    $this->recordData("mss_sms_error_$cur_index", $result['msg'], $title, $data);
                }
            }
        } else {
            //echo "send successful \n";
            //写入数据库
            $cur_index = date('Y_m');
            $title = "已发送短信数据库";
            $this->recordData("mss_sms_$cur_index", '', $title, $data);
        }
        return true;
    }

    /**
     * 备份短信数据
     * @param $table
     * @param $remark
     * @param $title
     * @param $data
     */
    protected function recordData($table, $remark, $title, $data)
    {
        $this->initSql();
        $create_sql = "create table if not exists $table (
                            `sms_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                            `platform_id` int(10) unsigned NOT NULL COMMENT '平台id',
                            `sender` varchar(64) DEFAULT NULL COMMENT '发送者',
                            `mobile` text NOT NULL COMMENT '接收者手机号',
                            `text` text DEFAULT NULL COMMENT '消息内容',
                            `deal_time` datetime NOT NULL COMMENT '短信处理时间',
                            `remark` varchar(256) DEFAULT NULL COMMENT '备注',
                            PRIMARY KEY (`sms_id`),
                            KEY `mobile` (`mobile`(11))
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='$title'";
        //echo $create_sql."\n";
        mysql_query($create_sql);
        $insert_sql = "insert into $table values(NULL," . $data['platform_id'] . ",'" . $data['sender'] . "','" .
            $data['mobile'] . "','" . $data['text'] . "',now(),'" . $remark . "')";
        //echo $insert_sql."\n";
        mysql_query($insert_sql);
    }
}
