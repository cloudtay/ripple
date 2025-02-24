<?php declare(strict_types=1);

use function Co\defer;
use function Co\go;
use function Co\wait;

include __DIR__ . '/../vendor/autoload.php';


$a = go(static function () {
    defer(static function () {
        \var_dump('defer1');
    });

    \Co\sleep(1);
    echo 'coroutine1', \PHP_EOL;
});

go(static function () use ($a) {
    $a->terminate();
    echo 'coroutine2', \PHP_EOL;
});

$time = \microtime(true);
wait();

echo \microtime(true) - $time, 's', \PHP_EOL;
