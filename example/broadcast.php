<?php declare(strict_types=1);

use Co\IO;
use Co\Net;
use Psc\Core\Http\Server\Request;

include_once __DIR__ . '/../vendor/autoload.php';

$tcpClients = [];

$tcp = static function () use (&$tcpClients) {
    $server = IO::Socket()->server('tcp://127.0.0.1:8001');
    while (1) {
        $server->waitForReadable();
        \Co\async(static function () use ($server, &$tcpClients) {
            $client = $server->accept();
            $client->setBlocking(false);
            $tcpClients[$client->id] = $client;

            while (1) {
                $client->waitForReadable();
                $data = $client->read(1024);
                if ($data === '') {
                    $client->close();
                    unset($tcpClients[$client->id]);
                    break;
                }
                $client->write("received: {$data}");
            }
        });
    }
};

$http = static function () use ($tcp, &$tcpClients) {
    $server = Net::Http()->server('http://127.0.0.1:8008');
    $server->onRequest(static function (Request $request) use (&$tcpClients) {
        $request->respond('Hello World');
        if ($request->GET['message'] ?? null) {
            foreach ($tcpClients as $client) {
                $client->write("broadcast: {$request->GET['message']}\n");
            }
        }
    });
    $server->listen();
};

\Co\async($tcp);
\Co\async($http);
\Co\wait();
