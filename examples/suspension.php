<?php declare(strict_types=1);

include __DIR__ . '/../vendor/autoload.php';

\Co\async(static function () {
    $suspension = \Co\getSuspension();

    \Co\async(function () use ($suspension) {
        \Co\sleep(1);
        $suspension->resume();
    });

    $suspension->suspend();

    echo 'Coroutine 1', \PHP_EOL;
});

\Co\wait();
