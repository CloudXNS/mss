<?php

return array(
    //存储消息redis
    'Email_redis_key' => 'email',	//redis的key
    'Sms_redis_key' => 'sms',		//redis的key
    'redis_ip' => '127.0.0.1',		//redis的ip
    'redis_port' => 6379,			//redis的port
    'redis_db' => 0,				//redis的db
    'requirepass' => 'password',	//redis的密码

    //sms短信
    'sms_apikey' => 'apikey',		//云片网的apikey
    'sms_send_url' => 'http://yunpian.com/v1/sms/send.json',	//云片网api地址

    'mail_host' => 'mail.com',	//邮件服务器
    'mail_port' => 25,			//邮件服务器端口
    'mail_password' => 'password',//发件人邮箱密码
    'mail_charset' => 'UTF-8',
    'mail_encoding' => 'base64',
    'mail_sender' => 'send@mail.com',//发件人邮箱用户名
    'mail_smtpdebug' => 0,
    'mail_smtpauth' => true,

    'db_ip' => '127.0.0.1',	//数据库ip
    'db_username' => 'user',//数据库连接用户名
    'db_password' => 'password',//数据库连接密码
    'db' => 'db',	//数据库名称
);