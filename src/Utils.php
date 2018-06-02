<?php

namespace XCrawler;
use Symfony\Component\DomCrawler\Crawler;

/**
 * 工具类
 */
class Utils
{
    /**
     * 获取项目根目录
     */
    public static function rootPath()
    {
        $paths = [
            __DIR__ . '/../vendor/autoload.php', // 当xcrawler被clone使用时
            __DIR__ . '/../../../../vendor/autoload.php', // 当xcrawler作为composer包被引入时
        ];

        $root_path = null;
        foreach ($paths as $path) {
            if (file_exists($path)) {
                $root_path = $path;
                continue;
            }
        }
        $root_path = str_replace('/vendor/autoload.php', '/', $root_path);
        return $root_path;
    }

    /**
     * 获取redis实例
     */
    public static function redis() {
        static $client  = '';
        if (!$client) {
            $options = array(
                'prefix' => self::config('redis.prefix'),
            );
            $client = new \Predis\Client([
                'scheme'        => 'tcp',
                'host'          => self::config('redis.host'),
                'port'          => self::config('redis.port'),
                'read_write_timeout' => 0,
                'database' => self::config('redis.database'),
                'password' => self::config('redis.password'),
            ], $options);
        }
        return $client;
    }

    public static function config($name = null)
    {
        $default_config = require __DIR__.'/config.php';
        $config = NULL;
        if (file_exists(self::rootPath().'/xcrawler-config.php')) {
            $config = require self::rootPath().'/xcrawler-config.php';
        }
        $name    = explode('.', $name);
        
        // 从配置数组获取
        foreach ($name as $val) {
            if (isset($config[$val])) {
                $config = $config[$val];
            } else {
                unset($config);
            }
        }
        // 配置不存在，则从默认数组获取
        if (!isset($config)) {
            foreach ($name as $val) {
                if (isset($default_config[$val])) {
                    $default_config = $default_config[$val];
                } else {
                    $default_config = null;
                }
            }
            $config = $default_config;
        }
        return $config;
    }

    /**
     * 删除redis数据(支持通配符删除)
     * @param  string $key 需要删除的key
     * @return integer 删除个数
     */
    public static function redisDel($key)
    {
        $del_num = 0;
        foreach (self::redis()->keys($key) as $key => $val) {
            $val = substr_replace($val, '', 0, strlen(self::config('redis.prefix')));
            $del_num += self::redis()->del($val);
        }
        return $del_num;
    }

    /**
     * css解析dom且在节点为空时返回NULL（不抛出异常）
     * @param  Symfony\Component\DomCrawler\Crawler $crawler   symfony Crawler实例
     * @param  string $selector  选择器
     * @param  string $method    解析方法 (html/attr)
     * @param  string $arguments 参数
     * @return string
     */
    public static function domFilter(Crawler $crawler, $selector, $method, $arguments = '')
    {
        try {
            return trim($crawler->filter($selector)->$method($arguments));
        } catch (\Exception $e) {
            return NULL;
        }
    }

    /**
     * xpath解析dom且在节点为空时返回NULL（不抛出异常）
     * @param  Symfony\Component\DomCrawler\Crawler $crawler   symfony Crawler实例
     * @param  string $selector  选择器
     * @param  string $method    解析方法 (html/attr)
     * @param  string $arguments 参数
     * @return string
     */
    public static function domFilterXpath(Crawler $crawler, $selector, $method, $arguments = '')
    {
        try {
            return trim($crawler->filterXPath($selector)->$method($arguments));
        } catch (\Exception $e) {
            return NULL;
        }
    }

}
