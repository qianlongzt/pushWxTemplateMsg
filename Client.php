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
     * @param $result
     * @param $level
     */
    private function _log($msg, $level = 'info') {
        $data = date('Y-m-d h:i:s').' ['.$level.'] ['. $this->_appName.'] '. $msg .PHP_EOL;
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
     * @param string $level 消息等级
     * @return array 发送模板消息回传信息
     *
     */
    public function addJob($data, $level = 'normal') {
        if($level === 'high') {
            $jobList = $this->_addPrefix('jobs:high');
        } else {
            $jobList = $this->_addPrefix('jobs:normal');
        }
        $jobName = $this ->_addPrefix('jobIds');
        $redis = $this->_redis;
        $id = $redis->incr($jobName);
        $this ->_jobCode = $this->_addPrefix('job:'.$id);
        $redis -> hMset($this->_jobCode,
                array(
                    'createTime' => time(),
                    'status' => 'create',
                    'result' => '',
                )
            );
        $job['jobCode'] = $this->_jobCode;
        $job['appName'] = $this->_appName;
        $job['data'] = $data;
        $redis->expire($this->_jobCode, 86400);
        $this->_log('add '.$this->_jobCode, 'info');
        $redis -> lPush($jobList, json_encode($job, JSON_UNESCAPED_UNICODE));
        return $this->_jobCode;
    }

    public function getJobStatus() {
        $redis = $this->_redis;
        return $redis -> hGetAll($this->_jobCode);
    }
}
try{
    $c = new Client('test');
    $job = array();
    $job['touser'] = 'openid';
    $job['template_id'] =  'template_id';
    $job['url'] = 'http://www.baidu.com';
    $job['data'] =  array(
        'first' =>
            array(
                'value' => '亲爱的同学: '."\n",
            ),
        'keyword1'=>
            array(
                'value' => '四六级成绩推送服务',
            ),
        'keyword2' =>
            array(
                'value' => '四六级准考证或者姓名填写错误',
            ),
        'keyword3'=>
            array(
                'value' => "\n准考证号:33333333333333",
                'color' => '#01579b'
            ),
        'keyword4' =>
            array(
                'value' => date('Y-m-d H:i:s'),
            ),
        'remark'=>
            array(
                'value' => "\n\n点击修改准考证信息",
                'color' => '#01579b'
            ),
    );
    $c -> addJob($job);
    var_dump($c->getJobStatus());
} catch (Exception $e) {
    $data = date('Y-m-d h:i:s').' [error] [client] '. $e->getMessage().PHP_EOL;
    file_put_contents('/var/log/wxTemplateMsg.log', $data,FILE_APPEND);
}