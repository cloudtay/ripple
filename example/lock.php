<?php declare(strict_types=1);

use P\System;
use Psc\Library\IO\Lock\Lock;

use function P\async;

include_once __DIR__ . '/../vendor/autoload.php';

$lock = new Lock();
$lock->lock();

async(function () use ($lock) {
    \P\sleep(1);
    $lock->unlock();
});

$task = System::Process()->task(function () use ($lock) {
    $lock->lock();
    echo 'child process' . \PHP_EOL;
});

$runtime = $task->run();

\P\run();
