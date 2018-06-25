<?php

use XCrawler\Utils;
// 默认配置
return [
    // 日志配置
    'log' => [
        // 日志文件路径
        'path' => Utils::rootPath().'log/xcrawler-'.date('Y-m-d').'.log',
    ],

    // redis配置
    'redis' => [
        'prefix' => null,
        'host' => '127.0.0.1',
        'password' => null,
        'port' => 6379,
        'database' => 0,
    ],
];
