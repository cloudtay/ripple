<?php declare(strict_types=1);

use P\Net;
use Psc\Core\Output;
use Psc\Library\Net\Http\Server\Request;
use Psc\Library\Net\Http\Server\Response;

use function P\repeat;
use function P\run;

include __DIR__ . '/../vendor/autoload.php';

$context = \stream_context_create([
    'socket' => [
        'so_reuseport' => true,
        'so_reuseaddr' => true,
    ],
]);

repeat(function () {
    Output::info('memory usage: ' . \memory_get_usage());
    \gc_collect_cycles();
}, 1);

$server            = Net::Http()->server('http://127.0.0.1:8008', $context);
$server->onRequest = function (Request $request, Response $response) {
    if ($request->isMethod('get')) {
        $hash = $request->query->get('hash');
        $response->setContent($hash)->respond();
        return;
    }

    if ($request->isMethod('post')) {
        $response->setContent(
            $request->getContent()
        )->respond();
        return;
    }

    $response->setStatusCode(404)->respond();
};
$server->listen();
run();
