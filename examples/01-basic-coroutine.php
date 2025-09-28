<?php declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Ripple\Runtime;

use function Co\go;

/**
 * @return int
 */
function main(): int
{
    go(function () {
        echo "Hello from coroutine!\n";

        // 创建子协程
        go(function () {
            echo "Child coroutine running\n";
        });

        echo "Main coroutine finished\n";
    });

    return 0;
}

Runtime::run(static fn () => \main());
