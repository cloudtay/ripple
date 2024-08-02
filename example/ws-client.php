<?php declare(strict_types=1);

use P\Net;

use Psc\Library\Net\WebSocket\Client\Connection;

use function P\run;

include __DIR__ . '/../vendor/autoload.php';

$connection            = Net::WebSocket()->connect('wss://echo.websocket.org');
$connection->onOpen(function (Connection $connection) {
    $connection->send('{"action":"ping","data":[]}');

});

$connection->onMessage(function (string $data, Connection $connection) {
    echo 'Received: ' . $data . \PHP_EOL;
});

$connection->onClose(function (Connection $connection) {
    echo 'Connection closed' . \PHP_EOL;
});

run();
