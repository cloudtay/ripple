<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Ripple\Sync\Channel;
use Ripple\Time;

use function Co\go;
use function Co\wait;

// 生产者-消费者模式
go(function () {
    $jobs = new Channel(10);
    $results = new Channel(10);

    // 启动多个工作者
    for ($i = 1; $i <= 3; $i++) {
        go(function () use ($jobs, $results, $i) {
            while ($job = $jobs->receive()) {
                echo "Worker $i processing job: $job\n";
                Time::sleep(0.5); // 模拟处理时间
                $results->send("Result of $job by worker $i");
            }
        });
    }

    // 生产者：发送任务
    go(function () use ($jobs) {
        for ($i = 1; $i <= 10; $i++) {
            $jobs->send("Job $i");
        }
        $jobs->close();
    });

    // 消费者：收集结果
    go(function () use ($results) {
        $count = 0;
        while ($result = $results->receive()) {
            echo "Got result: $result\n";
            $count++;
            if ($count >= 10) {
                break;
            }
        }
        echo "All jobs completed!\n";
    });
});

wait();
