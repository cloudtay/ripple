<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Ripple\Sync\Channel;

use function Co\go;
use function Co\wait;

// Channel 通信演示
go(function () {
    $channel = new Channel(3); // 缓冲区大小为3

    // 生产者协程
    go(function () use ($channel) {
        for ($i = 1; $i <= 5; $i++) {
            $channel->send("Message $i");
            echo "Sent: Message $i\n";
        }
        $channel->close();
    });

    // 消费者协程
    go(function () use ($channel) {
        while ($message = $channel->receive()) {
            echo "Received: $message\n";
        }
        echo "Channel closed\n";
    });
});

wait();
