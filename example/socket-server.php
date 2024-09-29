<?php declare(strict_types=1);

include __DIR__ . '/../vendor/autoload.php';

use Co\IO;
use Psc\Core\Socket\SocketStream;

use function Co\wait;

$onMessage = static function (string $data, SocketStream $stream) {
    $stream->write("Received: $data");
};

$listenClient = static function (SocketStream $stream) use ($onMessage) {
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

$server = IO::Socket()->server('tcp://127.0.0.1:9080');
$server->setBlocking(false);
$server->setOption(\SOL_SOCKET, \SO_KEEPALIVE, 1);
$server->onReadable(fn () => $listenClient($server->accept()));

wait();
