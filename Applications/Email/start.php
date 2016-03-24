<?php
use \Workerman\Worker;
use \Workerman\Autoloader;

// 自动加载类
require_once __DIR__ . '/../../Workerman/Autoloader.php';
Autoloader::setRootPath(__DIR__);

$config = require __DIR__ . '/../config.php';
EmailWorker::$config = $config;

$worker = new Worker();
// worker名称
$worker->name = 'EmailWorker';
// Worker进程数量
$worker->count = 10;

$worker->onWorkerStart = function($worker) {
    //echo 'process '.getmypid(). ' start'."\n";
    $Email = new EmailWorker();
    $Email->start();
};

// 如果不是在根目录启动，则运行runAll方法
if(!defined('GLOBAL_START'))
{
    Worker::runAll();
}
