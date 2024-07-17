<?php

use function P\run;

include_once __DIR__ . '/../vendor/autoload.php';

$o = P\IO::File()->watch(__DIR__);
$o->onNewFile = function (string $path) {
    echo "New file: $path\n";
};

$o->onChangeFile = function (string $path) {
    echo "File changed: $path\n";
};

$o->onRemoveFile = function (string $path) {
    echo "File removed: $path\n";
};

$o->listen();

run();
