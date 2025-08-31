<?php declare(strict_types=1);

include __DIR__ . '/../vendor/autoload.php';

use Ripple\Socket;
use Ripple\Stream\Exception\ConnectionException;
use Ripple\Stream\Exception\StreamInternalException;
use Ripple\Utils\Output;

use function Co\wait;

try {
    $connection = Socket::connect('tcp://127.0.0.1:1080');

    #  Enable SSL
    // $connection->enableSSL();
    $connection->setBlocking(false);
} catch (StreamInternalException $e) {
    // 连接建立失败 - 这是框架层异常，但在连接阶段可以捕获
    Output::warning("Connection failed: " . $e->getMessage());
    exit(1);
} catch (ConnectionException $e) {
    // 其他应用层连接异常
    Output::warning("Connection error: " . $e->getMessage());
    exit(1);
}

wait();
