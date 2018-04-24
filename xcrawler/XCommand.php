<?php

namespace xcrawler;

use think\console\Command;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class XCommand extends Command
{
    public function __construct($name = null)
    {
        parent::__construct($name);

        // 定义日志类
        $this->log = new Logger('xcommand.'.$this->getName());
        $this->log->pushHandler(new StreamHandler(config('xcrawler.log_path').'/'.date('Y-m-d').'.log', Logger::INFO));
    }
}
