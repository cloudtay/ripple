<?php declare(strict_types=1);

include __DIR__ . '/../vendor/autoload.php';

use Co\System;

use function Co\wait;

$session = System::Proc()->open(\PHP_BINARY);

$session->onMessage = static function (string $message) {
    echo $message, \PHP_EOL;
};

$session->onErrorMessage = static function (string $message) {
    echo $message, \PHP_EOL;
};

$session->input('<?php echo "Hello, World!";');
$session->inputEot();

wait();
