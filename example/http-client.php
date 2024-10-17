<?php declare(strict_types=1);

include __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Exception\GuzzleException;
use Ripple\Utils\Output;

$client = Co\Plugin::Guzzle()->newClient();
try {
    $response = $client->get('https://www.google.com/');
    echo $response->getStatusCode(), \PHP_EOL;
    exit;
} catch (GuzzleException $e) {
    Output::exception($e);
}
