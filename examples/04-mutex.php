<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Ripple\Sync\Mutex;
use Ripple\Time;

use function Co\go;
use function Co\wait;

// 互斥锁演示
go(function () {
    $mutex = new Mutex();
    $counter = 0;

    // 创建多个协程同时访问共享资源
    for ($i = 1; $i <= 3; $i++) {
        go(function () use ($mutex, &$counter, $i) {
            $mutex->lock();

            echo "Worker $i: locked, counter = $counter\n";
            Time::sleep(0.1); // 模拟工作
            $counter++;
            echo "Worker $i: counter updated to $counter\n";

            $mutex->unlock();
        });
    }

    Time::sleep(1);
    echo "Final counter: $counter\n";
});

wait();
