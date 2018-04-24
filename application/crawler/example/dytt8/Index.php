<?php
namespace app\crawler\example\dytt8;

use xcrawler\XCommand;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use xcrawler\XCrawler;
use Symfony\Component\DomCrawler\Crawler;

/**
 * 电影天堂首页爬虫示例
 */
class Index extends XCommand
{
    protected function configure()
    {
        $this->setName('dytt8:index')
            ->setDescription('dytt8 index crawler');
    }

    protected function execute(Input $input, Output $output)
    {
        $xcrawler = new XCrawler([
            'name' => $this->getName(),
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
        // 输出爬取结果
        $output->writeln($result);
        // 如果你想在列表爬虫命令内部直接调用详情爬虫，可以通过下面的方法:
        \xcrawler\XConsole::call('dytt8:detail', [], 'Console');
    }
}