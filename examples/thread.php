<?php declare(strict_types=1);

include __DIR__ . '/../vendor/autoload.php';

use Ripple\Kernel;
use Ripple\Utils\Output;

use function Co\thread;

if (!Kernel::getInstance()->supportParallel()) {
    Output::warning('Parallel extension is not enabled');
}

$group = [];

$group[] = thread(static function () {
    \sleep(1);
    echo 'Thread 1 ', \microtime(true), \PHP_EOL;
});

$group[] = thread(static function () {
    \sleep(1);

    echo 'Thread 2 ', \microtime(true), \PHP_EOL;
});

$group[] = thread(static function () {
    \sleep(1);

    echo 'Thread 3 ', \microtime(true), \PHP_EOL;
});

// Wait for all threads to complete execution
while ($future = \array_shift($group)) {
    $future->done();
}
