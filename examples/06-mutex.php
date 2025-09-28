<?php declare(strict_types=1);
include __DIR__ . '/../vendor/autoload.php';

use Ripple\Sync\Mutex;
use Ripple\Time;

use function Co\go;
use function Co\wait;

$mutex = new Mutex();
$counter = 0;
$results = [];

for ($i = 0; $i < 2; $i++) {
    go(function () use ($mutex, &$counter, &$results, $i) {
        for ($j = 0; $j < 2; $j++) {
            $mutex->lock();
            Time::sleep(1);
            $counter = $counter + 1;
            $mutex->unlock();
        }
        $results[] = "Coroutine $i completed";
    });
}

wait();
\var_dump($counter);
