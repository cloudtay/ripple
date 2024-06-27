<?php declare(strict_types=1);

use Cclilshy\PRippleEvent\Facades\Guzzle;
use GuzzleHttp\Psr7\Response;

include_once __DIR__ . '/vendor/autoload.php';

A\async(function () {
    $fileContent = A\await(A\fileGetContents(__FILE__));
    $hash        = hash('sha256', $fileContent);
    echo "[await] File content hash: {$hash}" . PHP_EOL;
});

A\async(function () {
    try {
        $response = A\await(Guzzle::requestAsync('get', 'https://www.baidu.com/404'));
        echo "[await] Response status code: {$response->getStatusCode()}" . PHP_EOL;
    } catch (Throwable $exception) {
        echo "[await] Exception: {$exception->getMessage()}" . PHP_EOL;
    }
});

A\async(function () {
    Guzzle::getAsync('https://www.baidu.com/404')->then(function (Response $response) {
        echo "[async] Response status code: {$response->getStatusCode()}" . PHP_EOL;
    })->except(function (Exception $e) {
        echo "[async] Exception: {$e->getMessage()}" . PHP_EOL;
    });
});

A\loop(1000000);
