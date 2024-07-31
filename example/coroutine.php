<?php declare(strict_types=1);

include_once __DIR__ . '/../vendor/autoload.php';

\P\async(function () {
    echo \P\await(\P\promise(function ($r) {
        $r(8);
    }));
});

\P\tick();
