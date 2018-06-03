<?php

require_once __DIR__ . '/Bootstrap.php';
use XCrawler\XCrawler;
use Symfony\Component\DomCrawler\Crawler;

// 爬取dytt8影片列表
$xcrawler = new XCrawler([
    'name' => 'dytt8:index',
    'requests' => function() {
        $url = 'http://www.dytt8.net/';
        yield $url;
    },
    'success' => function($result, $request, $xcrawler, $res_headers) {
        // 把html的编码从gbk转为utf-8
        $result = iconv('GBK', 'UTF-8', $result);
        $crawler = new Crawler();
        $crawler->addHtmlContent($result);

        $list = [];
        // 通过css选择器遍历影片列表
        $tr_selector = '#header > div > div.bd2 > div.bd3 > div:nth-child(2) > div:nth-child(1) > div > div:nth-child(2) > div.co_content8 tr';
        $crawler->filter($tr_selector)->each(function (Crawler $node, $i) use (&$list) {
            $name = dom_filter($node, 'a:nth-child(2)', 'html');
            if (empty($name)) {
                return;
            }
            $url = 'http://www.dytt8.net'.dom_filter($node, 'a:nth-child(2)', 'attr', 'href');

            $data = [
                'name' => $name,
                'url' => $url,
                'time' => dom_filter($node, '.inddline font', 'html'),
            ];
            // 把影片url、name推送到redis队列，以便进一步爬取影片下载链接
            redis()->lpush('dytt8:detail_queue', json_encode($data));
            $list[] = $data;
        });
        var_dump($list);
    }
]);
$result = $xcrawler->run();

// 爬取dytt8影片详情
$xcrawler = new XCrawler([
    'name' => 'dytt8:detail',
    'concurrency' => 3,
    'requests' => function() {
        while ($data = redis()->rpop('dytt8:detail_queue')) {
            $data = json_decode($data, true);
            $request = [
                'uri' => $data['url'],
                'callback_data' => $data,
            ];
            yield $request;
        }
    },
    'success' => function($result, $request, $xcrawler) {
        $result = iconv('GBK', 'UTF-8', $result);
        $crawler = new Crawler();
        $crawler->addHtmlContent($result);

        $data = $request['callback_data'];
        $crawler->filter('td[style="WORD-WRAP: break-word"] a')->each(function (Crawler $node, $i) use (&$data) {
            $data['download_links'][] = $node->attr('href');
        });
        var_dump($data);
    }
    ]);
$result = $xcrawler->run();

