<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Ripple\Sync\WaitGroup;
use Ripple\Time;

use function Co\go;
use function Co\wait;

// WaitGroup 演示
go(function () {
    $wg = new WaitGroup();

    // 启动3个工作协程
    for ($i = 1; $i <= 3; $i++) {
        $wg->add(1);

        go(function () use ($wg, $i) {
            echo "Worker $i started\n";
            Time::sleep(\rand(1, 3)); // 随机工作时间
            echo "Worker $i finished\n";
            $wg->done();
        });
    }

    echo "Waiting for all workers...\n";
    $wg->wait();
    echo "All workers completed!\n";
});

wait();
