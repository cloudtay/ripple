<?php declare(strict_types=1);

include __DIR__ . '/../vendor/autoload.php';

use Co\IO;
use Psc\Core\Socket\Tunnel\Socks5;
use Psc\Core\Stream\Exception\ConnectionException;
use Psc\Utils\Output;

use function Co\wait;

# base
try {
    $context = \stream_context_create();
    \stream_context_set_option($context, 'ssl', 'verify_peer', false);
    \stream_context_set_option($context, 'ssl', 'verify_peer_name', false);
    $proxySocket = IO::Socket()->connect('tcp://127.0.0.1:1080', 10, $context);

    $googleSocks5 = Socks5::connect($proxySocket, [
        'host' => 'www.google.com',
        'port' => 443
    ]);

    $googleStream = $googleSocks5->getSocketStream();
    $googleStream->enableSSL();
    $googleStream->write("GET / HTTP/1.1\r\nHost: www.google.com\r\nConnection: close\r\n\r\n");
    $googleStream->onReadable(function () use ($googleStream) {
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
        ],
        ssl: true,
        wait: true
    );

    $connection = $google->getSocketStream();
    $connection->write("GET / HTTP/1.1\r\nHost: www.google.com\r\nConnection: close\r\n\r\n");
    $connection->onReadable(function () use ($connection) {
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
