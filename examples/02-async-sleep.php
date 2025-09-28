<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Ripple\Time;

use function Co\go;
use function Co\wait;

// 异步睡眠演示
go(function () {
    echo "Start: " . \date('H:i:s') . "\n";

    // 并发执行多个任务
    go(function () {
        Time::sleep(1);
        echo "Task 1 done: " . \date('H:i:s') . "\n";
    });

    go(function () {
        Time::sleep(2);
        echo "Task 2 done: " . \date('H:i:s') . "\n";
    });

    go(function () {
        Time::sleep(0.5);
        echo "Task 3 done: " . \date('H:i:s') . "\n";
    });

    Time::sleep(3);
    echo "All tasks finished: " . \date('H:i:s') . "\n";
});

wait();
