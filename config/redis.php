<?php
// +----------------------------------------------------------------------
// | redis
// +----------------------------------------------------------------------
return [
    'prefix' => Env::get('redis.prefix', 'xcrawler:'),
    'host' => Env::get('redis.host', '127.0.0.1'),
    'password' => Env::get('redis.password', null),
    'port' => Env::get('redis.port', 6379),
    'database' => Env::get('redis.database', 0),
];
