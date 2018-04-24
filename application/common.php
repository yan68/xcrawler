<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: 流年 <liu21st@gmail.com>
// +----------------------------------------------------------------------

// 应用公共文件

/**
 * 获取redis实例
 */
function redis() {
    static $client  = '';
    if (!$client) {
        $options = array(
            'prefix' => config('redis.prefix'),
        );
        $client = new Predis\Client([
            'scheme'        => 'tcp',
            'host'          => config('redis.host'),
            'port'          => config('redis.port'),
            'read_write_timeout' => 0,
            'database' => config('redis.database'),
            'password' => config('redis.password'),
        ], $options);
    }
    return $client;
}

/**
 * 删除redis数据(支持通配符删除)
 */
function redis_del($keys)
{
    $del_num = 0;
    foreach (redis()->keys($keys) as $key => $val) {
        $val = substr_replace($val, '', 0, strlen(config('redis.prefix')));
        $del_num += redis()->del($val);
    }
    return $del_num;
}

/**
 * css解析dom且在节点为空时返回NULL（不抛出异常）
 */
function dom_filter($crawler, $selector, $method, $arguments = '')
{
    try {
        return trim($crawler->filter($selector)->$method($arguments));
    } catch (\Exception $e) {
        return NULL;
    }
}

/**
 * xpath解析dom且在节点为空时返回NULL（不抛出异常）
 */
function dom_filter_xpath($crawler, $selector, $method, $arguments = '')
{
    try {
        return trim($crawler->filterXPath($selector)->$method($arguments));
    } catch (\Exception $e) {
        return NULL;
    }
}

