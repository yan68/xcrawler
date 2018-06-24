<?php

use XCrawler\Utils;
use Symfony\Component\DomCrawler\Crawler;

if (!function_exists('redis')) {
    /**
     * 获取redis实例
     */
    function redis()
    {
        return Utils::redis();
    }
}

if (!function_exists('redis_del')) {
    /**
     * 删除redis数据(支持通配符删除)
     * @param  string $key 需要删除的key
     * @return integer 删除个数
     */
    function redis_del($key)
    {
        return Utils::redisDel($key);
    }
}

if (!function_exists('dom_filter')) {
    /**
     * css解析dom且在节点为空时返回NULL（不抛出异常）
     * @param  Symfony\Component\DomCrawler\Crawler $crawler   symfony Crawler实例
     * @param  string $selector  选择器
     * @param  string $method    解析方法 (html/attr)
     * @param  string $arguments 参数
     * @return string
     */
    function dom_filter(Crawler $crawler, $selector, $method, $arguments = '')
    {
        return Utils::domFilter($crawler, $selector, $method, $arguments);
    }
}

if (!function_exists('dom_filter_xpath')) {
    /**
     * xpath解析dom且在节点为空时返回NULL（不抛出异常）
     * @param  Symfony\Component\DomCrawler\Crawler $crawler   symfony Crawler实例
     * @param  string $selector  选择器
     * @param  string $method    解析方法 (html/attr)
     * @param  string $arguments 参数
     * @return string
     */
    function dom_filter_xpath(Crawler $crawler, $selector, $method, $arguments = '')
    {
        return Utils::domFilterXpath($crawler, $selector, $method, $arguments);
    }
}
