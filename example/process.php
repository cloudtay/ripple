<?php

declare(strict_types=1);

use function P\repeat;

include_once __DIR__ . '/../vendor/autoload.php';

$session                 = P\System::Proc()->open(\PHP_BINARY);
$session->onMessage      = function ($data) {
    echo $data;
};
$session->onErrorMessage = function ($data) {
    echo $data;
};
$session->onClose        = function () {
    echo 'Session closed.';
};

$session->input("<?php");
$session->inputEot();

\P\sleep(1);

echo 'start';

repeat(function () {
    echo 'repeat';
}, 1);

$task = P\System::Process()->task(function () {
    \P\sleep(3);
    echo 'end';
    exit;
});

for ($i = 0; $i < 1; $i++) {
    $task->run()->finally(function () {
        var_dump('end');
    });
}

\P\run();
