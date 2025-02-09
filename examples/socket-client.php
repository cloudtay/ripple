<?php declare(strict_types=1);

include __DIR__ . '/../vendor/autoload.php';

use Ripple\Socket;
use Ripple\Stream\Exception\ConnectionException;
use Ripple\Utils\Output;

use function Co\wait;

try {
    $connection = Socket::connect('tcp://127.0.0.1:1080');

    #  Enable SSL
    // $connection->enableSSL();
    $connection->setBlocking(false);
} catch (ConnectionException $e) {
    Output::warning($e->getMessage());
    exit(1);
}

wait();
