<?php declare(strict_types=1);

include __DIR__ . '/../vendor/autoload.php';

use Ripple\Stream\Exception\ConnectionException;
use Ripple\Tunnel\Socks5;
use Ripple\Utils\Output;

use function Co\wait;

# base
try {
    $googleSocks5 = Socks5::connect('tcp://127.0.0.1:1080', [
        'host' => 'www.google.com',
        'port' => 443
    ]);

    $googleStream = $googleSocks5->getSocket();
    $googleStream->enableSSL();
    $googleStream->write("GET / HTTP/1.1\r\nHost: www.google.com\r\nConnection: close\r\n\r\n");
    $googleStream->onReadable(static function () use ($googleStream) {
        $data = $googleStream->read(1024);
        if ($data === '') {
            $googleStream->close();
            return;
        }
        echo $data;
    });
} catch (ConnectionException $e) {
    Output::warning($e->getMessage());
    exit(1);
}

# sugar
try {
    // Connect to the SOCKS5 proxy server
    $google = Socks5::connect(
        target: 'tcp://127.0.0.1:1080',
        payload: [
            'host' => 'www.google.com',
            'port' => 443
        ]
    );

    $connection = $google->getSocket();
    $connection->enableSSL();
    $connection->write("GET / HTTP/1.1\r\nHost: www.google.com\r\nConnection: close\r\n\r\n");
    $connection->onReadable(static function () use ($connection) {
        $data = $connection->read(1024);
        if ($data === '') {
            $connection->close();
            return;
        }
        echo $data;
    });
} catch (ConnectionException $e) {
    Output::warning($e->getMessage());
    exit(1);
}

wait();
