<?php declare(strict_types=1);

use Psc\Core\Stream\Exception\ConnectionException;

include __DIR__ . '/../vendor/autoload.php';

try {
    echo \Co\IO::File()->getContents(__FILE__), \PHP_EOL;
} catch (ConnectionException $e) {
    echo $e->getMessage(), \PHP_EOL;
    exit(1);
}
