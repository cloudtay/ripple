<?php declare(strict_types=1);

include __DIR__ . '/../vendor/autoload.php';

use Co\System;
use Ripple\Kernel;
use Ripple\Utils\Output;

use function Co\forked;
use function Co\wait;

// Check if process control is supported
if (!Kernel::getInstance()->supportProcessControl()) {
    Output::warning('Process control is not supported');
}

$runtimes = [];

// Register a fork handler, this will be called when a new coroutine is forked
// Registered functions will not be inherited in child processes
forked(static function () {
    echo "Forked ", \posix_getpid(), \PHP_EOL;
});

$runtimes[] = System::Process()->task(static function () {
    \sleep(1);
    exit(1);
})->run();

$runtimes[] = System::Process()->task(static function () {
    \sleep(2);
    exit(2);
})->run();

$runtimes[] = System::Process()->task(static function () {
    \sleep(3);
    exit(3);
})->run();


foreach ($runtimes as $runtime) {
    $runtime->then(static function (int $exitCode) {
        echo "Exit code: $exitCode", \PHP_EOL;
    });
}

wait();
