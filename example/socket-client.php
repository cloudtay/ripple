<?php declare(strict_types=1);

include __DIR__ . '/../vendor/autoload.php';

use Co\IO;
use Psc\Core\Stream\Exception\ConnectionException;
use Psc\Utils\Output;

use function Co\wait;

try {
    $connection = IO::Socket()->connect('tcp://127.0.0.1:1080');

    #  Enable SSL
    // $connection->enableSSL();
    $connection->setBlocking(false);
} catch (ConnectionException $e) {
    Output::warning($e->getMessage());
    exit(1);
}

wait();
