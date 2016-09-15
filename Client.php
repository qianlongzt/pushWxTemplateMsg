<?php
ini_set('display_errors', 'on');
error_reporting(E_ALL);
ini_set('date.timezone','Asia/Shanghai');
class Client {
    private $_redis;// redis 实例
    private $_db;//redis数据库
    private $_prefix; //命名前缀
    private $_jobCode; //上一个job id
    private $_logFile;//日志文件
    private $_appName; // 发送模板消息的应用

    /**
     * Client constructor.
     * @param $appName string 发送模板消息的应用
     */
    public function __construct($appName) {
        $config = require 'config.php';
        $redisConfig = $config['redis'];

        $redis = new Redis();
        $redis -> pconnect($redisConfig['host'], $redisConfig['port']);
        $redis -> auth($redisConfig['auth']);

        $redis -> select((int)$redisConfig['db']);
        $this -> _redis = $redis;
        $this -> _db = $redisConfig['db'];

        $this -> _prefix = $config['prefix'];
        $this -> _logFile = $config['logFile'];
        $this -> _appName = $appName;
    }

    /**
     * @param $name string 原来的名称
     * @return string 添加前缀后的名称
     */
    private function _addPrefix($name) {
        return $this->_prefix.$name;
    }

    /**
     * @param $msg string 日志信息
     * @param $level string 日志级别
     */
    private function _log($msg, $level = 'info') {
        $data = date('Y-m-d H:i:s').' ['.$level.'] ['. $this->_appName.'] '. $msg .PHP_EOL;
        file_put_contents($this->_logFile, $data, FILE_APPEND);
    }

    /**
     * @param $data array 模板消息数据
    {
    "touser":"OPENID",
    "template_id":"ngqIpbwh8bUfcSsECmogfXcV14J0tQlEpBO27izEYtY",
    "url":"http://weixin.qq.com/download",
    "data":{
    "first": {
    "value":"恭喜你购买成功！",
    "color":"#173177"
    },
    "remark":{
    "value":"欢迎再次购买！",
    "color":"#173177"
    }
    }
    }
     * @param string $level 消息等级 normal
     * @return string 添加的消息任务id
     *
     */
    public function addJob($data) {
        $jobList = $this->_addPrefix('jobs:normal');
        $jobName = $this ->_addPrefix('jobIds');
        $redis = $this->_redis;
        $id = $redis->incr($jobName);
        $this ->_jobCode = $this->_addPrefix('job:'.$id);
        $redis -> hMset($this->_jobCode,
                array(
                    'createTime' => time(),
                    'status' => 'create',
                    'result' => '',
		    'count'  => 0,
                )
            );
        $job['jobCode'] = $this->_jobCode;
        $job['appName'] = $this->_appName;
        $job['data'] = $data;
        $redis->expire($this->_jobCode, 8640);//放2.4h
        $this->_log('add '.$this->_jobCode, 'info');
        $redis -> lPush($jobList, json_encode($job, JSON_UNESCAPED_UNICODE));
        return $this->_jobCode;
    }

    /**
     * @param null $jobCode string jobCode 通过 addJob 返回的，默认 上一个添加的任务
     * @return array
     */
    public function getJobStatus($jobCode= null) {
        if($jobCode == null) {
            $jobCode = $this->_jobCode;
        }
        $redis = $this->_redis;
        return $redis -> hGetAll($jobCode);
    }
}
