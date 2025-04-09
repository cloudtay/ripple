<?php declare(strict_types=1);

include __DIR__ . '/../vendor/autoload.php';

use function Co\getContext;
use function Co\go;
use function Co\wait;

go(static function () {
    echo \microtime(true), ' > Coroutine 1', \PHP_EOL;
});

go(static function () {
    echo \microtime(true), ' > Coroutine 2', \PHP_EOL;
});

go(static function () {
    \Co\sleep(1);
    echo \microtime(true), ' > Coroutine 3', \PHP_EOL;
});

$co = go(static function () {
    while (1) {
        \Co\sleep(1);
        echo \microtime(true), ' > Coroutine 4', \PHP_EOL;
    }
});

$co2 = go(static function () {
    while (1) {
        \Co\sleep(1);
        echo \microtime(true), ' > Coroutine 5', \PHP_EOL;
    }
});

\Co\sleep(3);
$co->terminate();
echo \microtime(true), ' > Coroutine 4 terminated', \PHP_EOL;

$testCo = go(static function () {
    \var_dump(\spl_object_hash(getContext()));
});

wait();
