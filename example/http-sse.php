<?php declare(strict_types=1);

include __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Exception\GuzzleException;
use Psc\Core\Http\Client\Capture\ServerSentEvents;

if (!$key = $argv[1] ?? null) {
    echo 'Please enter the key' . \PHP_EOL;
    exit(1);
}

// Create interceptor
$sse = new ServerSentEvents();
$sse->onEvent(function (array $event) {
    \var_dump($event);
});

// Refer to the documentation and ask questions
$client                  = Co\Plugin::Guzzle()->newClient();
$header                  = [];
$header['Content-Type']  = 'application/json';
$header['Accept']        = 'text/event-stream';
$header['Authorization'] = 'Bearer ' . $key;
$body                    = [
    'model' => 'qwen-max',
    'input' => [
        'messages' => [
            ['role' => 'system', 'content' => 'Your name is ripple knowledge base'],
            ['role' => 'user', 'content' => 'Who are you?'],
        ],
    ],
];

try {
    $response = $client->post('https://dashscope.aliyuncs.com/api/v1/services/aigc/text-generation/generation', [
        'headers' => $header,
        'body'    => \json_encode($body, \JSON_UNESCAPED_UNICODE),

        'timeout'       => 10,

        // Injection interceptor
        'capture_write' => $sse->getWriteCapture(),
        'capture_read'  => $sse->getReadCapture(),
    ]);
} catch (GuzzleException $e) {
    echo $e->getMessage();
}
