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
        $normalJob = $this->_addPrefix('jobs:normal');
	$selfJob = $this->_addPrefix('jobs:self');
	while($redis -> rpoplpush($selfJob, $normalJob));//将意外退出的任务重新加入到任务列表中
        $this->_log('Server', 'start push', 'info');
        while(1){
	    $token = $this->getWxToken();
	    if(!$token) {//不能得到token就继续
	    	$this->_log('Server', 'wait for access_token', 'error');
	    	sleep(60);
		continue;
	    }
            $Job = $redis->brpoplpush($normalJob, $selfJob,10);//获得一个任务，然后加入到自己的任务队列
            if( !$Job ){//没有任务等待
                continue;
            }
            $jobInfo = json_decode($Job, true);//任务数据
            $appName = $jobInfo['appName'];//添加任务的应用名称
            $jobCode = $jobInfo['jobCode'];//任务的id
            $jobData = $jobInfo['data'];//任务模板消息数据

            $redis -> hSet($jobCode, 'status', 'runing');//设置任务状态为 运行中
            $this->_log($appName, 'run '.$jobCode, 'info');//记录日志

            $result = $this->sendTemplateMessage($jobData, $token);//发送模板消息
	    if(!$result || $result['errcode'] == '40001' || $result['errcode'] == '40014' || $result['errcode'] == '41001' || $result['errcode'] == '42001') { //各种access_token 不能用的情况

	    	$jobTryCount = $redis -> hGet($jobCode, 'count');//得到任务尝试次数
		if($jobTryCount >= 5) {//超过5次放弃这个任务，并把任务记录到日志中
			$this->_log('Server', 'try it '.$jobCode.' 5 times, but still cann\'t finish it, '.$Job, 'error');
            		$redis -> hSet($jobCode, 'status', 'end');
		        $redis -> hSet($jobCode, 'result', $result['errmsg']);
			continue;
		}
            	$redis -> hSet($jobCode, 'status', 'try_again');
            	$redis -> hIncrby($jobCode, 'count', 1);
		$this->_log('Server', 'try agian for '.$jobCode, 'error');
		$redis -> rpoplpush($selfJob, $normalJob);
	    	sleep(60); //wait for access_token back
	    }
            $redis -> hSet($jobCode, 'status', 'end');//结束任务
            $redis -> hSet($jobCode, 'result', $result['errmsg']);//记录结果
	    $redis -> lpop($selfJob);//删除已经完成的任务
            $this->_log($appName, 'end '.$jobCode . ' '. $result['errcode'].' '. $result['errmsg'], 'info');//写入日志
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
