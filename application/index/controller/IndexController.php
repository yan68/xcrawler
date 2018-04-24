<?php
namespace app\index\controller;

class IndexController
{
    public function index()
    {
        $res = \xcrawler\XConsole::call('hello', [], 'Console');
        return 'hello, XCrawler';
    }
}
