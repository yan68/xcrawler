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
class Detail extends XCommand
{
    protected function configure()
    {
        $this->setName('dytt8:detail')
            ->addOption('continue', 'c', Option::VALUE_NONE, 'is continue')
            ->setDescription('dytt8 detail crawler');
    }

    protected function execute(Input $input, Output $output)
    {
        $xcrawler = new XCrawler([
            'name' => $this->getName(),
            'concurrency' => 3,
            'continue' => $input->getOption('continue'),
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
        // 输出爬取结果
        $output->writeln($result);
    }
}