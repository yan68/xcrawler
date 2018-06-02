<?php

/**
 * 引入composer autoloader
 */

$paths = [
    __DIR__ . '/../../vendor/autoload.php', // 当xcrawler被clone使用时
    __DIR__ . '/../../../../autoload.php', // 当xcrawler作为composer包被引入时
];

foreach ($paths as $path) {
    if (file_exists($path)) {
        require_once $path;

        return;
    }
}

throw new \Exception('Composer autoloader could not be found. Install dependencies with `composer install` and try again.');
