<?php declare(strict_types=1);

use Ripple\File\Exception\FileException;
use Ripple\File\File;

include __DIR__ . '/../vendor/autoload.php';

try {
    echo File::getContents(__FILE__), \PHP_EOL;
} catch (FileException $e) {
    echo $e->getMessage(), \PHP_EOL;
    exit(1);
}
