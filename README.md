#pushWxTemplateMsg

推送微信模板消息

配置config.php
服务端 启动 `Server.php` 
后台运行

客户端 引用Client.php
	```
	$c = new Client('appName');
	$job['touser'] = 'o0Myhs4E7CJdfXnv4nMx7r4hEWuE';
	$job['template_id'] =  'aoUTGmoWdXY02NdOWvdLxRadMADfuXRx5jMPxEe2ecI';
	$job['url'] = 'http://www.baidu.com';
	$job['data'] =  array(
        'first' =>
            array(
                'value' => '亲爱的 yf同学: '."\n",
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
	$c -> addJob($data);
```

