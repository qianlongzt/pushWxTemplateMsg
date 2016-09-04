<?php

return array (
    'redis'=>array( //redis相关配置
        'host'=>'127.0.0.1',
        'port'=> '6379',
        'auth'=>'',
        'db' => 1
    ),
    'tokenDb' => 0, //access_token 在的数据库
    'tokenName' => 'access_token', //access_token的名称
    'prefix' => 'wxTemplateMsg:', // 命名前缀
    'logFile' => '/var/log/wxTemplateMsg.log', // 日志名称
);
