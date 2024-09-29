<?php declare(strict_types=1);

include __DIR__ . '/../vendor/autoload.php';

use Psc\Core\Http\Server\Chunk;
use Psc\Core\Http\Server\Request;

$server = Co\Net::Http()->server('http://127.0.0.1:8008', \stream_context_create([
    'socket' => [
        'so_reuseport' => true,
        'so_reuseaddr' => true,
    ]
]));

if (!$server) {
    // Problems such as port occupation may cause the creation to fail.
    exit(1);
}

$server->onRequest(static function (Request $request) {
    switch (\trim($request->SERVER['REQUEST_URI'], '/')) {
        case 'sse':
            $generator = static function () {
                foreach (\range(1, 10) as $i) {
                    Co\sleep(0.1);
                    yield Chunk::event('message', \json_encode(['id' => $i, 'content' => 'content']));
                }
                return false;
            };
            $request->respond($generator());
            break;
        default:
            $request->respond('Hello, World!');
            break;
    }
});

// Subscribe to the readable event of server-socket
$server->listen();

Co\wait();
