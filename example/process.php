<?php
include_once __DIR__ . '/../vendor/autoload.php';

$session = \P\System::Proc()->open();

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

\P\delay(function () use ($session) {
    $session->close();
}, 3);


\P\run();
