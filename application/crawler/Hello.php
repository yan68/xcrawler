<?php
namespace app\crawler;

use xcrawler\XCommand;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;

class Hello extends XCommand
{
    protected function configure()
    {
        $this->setName('hello')
            ->setDescription('hello world');
    }

    public function execute(Input $input, Output $output)
    {
        $output->writeln("hello world! - XCrawler");
    }
}