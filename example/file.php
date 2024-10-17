<?php declare(strict_types=1);

use Co\IO;
use Ripple\File\Exception\FileException;

include __DIR__ . '/../vendor/autoload.php';

try {
    echo IO::File()->getContents(__FILE__), \PHP_EOL;
} catch (FileException $e) {
    echo $e->getMessage(), \PHP_EOL;
    exit(1);
}
