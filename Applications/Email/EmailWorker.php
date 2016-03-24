<?php

require_once __DIR__ . '/../../PHPMailer/PHPMailerAutoload.php';
/**
 *
 * 用这个worker实现邮件服务
 * 每次从redis取一条数据，处理成功后写入数据库
 * 数据表根据月份分开创建
 *
 * @author walkor <walkor@workerman.net>
 */
class EmailWorker
{
    protected $mailer = null;
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
        //echo getmypid()." start initMailer\n";
        $this->initMailer();
        $redis_key = $this->getConf('Email_redis_key');
        while (true) {
            $data = $this->redis->lPop($redis_key);
            if (empty($data)) {
                usleep(300);
                continue;
            }
            $this->dealMail($data);
        }
        $this->end();
        return true;
    }

    /** 获取配置信息
     * @param $str
     * @return mixed
     */
    protected function getConf($str)
    {
        return isset(self::$config[$str]) ? self::$config[$str] : '';
    }

    /** 初始化redis
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

    /** 初始化mysql
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

    /** 回收资源
     *
     */
    protected function end()
    {
        $this->redis->close();
        unset($this->mailer);
        mysql_close($this->sql_connect);
    }

    /** 初始化phpmailer
     *
     */
    protected function initMailer()
    {
        unset($this->mailer);
        $this->mailer = new PHPMailer();
        $host = $this->getConf('mail_host'); // SMTP server
        $port = $this->getConf('mail_port');
        $charSet = $this->getConf('mail_charset'); //mail charset
        $encoding = $this->getConf('mail_encoding'); //mail encoding
        //$sender = $this->getConf('mail_sender'); //email sender account
        //$sender_password = $this->getConf('mail_password'); //email send account password
        //$sender_name = 'CloudXNS'; //email sender title
        //$sender_name = "=?utf-8?B?" . base64_encode($sender_name) . "?=";
        $this->mailer->IsSMTP();
        $this->mailer->SMTPKeepAlive = true; //keep SMTP connection
        $this->mailer->SMTPDebug = $this->getConf('mail_smtpdebug');
        $this->mailer->SMTPAuth = $this->getConf('mail_smtpauth');
        $this->mailer->Host = $host;
        $this->mailer->Port = $port;
        //$this->mailer->Username = $sender;
        //$this->mailer->Password = $sender_password;
        //$this->mailer->From = $sender;
        //$this->mailer->SetFrom($sender, $sender_name);
        //$this->mailer->AddReplyTo($sender, $sender_name);
        $this->mailer->IsHTML();
        $this->mailer->CharSet = $charSet;
        $this->mailer->Encoding = $encoding;

        //add by wuzx 2015-07-20 添加Hostname字段
        $this->mailer->Hostname = 'cloudxns.net';
    }

    /** 处理邮件
     * @param $mail_info
     * @return bool
     */
    protected function dealMail($mail_info)
    {
        //echo $mail_info."\n";
        $data = json_decode($mail_info,true);
        if (!$data) {
            return false;
        }
        if (!$this->mailer->getSMTPInstance()->noop()) {
        //echo getmypid()." noop init\n";
            $this->initMailer();
        }
        if (isset($data['sender'])) {
            $sender_name = "=?utf-8?B?" . base64_encode($data['sender']) . "?=";
            //$this->mailer->SetFrom($this->mailer->Username, $sender_name);
            $this->mailer->FromName = $sender_name;
            $this->mailer->AddReplyTo($this->mailer->Username, $sender_name);
        }
        if (isset($data['to_email']) && isset($data['to_name'])) {
            $to_email_array = explode(';', $data['to_email']);
            $to_name_array = explode(';', $data['to_name']);
            for ($i = 0; $i < count($to_email_array); ++$i) {
                $this->mailer->AddAddress($to_email_array[$i], isset($to_name_array[$i]) ? $to_name_array[$i] : '');
            }
        }
        if (isset($data['subject'])) {
            $this->mailer->Subject = "=?utf-8?B?" . base64_encode($data['subject']) . "?=";
        }
        if (isset($data['content'])) {
            $this->mailer->MsgHTML($data['content']);
        }
        /* 通过二进制字符串发送附件
        * $v[0] 经过base64_encode的文件二进制内容
        * $v[1] 附件显示的名称
        * $v[2] 文件编码，默认base64
        * $v[3] 文件类型，JPEG images use 'image/jpeg', GIF uses 'image/gif', PNG uses 'image/png'
        */
        if (isset($data['stringattachment']) && is_array($data['stringattachment'])) {
            foreach ($data['stringattachment'] as $v) {
                if (is_array($v) && count($v) >= 4) {
                    $this->mailer->addStringAttachment(base64_decode($v[0]), $v[1], $v[2], $v[3]);
                }
            }
        }

        /* 通过二进制字符串发送本地附件，用于显示在邮件内容里
        * $v[0] 经过base64_encode的文件二进制内容
        * $v[1] 内容id，html通过该id获取附件，例如<img src="cid:$v[1]" alt="" width="30" height="1">
        * $v[2] 附件显示的名称
        * $v[3] 文件编码，默认base64
        * $v[4] 文件类型，JPEG images use 'image/jpeg', GIF uses 'image/gif', PNG uses 'image/png'
        */
        if (isset($data['stringembeddedimage']) && is_array($data['stringembeddedimage'])) {
            foreach ($data['stringembeddedimage'] as $v) {
                if (is_array($v) && count($v) >= 5) {
                    $this->mailer->addStringEmbeddedImage(base64_decode($v[0]), $v[1], $v[2], $v[3], $v[4]);
                }
            }
        }

        //add by wuzx 2015-09-08 区分平台
        $sender_password = $this->getConf('mail_password');
        $this->mailer->Password = $sender_password[$data['platform_id']];
        $sender = $this->getConf('mail_sender');
        $this->mailer->Username = $sender[$data['platform_id']];
        $this->mailer->From = $sender[$data['platform_id']];

	//保持时间一致
        $this->mailer->MessageDate = date('D, j M Y H:i:s O');
        if ($this->mailer->Send()) {
            //echo "send successful \n";
            $this->mailer->ClearAddresses();
            //写入数据库
            $cur_index = date('Y_m');
            $title = "已发送邮件数据库";
            $this->recordData("mss_email_$cur_index", '', $title, $data);
        } else {
            $error_info = $this->mailer->ErrorInfo;
            //echo getmypid()." send init\n";
            $this->initMailer();
            if(!isset($data['send_reply'])) {
                $data['send_reply'] = 1;
                $this->redis->rPush($this->getConf('Email_redis_key'), json_encode($data));
            } else {
                if(++$data['send_reply'] < 5) {
                    $this->redis->rPush($this->getConf('Email_redis_key'), json_encode($data));
                } else {
                //写入数据库
                $cur_index = date('Y_m');
                $title = "发送失败邮件数据库";
                $this->recordData("mss_email_error_$cur_index", $error_info, $title, $data);
                }
            }
        }
        return true;
    }

    /** 备份邮件数据
     * @param $table
     * @param $remark
     * @param $title
     * @param $data
     */
    protected function recordData($table, $remark, $title, $data)
    {
        $this->initSql();
        $create_sql = "create table if not exists $table (
                            `email_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                            `platform_id` int(10) unsigned NOT NULL COMMENT '平台id',
                            `sender` varchar(64) DEFAULT NULL COMMENT '发送者',
                            `to_email` text NOT NULL COMMENT '接收者email',
                            `to_name` text DEFAULT NULL COMMENT '接收者名称',
                            `subject` varchar(256) DEFAULT NULL COMMENT '邮件标题',
                            `content` text DEFAULT NULL COMMENT '邮件内容',
                            `deal_time` datetime NOT NULL COMMENT '邮件处理时间',
                            `remark` varchar(256) DEFAULT NULL COMMENT '备注',
                            PRIMARY KEY (`email_id`),
                            KEY `to_email` (`to_email`(20))
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='$title'";
        //echo $create_sql."\n";
        mysql_query($create_sql);
        $insert_sql = "insert into $table values(NULL," . $data['platform_id'] . ",'" . $data['sender'] . "','" .
            $data['to_email'] . "','" . $data['to_name'] . "','" .
            base64_encode($data['subject']) . "','" . base64_encode($data['content']) . "',now(),'". $remark . "')";
        //echo $insert_sql."\n";
        mysql_query($insert_sql);
    }
}
