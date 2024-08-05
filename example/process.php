<?php declare(strict_types=1);

include_once __DIR__ .'/../vendor/autoload.php';



$task = \P\System::Process()->task(function () {
    \P\repeat(function () {}, 1);
});

$task->run();

\P\tick();
