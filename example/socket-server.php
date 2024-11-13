<?php declare(strict_types=1);

include __DIR__ . '/../vendor/autoload.php';

use Ripple\Socket;

use function Co\wait;

$onMessage = static function (string $data, Socket $stream) {
    $stream->write("Received: $data");
};

$listenClient = static function (Socket $stream) use ($onMessage) {
    $stream->setBlocking(false);
    $stream->onReadable(static function () use ($stream, $onMessage) {
        $data = $stream->read(1024);
        if ($data === '') {
            $stream->close();
            return;
        }
        $onMessage($data, $stream);
    });
};

$server = Socket::server('tcp://127.0.0.1:9080');
$server->setBlocking(false);
$server->setOption(\SOL_SOCKET, \SO_KEEPALIVE, 1);
$server->onReadable(fn () => $listenClient($server->accept()));

wait();
