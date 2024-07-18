<?php

use function P\async;
use function P\await;
use function P\run;

include_once __DIR__ . '/../vendor/autoload.php';

$a = async(function ($r, $d) {
    \P\sleep(3);
    $r(1);
});

async(function () use ($a) {
    $result = await($a);
    var_dump($result);
});

run();
