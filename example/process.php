<?php

use P\System;
use function P\delay;
use function P\run;

include_once __DIR__ . '/../vendor/autoload.php';

$session = System::Proc()->open();

$session->input('ls');

$session->onClose = function () {
    echo 'done.';
};

$session->onErrorMessage = function ($output) {
    echo $output;
};

$session->onMessage = function ($output) {
    echo $output;
};

delay(function () use ($session) {
    $session->close();
}, 3);


run();
