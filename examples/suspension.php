<?php declare(strict_types=1);

use function Co\async;
use function Co\getSuspension;
use function Co\wait;

include __DIR__ . '/../vendor/autoload.php';

async(static function () {
    $suspension = getSuspension();

    async(static function () use ($suspension) {
        \Co\sleep(1);
        $suspension->resume();
    });

    $suspension->suspend();

    echo 'Coroutine 1', \PHP_EOL;
});

wait();
