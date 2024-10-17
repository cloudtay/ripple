<?php declare(strict_types=1);

use Co\Net;
use Ripple\App\Websocket\Server\Connection;
use Symfony\Component\HttpFoundation\Request;

use function Co\wait;

include __DIR__ . '/../vendor/autoload.php';

$server = Net::WebSocket()->server('tcp://127.0.0.1:9081', \stream_context_create([
    'socket' => [
        'so_reuseport' => true,
        'so_reuseaddr' => true,
    ]
]));

if (!$server) {
    // Problems such as port occupation may cause the creation to fail.
    exit(1);
}

$server->onRequest(static function (Request $request, Connection $connection) {
    // TODO: Occurs before onConnect, allowing information such as authentication to be done here

    # discard the request
    // $connection->close();
});

$server->onMessage(static function (string $message, Connection $connection) {
    $connection->send("Received: $message");
});

// Subscribe to the readable event of server-socket
$server->listen();

wait();
