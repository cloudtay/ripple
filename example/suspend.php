<?php declare(strict_types=1);

use P\System;

include __DIR__ . '/../vendor/autoload.php';
//

\P\onSignal(\SIGTERM, function () {
    echo 'Received SIGTERM' . \PHP_EOL;
});

\P\defer(function () {
    $task = System::Process()->task(function () {
        echo 'child process:' . \posix_getpid() . \PHP_EOL;
        \sleep(1000);
    });

    $task->run();
});

echo 'parent process:' . \posix_getpid() . \PHP_EOL;
\P\run();
