<?php declare(strict_types=1);

/**
 * Copyright © 2024 cclilshy
 * Email: jingnigg@gmail.com
 *
 * This software is licensed under the MIT License.
 * For full license details, please visit: https://opensource.org/licenses/MIT
 *
 * By using this software, you agree to the terms of the license.
 * Contributions, suggestions, and feedback are always welcome!
 */

use Ripple\Runtime\Support\Stdin;
use Ripple\Worker;
use Ripple\Worker\Command;
use Ripple\Worker\Manager;

use function Co\go;
use function Co\wait;

include __DIR__ . '/../vendor/autoload.php';

class WorkerA extends Worker
{
    public function register(): void
    {
        $this->name = __CLASS__;
    }

    public function boot(): void
    {
        Stdin::println('a is booting');
        go(function () {
            while (1) {
                Stdin::println('a is running');
                \Co\sleep(1);
            }
        });

        \Co\go(function () {
            \Co\sleep(6);
            exit(2);
        });
    }

    public function onCommand(Command $command): void
    {
    }
}

class WorkerB extends Worker
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        Stdin::println('b is booting');
        go(function () {
            while (1) {
                Stdin::println(\json_encode($this->supervisorMetadata()));
                \Co\sleep(1);
            }
        });
    }

    public function onCommand(Command $command): void
    {
        \var_dump($command->name);
    }
}


$manager = new Manager();
$manager->add(new WorkerA());
$manager->add(new WorkerB());

$manager->run();
wait();
