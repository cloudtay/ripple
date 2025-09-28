<?php declare(strict_types=1);

namespace Tests\Worker;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Ripple\Worker\Manager;
use Ripple\Worker\Command;
use Ripple\Worker;
use Throwable;

class ManagerTest extends TestCase
{
    private Manager $manager;

    protected function setUp(): void
    {
        $this->manager = new Manager();
    }

    protected function tearDown(): void
    {
        if (isset($this->manager)) {
            $this->manager->terminate();

            \Co\sleep(2);
        }
    }

    #[TestDox("添加Worker")]
    public function testAddWorker(): void
    {
        $worker = new class () extends Worker {
            public string $name = 'TestWorker';

            public function register(): void
            {
            }
            public function boot(): void
            {
            }
            public function onCommand(Command $command): void
            {
            }
        };


        $this->manager->add($worker);
        $this->assertCount(1, $this->manager->workers);
        $this->assertArrayHasKey('TestWorker', $this->manager->workers);
        $this->assertEquals($worker, $this->manager->workers['TestWorker']);
    }

    #[TestDox("添加重复Worker（应该被忽略）")]
    public function testAddDuplicateWorker(): void
    {
        $worker1 = new class () extends Worker {
            public string $name = 'TestWorker';

            public function register(): void
            {
            }
            public function boot(): void
            {
            }
            public function onCommand(Command $command): void
            {
            }
        };

        $worker2 = new class () extends Worker {
            public string $name = 'TestWorker';

            public function register(): void
            {
            }
            public function boot(): void
            {
            }
            public function onCommand(Command $command): void
            {
            }
        };

        try {
            $this->manager->add($worker1);
            $this->manager->add($worker2);
        } catch (Throwable) {
        }

        $this->assertCount(1, $this->manager->workers);
    }

    #[TestDox("获取Worker")]
    public function testGetWorker(): void
    {
        $worker = new class () extends Worker {
            public string $name = 'TestWorker';

            public function register(): void
            {
            }
            public function boot(): void
            {
            }
            public function onCommand(Command $command): void
            {
            }
        };

        $this->manager->add($worker);

        $retrievedWorker = $this->manager->get('TestWorker');
        $this->assertEquals($worker, $retrievedWorker);
    }

    #[TestDox("获取不存在的Worker")]
    public function testGetNonExistentWorker(): void
    {
        $worker = $this->manager->get('NonExistentWorker');
        $this->assertNull($worker);
    }

    #[TestDox("移除Worker")]
    public function testRemoveWorker(): void
    {
        $worker = new class () extends Worker {
            public string $name = 'TestWorker';
            public function register(): void
            {
            }
            public function boot(): void
            {
            }
            public function onCommand(Command $command): void
            {
            }
        };

        $this->manager->add($worker);
        $this->assertCount(1, $this->manager->workers);

        $this->manager->remove('TestWorker');
        $this->assertCount(0, $this->manager->workers);
        $this->assertArrayNotHasKey('TestWorker', $this->manager->workers);
    }

    #[TestDox("Manager启动Worker进程")]
    public function testManagerRun(): void
    {
        $worker = new class () extends Worker {
            public string $name = 'RunTestWorker';
            public function register(): void
            {
            }
            public function boot(): void
            {
            }
            public function onCommand(Command $command): void
            {
            }
        };

        $this->manager->add($worker);
        $this->manager->run();

        \Co\sleep(1);

        $this->assertArrayHasKey('RunTestWorker', $this->manager->process);
        $this->assertCount(1, $this->manager->process['RunTestWorker']);
    }

    #[TestDox("重载Worker")]
    public function testReloadWorker(): void
    {
        $worker = new class () extends Worker {
            public string $name = 'ReloadTestWorker';
            public function register(): void
            {
            }
            public function boot(): void
            {
            }
            public function onCommand(Command $command): void
            {
            }
        };

        $this->manager->add($worker);
        $this->manager->run();

        \Co\sleep(1);

        $this->assertArrayHasKey('ReloadTestWorker', $this->manager->process);

        $originalPid = $this->manager->process['ReloadTestWorker'][0]->pid;
        $this->assertIsInt($originalPid);

        $this->manager->reload('ReloadTestWorker');

        \Co\sleep(2);

        $this->assertArrayHasKey('ReloadTestWorker', $this->manager->process);

        $newPid = $this->manager->process['ReloadTestWorker'][0]->pid;
        $this->assertIsInt($newPid);
        $this->assertNotEquals($originalPid, $newPid, 'Worker进程ID应该已改变，证明重载成功');
    }

    #[TestDox("重载所有Worker")]
    public function testReloadAllWorkers(): void
    {
        $worker1 = new class () extends Worker {
            public string $name = 'ReloadWorker1';
            public function register(): void
            {
            }
            public function boot(): void
            {
            }
            public function onCommand(Command $command): void
            {
            }
        };

        $worker2 = new class () extends Worker {
            public string $name = 'ReloadWorker2';
            public function register(): void
            {
            }
            public function boot(): void
            {
            }
            public function onCommand(Command $command): void
            {
            }
        };

        $this->manager->add($worker1);
        $this->manager->add($worker2);
        $this->manager->run();

        \Co\sleep(1);

        $this->assertArrayHasKey('ReloadWorker1', $this->manager->process);
        $this->assertArrayHasKey('ReloadWorker2', $this->manager->process);

        $originalPid1 = $this->manager->process['ReloadWorker1'][0]->pid;
        $originalPid2 = $this->manager->process['ReloadWorker2'][0]->pid;
        $this->assertIsInt($originalPid1);
        $this->assertIsInt($originalPid2);

        $this->manager->reload();

        \Co\sleep(2);

        $this->assertArrayHasKey('ReloadWorker1', $this->manager->process);
        $this->assertArrayHasKey('ReloadWorker2', $this->manager->process);

        $newPid1 = $this->manager->process['ReloadWorker1'][0]->pid;
        $newPid2 = $this->manager->process['ReloadWorker2'][0]->pid;
        $this->assertIsInt($newPid1);
        $this->assertIsInt($newPid2);
        $this->assertNotEquals($originalPid1, $newPid1, 'Worker1进程ID应该已改变，证明重载成功');
        $this->assertNotEquals($originalPid2, $newPid2, 'Worker2进程ID应该已改变，证明重载成功');
    }

    #[TestDox("终止Worker")]
    public function testTerminateWorker(): void
    {
        $worker = new class () extends Worker {
            public string $name = 'TerminateTestWorker';
            public function register(): void
            {
            }
            public function boot(): void
            {
            }
            public function onCommand(Command $command): void
            {
            }
        };

        $this->manager->add($worker);
        $this->manager->run();

        \Co\sleep(1);

        $this->assertArrayHasKey('TerminateTestWorker', $this->manager->process);

        $this->manager->terminate('TerminateTestWorker');

        \Co\sleep(2);

        $this->assertArrayNotHasKey('TerminateTestWorker', $this->manager->process);
    }

    #[TestDox("终止所有Worker")]
    public function testTerminateAllWorkers(): void
    {
        $worker1 = new class () extends Worker {
            public string $name = 'TerminateWorker1';
            public function register(): void
            {
            }
            public function boot(): void
            {
            }
            public function onCommand(Command $command): void
            {
            }
        };

        $worker2 = new class () extends Worker {
            public string $name = 'TerminateWorker2';
            public function register(): void
            {
            }
            public function boot(): void
            {
            }
            public function onCommand(Command $command): void
            {
            }
        };

        $this->manager->add($worker1);
        $this->manager->add($worker2);
        $this->manager->run();

        \Co\sleep(1);

        $this->assertArrayHasKey('TerminateWorker1', $this->manager->process);
        $this->assertArrayHasKey('TerminateWorker2', $this->manager->process);

        $this->manager->terminate();

        \Co\sleep(4);

        $this->assertArrayNotHasKey('TerminateWorker1', $this->manager->process);
        $this->assertArrayNotHasKey('TerminateWorker2', $this->manager->process);
    }

    #[TestDox("多进程Worker")]
    public function testMultiProcessWorker(): void
    {
        $worker = new class () extends Worker {
            public function register(): void
            {
                $this->name = 'MultiProcessWorker';
                $this->count = 3;
            }
            public function boot(): void
            {
            }
            public function onCommand(Command $command): void
            {
            }
        };

        $this->manager->add($worker);
        $this->manager->run();

        \Co\sleep(1);

        $this->assertArrayHasKey('MultiProcessWorker', $this->manager->process);
        $this->assertCount(3, $this->manager->process['MultiProcessWorker']);
    }
}
