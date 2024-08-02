<?php declare(strict_types=1);

include_once __DIR__ . '/../vendor/autoload.php';

\P\async(function () {
    $response  = \P\await(
        \P\Plugin::Guzzle()->getAsync('https://www.baidu.com')
    );

    \var_dump($response->getStatusCode());
});

\P\tick();
