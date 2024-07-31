<?php declare(strict_types=1);

include_once __DIR__ . '/../vendor/autoload.php';

\P\defer(function () {
    $response  = \P\await(
        \P\Net::Http()->Guzzle()->getAsync('https://www.baidu.com')
    );

    \var_dump($response->getStatusCode());
});

\P\tick();
