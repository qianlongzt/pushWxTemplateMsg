<?php
ini_set('display_errors', 'on');
error_reporting(E_ALL);
ini_set('date.timezone','Asia/Shanghai');
/**
 * 推送模板消息服务的sever
 */

class Server {
    private $_redis; // redis 实例
    private $_db; //redis数据库
    private $_prefix; //命名前缀
    private $_tokenName; //微信权限tokenName
    private $_logFile; //日志文件

    /**
     * server constructor.
     */
    public function __construct() {
        $config = require 'config.php';
        $redisConfig = $config['redis'];

        $redis = new Redis();
        $redis -> pconnect($redisConfig['host'], $redisConfig['port']);
        $redis -> auth($redisConfig['auth']);

        $redis -> select((int)$redisConfig['db']);
        $this -> _redis = $redis;
        $this -> _db = $redisConfig['db'];
        $this -> _tokenName = $config['tokenName'];

        $this -> _prefix = $config['prefix'];
        $this -> _logFile = $config['logFile'];
    }

    /**
     * @param int $db token 所在的数据库
     * @return bool|string token值
     */
    public function getWxToken($db = 0) {
        $redis = $this->_redis;
        $redis -> select($db);
        $token = $redis -> get($this->_tokenName);
        $redis -> select($this->_db);
        return $token;
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
     * @return array 发送模板消息回传信息
     *
     */
    public function sendTemplateMessage($data, $token) {
        $template = json_encode($data, JSON_UNESCAPED_UNICODE);

        $api_url = "https://api.weixin.qq.com/cgi-bin/message/template/send?access_token=".$token;

        $curl = curl_init($api_url);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $template);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 2);
        $html = curl_exec($curl);
        curl_close($curl);

        $html = json_decode($html, true);
	return $html;
    }

    /**
     * @param $name string 原来的名称
     * @return string 添加前缀后的名称
     */
    private function _addPrefix($name) {
        return $this->_prefix.$name;
    }

    /**
     * 记录日志
     * @param $appName string 应用名称
     * @param $msg string 信息
     * @param string $level 日志级别
     */
    private function _log($appName, $msg, $level = 'info') {
        $data = date('Y-m-d H:i:s').' ['.$level.'] ['. $appName.'] '. $msg.PHP_EOL;
        file_put_contents($this->_logFile, $data,FILE_APPEND);
    }

    /**
     *运行服务
     */
    public function start() {
        $redis = $this->_redis;
        $highJob = $this->_addPrefix('jobs:high');
        $normalJob = $this->_addPrefix('jobs:normal');
        $this->_log('Server', 'start push', 'info');
        while(1){
	    $token = $this->getWxToken();
	    if(!$token) {
	    	$this->_log('Server', 'wait for access_token', 'error');
	    	sleep(60);
		continue;
	    }
            $Job = $redis->brpop($highJob, $normalJob, 10);
            if( is_array($Job) && $Job === array() ){
                continue;
            }
            $jobLevel = $Job[1] === $highJob?'high':'normal';
            $jobInfo = json_decode($Job[1], true);
            $appName = $jobInfo['appName'];
            $jobCode = $jobInfo['jobCode'];
            $jobData = $jobInfo['data'];

            $redis -> hSet($jobCode, 'status', 'runing');
            $this->_log($appName, 'run '.$jobCode, 'info');

            $result = $this->sendTemplateMessage($jobData, $token);
	    if(!$result || $result['errcode'] == '40001' || $result['errcode'] == '40014' || $result['errcode'] == '41001' || $result['errcode'] == '42001') {
	    	sleep(60); //wait for access_token back
	    	$jobTryCount = $redis -> hGet($jobCode, 'count');
		if($jobTryCount >= 5) {
			$this->_log('Server', 'try it '.$jobCode.' 5 times, but still can finish it, '.$Job[1], 'error');
            		$redis -> hSet($jobCode, 'status', 'end');
		        $redis -> hSet($jobCode, 'result', $result['errmsg']);
			continue;
		}
            	$redis -> hSet($jobCode, 'status', 'try_again');
            	$redis -> hIncrby($jobCode, 'count', 1);
		$this->_log('Server', 'try agian for '.$jobCode, 'error');
		$redis -> lpush($highJob, json_encode($jobInfo, JSON_UNESCAPED_UNICODE));
	    }
            $redis -> hSet($jobCode, 'status', 'end');
            $redis -> hSet($jobCode, 'result', $result['errmsg']);
            $this->_log($appName, 'end '.$jobCode . ' '. $result['errcode'].' '. $result['errmsg'], 'info');
       }
    }
}
try {
    $s = new Server();
    $s -> start();
} catch (Exception $e) {
    $data = date('Y-m-d h:i:s').' [error] [server] '. $e->getMessage().PHP_EOL;
    file_put_contents('/var/log/wxTemplateMsg.log', $data,FILE_APPEND);
}
