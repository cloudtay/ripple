<?php

declare(strict_types=1);

use function P\run;

include_once __DIR__ . '/../vendor/autoload.php';

$session = P\System::Proc()->open(\PHP_BINARY);

$session->onMessage = function ($data) {
    echo $data;
};

$session->onErrorMessage = function ($data) {
    echo $data;
};

$session->onClose = function () {
    echo 'Session closed.';
};

$session->input("<?php echo 1;");

$session->inputEot();

run();
