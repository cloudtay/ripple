<?php declare(strict_types=1);

include __DIR__ . '/../vendor/autoload.php';

use function Co\async;

async(static function () {
    \Co\sleep(1);

    echo 'Coroutine 1', \PHP_EOL;
});

async(static function () {
    \Co\sleep(1);

    echo 'Coroutine 2', \PHP_EOL;
});

async(static function () {
    \Co\sleep(1);

    echo 'Coroutine 3', \PHP_EOL;
});

// Wait for all coroutines to complete execution
\Co\sleep(2);
