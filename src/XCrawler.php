<?php

namespace XCrawler;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class XCrawler
{
    // 爬虫配置
    private $config;

    public function __construct(array $config = array())
    {
        $defaults = array(
            'name' => NULL, // 爬虫名称
            'concurrency' => 1, // 并发线程数
            'continue' => 0, // 是否开启续爬
            'timeout' => 10.0,    // 爬取网页超时时间
            'log_step' => 50, // 每爬取多少页面记录一次日志
            'base_uri' => '', // 爬取根域名
            'interval' => 0, // 每次爬取间隔时间
            'queue_len' => NULL, // 队列长度，用于记录队列进度日志
            'retry_count' => 2, // 失败重试次数
            'check_black' => 1, // 是否判断黑名单
            'requests' => function () { // 需要发送的请求
                // 示例代码:
                /*
                $base_url = 'http://www.example.com/p/';
                for ($i=0; $i < 100; $i++) {
                    $request = [
                        'method' => 'get',
                        'uri' => $base_url.$i,
                        'debug' => true, // 是否开启debug
                        'callback_data' => [ // 回调参数
                            'page' => $i,
                        ],
                        'headers' => [
                            'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_12_0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/61.0.3163.100 Safari/537.36',
                        ],
                    ];
                    yield $request;
                }
                */
            },
            'success' => function ($result, $request, $xcrawler, $res_headers) { // 爬取成功的回调函数
            },
            'error' => function ($request, $error_msg, $result) { // 爬取失败的回调函数
            },
        );
    
        // 判断name未赋值，则抛出异常
        if (empty($config['name'])) {
            throw new \Exception('爬虫name未定义');
        }

        // 合并数组
        $this->config = $config + $defaults;
        // 赋值爬虫redis数据前缀
        $this->redis_prefix = $this->config['name'];
        $this->redis_prefix = 'xcrawler:'.$this->redis_prefix;

        // 
        $global_config = [
            'log_path' => '',
        ];
        // 定义日志类
        $this->log = new Logger('xcrawler.'.$this->config['name']);
        $this->log->pushHandler(new StreamHandler(Utils::config('log.path'), Logger::INFO));
    }

    /**
     * 执行并发爬取
     */
    public function run()
    {
        // 线程数
        $concurrency = $this->config['concurrency'];
        // 判断如果已开启断点续爬，且还有上次未完成的数据，则续爬。否则初始化队列
        if (!$this->config['continue'] || !Utils::redis()->get($this->redis_prefix.':overplus')) {
            $this->log->info('初始化队列 start');
            // 清除旧的redis数据
            Utils::redisDel($this->redis_prefix.'*');
            $last_log_process = 0;
            foreach ($this->config['requests']() as $key => $val) {
                // 请求格式化
                $request = $this->requestFormat($val);
                // 验证url合法性
                $check_uri = (strstr($request['uri'], 'http:') || strstr($request['uri'], 'https:')) ? $request['uri'] : 'http:'.$request['uri'];
                if (!filter_var($check_uri, FILTER_VALIDATE_URL)) {
                    $this->log->info($check_uri.'不合法, 已跳过');
                    continue;
                }
                $request = json_encode($request);
                // 利用sets数据结构来避免添加重复请求到队列
                if (Utils::redis()->sadd($this->redis_prefix.':sets', $request))
                    Utils::redis()->lpush($this->redis_prefix.':queue', $request);
                // 记录队列进度日志
                if ($this->config['queue_len']) {
                    $cur_process = round(($key+1) / $this->config['queue_len'], 2)*100;
                    if ($cur_process-$last_log_process >= 5) {
                        $this->log->info('初始化队列:'.$cur_process.'%, 队列长度:'.Utils::redis()->llen($this->redis_prefix.':queue'));
                        $last_log_process = $cur_process;
                    }
                }
            }
            // 初始化剩余爬取的页面总数(以此来判断是否爬取完成)
            $overplus = Utils::redis()->llen($this->redis_prefix.':queue');
            Utils::redis()->set($this->redis_prefix.':overplus', $overplus);
            // 记录爬取页面总数(以此来判断爬取进度)
            Utils::redis()->set($this->redis_prefix.':total', $overplus);
            // 清除sets数据
            Utils::redis()->del($this->redis_prefix.':sets');
            $this->log->info('初始化队列 done');
        }
        // 断点续爬逻辑
        else {
            // 把上次请求中(requesting)的请求入队列
            $requesting = Utils::redis()->hgetall($this->redis_prefix.':requesting');
            foreach ($requesting as $key => $val) {
                Utils::redis()->rpush($this->redis_prefix.':queue', $val);
            }
            // 初始化剩余爬取的页面总数(以此来判断是否爬取完成)
            $overplus = Utils::redis()->llen($this->redis_prefix.':queue');
            Utils::redis()->set($this->redis_prefix.':overplus', $overplus);
            // 清除请求中的数据
            Utils::redis()->del($this->redis_prefix.':requesting');
        }
        $this->log->info('爬取 start');
        $begin_time = microtime(TRUE);
        // 统计数据
        $this->stat_data = [
            'success_count' => 0,
            'request_error_pages' => 0,
            'save_error_pages' => 0,
        ];
        // 实例化guzzle
        $client = new \GuzzleHttp\Client([
            'timeout' => $this->config['timeout'],
        ]);
        // 判断如果没有爬取完，则重试
        while (Utils::redis()->get($this->redis_prefix.':overplus')) {
            // 获取请求闭包函数
            $requests = function () use ($client) {
                // 记录请求下标。对应回调函数里的$index
                $i = 0;
                while (($request = Utils::redis()->rpop($this->redis_prefix.':queue:error')) || ($request = Utils::redis()->rpop($this->redis_prefix.':queue'))) {
                    // 记录正在进行的请求(用于成功回调函数内可获取该请求，和断点续爬)
                    Utils::redis()->hset($this->redis_prefix.':requesting', $i, $request);
                    // 生成真实请求
                    $request = $this->getRealRequest($request);
                    yield function () use ($client, $request) {
                        $options = $request;
                        return $client->requestAsync($request['method'], $request['uri'], $options);
                    };
                    $i++;
                }
            };
            $config = $this->config;
            // 爬取网站数据
            $pool = new Pool($client, $requests(), [
                'concurrency' => $concurrency,
                'fulfilled' => function ($response, $index) {
                    $this->stat_data['success_count']++;
                    // 获取请求数据
                    $request = Utils::redis()->hget($this->redis_prefix.':requesting', $index);
                    // 获取请求结果
                    $result = $response->getBody()->getContents();
                    // 调用爬取成功回调函数
                    $request = json_decode($request, true);
                    // 减少剩余爬取页面数
                    Utils::redis()->decr($this->redis_prefix.':overplus');
                    // 在请求中hash中删除该请求
                    Utils::redis()->hdel($this->redis_prefix.':requesting', $index);
                    // 删除该请求失败重试次数
                    Utils::redis()->hdel($this->redis_prefix.':retry_count', json_encode($request));

                    // 执行请求成功回调函数
                    try {
                        $callback_res = $this->config['success']($result, $request, $this, $response->getHeaders());
                        // 判断回调函数状态
                        if (isset($callback_res['status']) && $callback_res['status'] <= 0) {
                            /* 记录爬取错误日志 */
                            sort($callback_res['error_resaons']);
                            $error_log = [
                                'prefix' => $this->redis_prefix,
                                'request' => $request,
                                'error_type' => 'save_validate',
                                'reason' => $callback_res['error_resaons'],
                                'error_time' => time(),
                            ];
                            $this->log->error('数据解析失败', $error_log);
                            /* /记录爬取错误日志 */
                        }
                    } catch (\Exception $e) {
                        /* 记录爬取错误日志 */
                        $error_message = $e->getMessage().' in '.$e->getFile().' on '.$e->getLine();
                        $error_log = [
                            'prefix' => $this->redis_prefix,
                            'request' => $request,
                            'error_type' => 'crawler_exception',
                            'reason' => $error_message,
                            'error_time' => time(),
                        ];
                        $this->log->error('数据解析失败', $error_log);
                        /* /记录爬取错误日志 */
                        $this->stat_data['save_error_pages']++;
                    }
                    /* 获取总成功爬取页面数 */
                    $total = Utils::redis()->get($this->redis_prefix.':total');
                    $overplus = Utils::redis()->get($this->redis_prefix.':overplus');
                    $success_count = $overplus == 0 ? $total : $total - $overplus;
                    /* /获取总成功爬取页面数 */
                    if ($success_count % $this->config['log_step'] == 0) {
                        $process = round(($success_count / $total), 2)*100;
                        $this->log->info('爬取进度:'.$process.'%, 已爬取:'.$success_count.'个页面, 剩余页面:'.$overplus);
                    }
                    // 爬取时间间隔
                    sleep($this->config['interval']);
                },
                'rejected' => function ($reason, $index) {
                    // 获取请求数据
                    $request = Utils::redis()->hget($this->redis_prefix.':requesting', $index);
                    // 在请求中hash中删除该请求
                    Utils::redis()->hdel($this->redis_prefix.':requesting', $index);
                    $this->stat_data['request_error_pages']++;
                    $error_log = "失败请求:{$request}".PHP_EOL;
                    $error_log .= "失败原因:{$reason->getMessage()}".PHP_EOL;
                    $this->log->error($error_log);
                    // 获取请求重试次数
                    $retry_count = Utils::redis()->hget($this->redis_prefix.':retry_count', $request);
                    // 判断失败次数超过限制，则跳过该请求
                    if ($retry_count >= $this->config['retry_count'])
                    {
                        // 调用爬取失败回调函数
                        $result = $reason->getResponse() ? $reason->getResponse()->getBody()->getContents() : null;
                        $this->config['error'](json_decode($request, true), $reason->getMessage(), $result);
                        /* 记录请求错误日志 */
                        $error_log = [
                            'prefix' => $this->redis_prefix,
                            'request' => json_decode($request, true),
                            'error_type' => 'request_fail',
                            'reason' => $reason->getMessage(),
                            'error_time' => time(),
                        ];
                        $this->log->error('请求失败', $error_log);
                        /* /记录请求错误日志 */

                        // 减少剩余请求页面数
                        Utils::redis()->decr($this->redis_prefix.':overplus');
                        // 删除该请求失败重试次数
                        Utils::redis()->hdel($this->redis_prefix.':retry_count', $request);
                    }
                    // 失败次数没超过限制，则重试
                    else
                    {
                        // 爬取时间间隔
                        sleep($this->config['interval']);
                        // 把请求重新重新放入队列
                        Utils::redis()->lpush($this->redis_prefix.':queue:error', $request);
                        // 记录重试次数
                        Utils::redis()->hincrby($this->redis_prefix.':retry_count', $request, 1);
                    }
                },
            ]);
            // 等待爬取完成
            $promise = $pool->promise();
            $promise->wait();
        }
        $take_time = number_format((microtime(TRUE)-$begin_time), 6);
        $end_log = "爬取 done".PHP_EOL;
        $end_log .= "花费时间:".$take_time.'s'.PHP_EOL;
        $end_log .= "线程数:".$concurrency.PHP_EOL;
        $end_log .= "总页数:".Utils::redis()->get($this->redis_prefix.':total').PHP_EOL;
        $end_log .= "请求成功次数:".$this->stat_data['success_count'].PHP_EOL;
        $end_log .= "请求失败次数:".$this->stat_data['request_error_pages'].PHP_EOL;
        $end_log .= "解析失败次数:".$this->stat_data['save_error_pages'].PHP_EOL;
        $end_log = trim($end_log, PHP_EOL);
        $this->log->info($end_log);
        // 清除redis数据
        Utils::redisDel($this->redis_prefix.':*');

        return $end_log;
    }

    /**
     * 获取真实请求数据（传入guzzle的请求数据）
     * @param  array $request 请求内容
     * @return array 转化后的数据
     */
    protected function getRealRequest($request)
    {
        $request = json_decode($request, true);
        // 转化multipart中的filepath数据（上传文件数据）
        if (!empty($request['multipart'])) {
            foreach ($request['multipart'] as $key => $val) {
                if (empty($val['filepath'])) {
                    continue;
                }
                $request['multipart'][$key]['contents'] = fopen($val['filepath'], 'r');
                unset($request['multipart'][$key]['filepath']);
            }
        }
        return $request;
    }

    /**
     * 获取格式化后的请求
     * @param  string|array $request 请求内容
     * @return array 格式化后的请求
     */
    protected function requestFormat($request)
    {
        // 如果请求内容为字符串/数字，则把字符串/数字当作url转为get数组请求。
        if (is_string($request) || is_numeric($request))
        {
            return [
                'method' => 'get',
                'uri' => $this->config['base_uri'].$request,
            ];
        }
        elseif (is_array($request))
        {
            $request['uri'] = $this->config['base_uri'].$request['uri'];
            if (empty($request['method'])) {
                $request['method'] = 'get';
            }
            return $request;
        }
        return false;
    }

    /**
     * 新增请求
     * @param array|string $request 请求内容
     */
    public function addRequest($request)
    {
        $request = $this->requestFormat($request);
        // 验证url合法性
        $check_uri = (strstr($request['uri'], 'http:') || strstr($request['uri'], 'https:')) ? $request['uri'] : 'http:'.$request['uri'];
        if (!filter_var($check_uri, FILTER_VALIDATE_URL)) {
            $this->log->info($check_uri.'不合法, 已跳过');
            return;
        }
        $request = json_encode($request);
        // 添加请求到队列
        Utils::redis()->lpush($this->redis_prefix.':queue', $request);
        Utils::redis()->incr($this->redis_prefix.':overplus');
        Utils::redis()->incr($this->redis_prefix.':total');
        return true;
    }

    /**
     * 获取总爬取页数
     */
    public function getTotal()
    {
        return Utils::redis()->get($this->redis_prefix.':total');
    }

    /**
     * 获取剩余爬取页数
     */
    public function getOverplus()
    {
        return Utils::redis()->get($this->redis_prefix.':overplus');
    }

}
