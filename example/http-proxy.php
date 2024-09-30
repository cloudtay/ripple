<?php declare(strict_types=1);

include __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Exception\GuzzleException;

try {
    $response = Co\Plugin::Guzzle()->newClient()->get('https://www.youtube.com/', ['proxy' => 'http://127.0.0.1:1080']);
    echo $response->getStatusCode(), \PHP_EOL;
} catch (GuzzleException $e) {
    echo $e->getMessage();
}
