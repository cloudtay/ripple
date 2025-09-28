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

use Ripple\Time;
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
    }

    public function boot(): void
    {
        \var_dump('worker a boot');
        \Co\go(function () {
            while (1) {
                \var_dump('worker a running');
                Time::sleep(1);
            }
        });
    }

    public function onCommand(Command $command): void
    {
        \var_dump($command->name);
    }
}

class WorkerB extends Worker
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        \var_dump('worker b boot');
        \Co\go(function () {
            while (1) {
                \var_dump('worker b running');
                Time::sleep(1);
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

go(function () use ($manager) {
    Time::sleep(4);
    $manager->reload(WorkerB::class);
    \var_dump(\count($manager->process));

    Time::sleep(4);
    $manager->reload(WorkerA::class);

    Time::sleep(10);
    $manager->terminate();
});

wait();
