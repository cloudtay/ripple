<?php declare(strict_types=1);

use Psc\Library\IO\Lock\Lock;

include_once __DIR__ . '/../vendor/autoload.php';

$lock = new Lock();
$lock->lock();

\P\async(function () use ($lock) {
    \P\sleep(3);

    $lock->unlock();
});

$task = \P\System::Process()->task(function () use ($lock) {
    $lock->lock();
    echo 'child process' . \PHP_EOL;
});
$task->run();

\P\sleep(5);
