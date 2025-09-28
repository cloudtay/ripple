<?php declare(strict_types=1);

namespace Tests\Worker;

use JetBrains\PhpStorm\NoReturn;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Ripple\Worker\Manager;
use Ripple\Worker\Command;
use Ripple\Worker;

use function array_filter;
use function count;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function json_decode;
use function json_encode;
use function microtime;
use function sys_get_temp_dir;
use function tempnam;
use function trim;
use function unlink;
use function is_array;
use function file_exists;

use const FILE_APPEND;
use const LOCK_EX;

class WorkerTest extends TestCase
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

            \Co\sleep(1);
        }
    }

    #[TestDox("单个Worker的基本生命周期")]
    public function testSingleWorkerLifecycle(): void
    {
        $worker = new class () extends Worker {
            public string $name = 'TestWorker';
            public bool $registered = false;
            public bool $booted = false;
            public array $commands = [];

            public function register(): void
            {
                $this->registered = true;
            }

            public function boot(): void
            {
                $this->booted = true;
            }

            public function onCommand(Command $command): void
            {
                $this->commands[] = $command;
            }
        };

        $this->manager->add($worker);


        $this->assertCount(1, $this->manager->workers);
        $this->assertArrayHasKey('TestWorker', $this->manager->workers);


        $this->manager->run();


        \Co\sleep(0.1);


        $this->assertArrayHasKey('TestWorker', $this->manager->process);
        $this->assertCount(1, $this->manager->process['TestWorker']);
    }

    #[TestDox("多个Worker的管理")]
    public function testMultipleWorkers(): void
    {
        $worker1 = new class () extends Worker {
            public string $name = 'Worker1';
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
            public string $name = 'Worker2';
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

        $this->assertCount(2, $this->manager->workers);

        $this->manager->run();


        $this->assertArrayHasKey('Worker1', $this->manager->process);
        $this->assertArrayHasKey('Worker2', $this->manager->process);
    }

    #[TestDox("Worker重载功能")]
    public function testWorkerReload(): void
    {
        $worker = new class () extends Worker {
            public string $name = 'ReloadTestWorker';
            public int $reloadCount = 0;

            public function register(): void
            {
            }

            public function boot(): void
            {
            }

            #[NoReturn]
            public function onReload(): void
            {
                $this->reloadCount++;
                exit(0);
            }

            public function onCommand(Command $command): void
            {
            }
        };

        $this->manager->add($worker);
        $this->manager->run();

        \Co\sleep(0.1);

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

    #[TestDox("Worker终止功能")]
    public function testWorkerTermination(): void
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
        $this->assertArrayHasKey('TerminateTestWorker', $this->manager->process);
        $this->manager->terminate('TerminateTestWorker');

        \Co\sleep(2);

        $this->assertArrayNotHasKey('TerminateTestWorker', $this->manager->process);
    }

    #[TestDox("Worker命令处理")]
    public function testWorkerCommandHandling(): void
    {
        $worker = new class () extends Worker {
            public string $name = 'CommandTestWorker';
            public array $receivedCommands = [];

            public function register(): void
            {
            }

            public function boot(): void
            {
            }

            public function onCommand(Command $command): void
            {
                $this->receivedCommands[] = $command;
            }
        };

        $this->manager->add($worker);
        $this->manager->run();

        $command = new Command('test_command', ['data' => 'test_value']);
        $this->manager->sendToWorker($command, 'CommandTestWorker', 0);

        \Co\sleep(0.1);

        $this->assertTrue(true);
    }

    #[TestDox("Worker指标获取")]
    public function testWorkerMetrics(): void
    {
        $worker = new class () extends Worker {
            public string $name = 'MetricsTestWorker';
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

        \Co\sleep(0.1);

        $this->assertArrayHasKey('MetricsTestWorker', $this->manager->process);

        $process = $this->manager->process['MetricsTestWorker'][0];
        $this->assertIsArray($process->metadata);
    }

    #[TestDox("Worker并发处理")]
    public function testWorkerConcurrency(): void
    {
        $worker = new class () extends Worker {
            public string $name = 'ConcurrencyTestWorker';
            public function register(): void
            {
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

        \Co\sleep(0.1);

        $this->assertArrayHasKey('ConcurrencyTestWorker', $this->manager->process);
        $this->assertCount(3, $this->manager->process['ConcurrencyTestWorker']);
    }

    #[TestDox("Manager基本功能")]
    public function testManagerBasicOperations(): void
    {
        $worker = new class () extends Worker {
            public string $name = 'ManagerTestWorker';
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

        $retrievedWorker = $this->manager->get('ManagerTestWorker');
        $this->assertNotNull($retrievedWorker);
        $this->assertEquals('ManagerTestWorker', $retrievedWorker->name);

        $nonExistentWorker = $this->manager->get('NonExistentWorker');
        $this->assertNull($nonExistentWorker);

        $this->manager->remove('ManagerTestWorker');
        $this->assertCount(0, $this->manager->workers);
    }

    #[TestDox("Worker进程重启")]
    public function testWorkerProcessRestart(): void
    {
        $worker = new class () extends Worker {
            public string $name = 'RestartTestWorker';
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

        \Co\sleep(0.1);

        $initialProcess = $this->manager->process['RestartTestWorker'][0];
        $initialPid = $initialProcess->pid;

        $this->manager->reload('RestartTestWorker');

        \Co\sleep(0.2);

        $this->assertArrayHasKey('RestartTestWorker', $this->manager->process);
        $this->assertCount(1, $this->manager->process['RestartTestWorker']);
    }

    #[TestDox("Worker间command通讯")]
    public function testWorkerCommandCommunication(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'ripple_communication_test_');

        $senderWorker = new class ($tempFile) extends Worker {
            public string $name = 'CommunicationSender';
            private string $tempFile;

            public function __construct(string $tempFile)
            {
                $this->tempFile = $tempFile;
                parent::__construct();
            }

            public function register(): void
            {
            }
            public function boot(): void
            {
            }

            public function onCommand(Command $command): void
            {
                if ($command->name === 'ping') {
                    $this->writeToTempFile([
                        'worker' => 'CommunicationSender',
                        'action' => 'received_ping',
                        'timestamp' => microtime(true),
                        'data' => $command->arguments
                    ]);

                    $this->sendToWorker(Command::make('pong', [
                        'from' => 'CommunicationSender',
                        'message' => 'Hello from sender!',
                        'timestamp' => microtime(true)
                    ]), 'CommunicationReceiver');
                }
            }

            private function writeToTempFile(array $data): void
            {
                $line = json_encode($data) . "\n";
                file_put_contents($this->tempFile, $line, FILE_APPEND | LOCK_EX);
            }
        };

        $receiverWorker = new class ($tempFile) extends Worker {
            public string $name = 'CommunicationReceiver';
            private string $tempFile;

            public function __construct(string $tempFile)
            {
                $this->tempFile = $tempFile;
                parent::__construct();
            }

            public function register(): void
            {
            }
            public function boot(): void
            {
            }

            public function onCommand(Command $command): void
            {
                if ($command->name === 'pong') {
                    $this->writeToTempFile([
                        'worker' => 'CommunicationReceiver',
                        'action' => 'received_pong',
                        'from' => $command->arguments['from'] ?? 'unknown',
                        'message' => $command->arguments['message'] ?? '',
                        'timestamp' => microtime(true)
                    ]);
                }
            }

            private function writeToTempFile(array $data): void
            {
                $line = json_encode($data) . "\n";
                file_put_contents($this->tempFile, $line, FILE_APPEND | LOCK_EX);
            }
        };

        $this->manager->add($senderWorker);
        $this->manager->add($receiverWorker);
        $this->manager->run();

        \Co\sleep(0.1);

        $this->manager->sendToWorker(Command::make('ping', [
            'test_data' => 'communication_test',
            'initiator' => 'test_suite'
        ]), 'CommunicationSender');

        \Co\sleep(0.5);

        $this->assertFileExists($tempFile);
        $content = file_get_contents($tempFile);
        $lines = array_filter(explode("\n", trim($content)));

        $this->assertCount(2, $lines, '应该有2条通讯记录');

        $senderRecord = json_decode($lines[0], true);
        $this->assertNotNull($senderRecord, 'Sender record should not be null');
        $this->assertEquals('CommunicationSender', $senderRecord['worker']);
        $this->assertEquals('received_ping', $senderRecord['action']);
        $this->assertEquals('communication_test', $senderRecord['data']['test_data']);

        $receiverRecord = json_decode($lines[1], true);
        $this->assertNotNull($receiverRecord, 'Receiver record should not be null');
        $this->assertEquals('CommunicationReceiver', $receiverRecord['worker']);
        $this->assertEquals('received_pong', $receiverRecord['action']);
        $this->assertEquals('CommunicationSender', $receiverRecord['from']);
        $this->assertEquals('Hello from sender!', $receiverRecord['message']);

        if (file_exists($tempFile)) {
            unlink($tempFile);
        }
    }

    #[TestDox("子进程通过supervisorMetadata获取全局信息")]
    public function testWorkerSupervisorMetadata(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'ripple_metadata_test_');

        $metadataWorker = new class ($tempFile) extends Worker {
            public string $name = 'MetadataTestWorker';
            private string $tempFile;

            public function __construct(string $tempFile)
            {
                $this->tempFile = $tempFile;
                parent::__construct();
            }

            public function register(): void
            {
            }
            public function boot(): void
            {
            }

            public function onCommand(Command $command): void
            {
                if ($command->name === 'request_metadata') {
                    $metadata = $this->supervisorMetadata();

                    if ($metadata !== false) {
                        $this->writeToTempFile([
                            'worker' => 'MetadataTestWorker',
                            'action' => 'received_metadata',
                            'metadata' => $metadata,
                            'timestamp' => microtime(true)
                        ]);
                    } else {
                        $this->writeToTempFile([
                            'worker' => 'MetadataTestWorker',
                            'action' => 'metadata_failed',
                            'timestamp' => microtime(true)
                        ]);
                    }
                }
            }

            private function writeToTempFile(array $data): void
            {
                $line = json_encode($data) . "\n";
                file_put_contents($this->tempFile, $line, FILE_APPEND | LOCK_EX);
            }
        };

        $this->manager->add($metadataWorker);
        $this->manager->run();

        \Co\sleep(0.1);

        $this->manager->sendToWorker(Command::make('request_metadata'), 'MetadataTestWorker');

        \Co\sleep(0.5);

        $this->assertFileExists($tempFile);
        $content = file_get_contents($tempFile);
        $lines = array_filter(explode("\n", trim($content)));

        $this->assertCount(1, $lines, '应该有1条metadata记录');

        $record = json_decode($lines[0], true);
        $this->assertEquals('MetadataTestWorker', $record['worker']);
        $this->assertEquals('received_metadata', $record['action']);

        $metadata = $record['metadata'];
        $this->assertIsArray($metadata);

        if (empty($metadata)) {
            $this->fail('Metadata is empty');
        }

        $this->assertNotEmpty($metadata, 'Metadata should not be empty');

        $this->assertIsArray($metadata);

        $hasWorkerMetadata = false;
        foreach ($metadata as $workerName => $workerData) {
            if (is_array($workerData) && !empty($workerData)) {
                $hasWorkerMetadata = true;
                break;
            }
        }

        $this->assertTrue($hasWorkerMetadata, 'Should have at least one worker with metadata');

        if (file_exists($tempFile)) {
            unlink($tempFile);
        }
    }

    #[TestDox("多个Worker的复杂通讯场景")]
    public function testComplexWorkerCommunication(): void
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'ripple_complex_test_');

        $coordinatorWorker = new class ($tempFile) extends Worker {
            public string $name = 'Coordinator';
            private string $tempFile;
            private int $taskCount = 0;

            public function __construct(string $tempFile)
            {
                $this->tempFile = $tempFile;
                parent::__construct();
            }

            public function register(): void
            {
            }
            public function boot(): void
            {
            }

            public function onCommand(Command $command): void
            {
                if ($command->name === 'start_workflow') {
                    $this->taskCount = $command->arguments['task_count'] ?? 3;
                    $this->writeToTempFile([
                        'worker' => 'Coordinator',
                        'action' => 'workflow_started',
                        'task_count' => $this->taskCount,
                        'timestamp' => microtime(true)
                    ]);

                    $this->sendToWorker(Command::make('process_task', [
                        'task_id' => 1,
                        'data' => 'task_data_1',
                        'coordinator' => 'Coordinator'
                    ]), 'TaskWorker1');
                } elseif ($command->name === 'task_completed') {
                    $taskId = $command->arguments['task_id'];
                    $this->writeToTempFile([
                        'worker' => 'Coordinator',
                        'action' => 'task_received',
                        'task_id' => $taskId,
                        'from' => $command->arguments['from'],
                        'timestamp' => microtime(true)
                    ]);

                    if ($taskId < $this->taskCount) {
                        $nextTaskId = $taskId + 1;
                        $this->sendToWorker(Command::make('process_task', [
                            'task_id' => $nextTaskId,
                            'data' => "task_data_{$nextTaskId}",
                            'coordinator' => 'Coordinator'
                        ]), 'TaskWorker1');
                    } else {
                        $this->sendToWorker(Command::make('all_tasks_completed', [
                            'total_tasks' => $this->taskCount,
                            'coordinator' => 'Coordinator'
                        ]), 'TaskWorker2');
                    }
                }
            }

            private function writeToTempFile(array $data): void
            {
                $line = json_encode($data) . "\n";
                file_put_contents($this->tempFile, $line, FILE_APPEND | LOCK_EX);
            }
        };

        $taskWorker1 = new class ($tempFile) extends Worker {
            public string $name = 'TaskWorker1';
            private string $tempFile;

            public function __construct(string $tempFile)
            {
                $this->tempFile = $tempFile;
                parent::__construct();
            }

            public function register(): void
            {
            }
            public function boot(): void
            {
            }

            public function onCommand(Command $command): void
            {
                if ($command->name === 'process_task') {
                    $taskId = $command->arguments['task_id'];
                    $data = $command->arguments['data'];

                    $this->writeToTempFile([
                        'worker' => 'TaskWorker1',
                        'action' => 'task_processed',
                        'task_id' => $taskId,
                        'data' => $data,
                        'timestamp' => microtime(true)
                    ]);

                    \Co\sleep(0.01);

                    $this->sendToWorker(Command::make('task_completed', [
                        'task_id' => $taskId,
                        'from' => 'TaskWorker1',
                        'result' => "processed_{$data}"
                    ]), 'Coordinator');
                }
            }

            private function writeToTempFile(array $data): void
            {
                $line = json_encode($data) . "\n";
                file_put_contents($this->tempFile, $line, FILE_APPEND | LOCK_EX);
            }
        };

        $taskWorker2 = new class ($tempFile) extends Worker {
            public string $name = 'TaskWorker2';
            private string $tempFile;

            public function __construct(string $tempFile)
            {
                $this->tempFile = $tempFile;
                parent::__construct();
            }

            public function register(): void
            {
            }
            public function boot(): void
            {
            }

            public function onCommand(Command $command): void
            {
                if ($command->name === 'all_tasks_completed') {
                    $totalTasks = $command->arguments['total_tasks'];

                    $this->writeToTempFile([
                        'worker' => 'TaskWorker2',
                        'action' => 'workflow_completed',
                        'total_tasks' => $totalTasks,
                        'timestamp' => microtime(true)
                    ]);
                }
            }

            private function writeToTempFile(array $data): void
            {
                $line = json_encode($data) . "\n";
                file_put_contents($this->tempFile, $line, FILE_APPEND | LOCK_EX);
            }
        };

        $this->manager->add($coordinatorWorker);
        $this->manager->add($taskWorker1);
        $this->manager->add($taskWorker2);
        $this->manager->run();

        \Co\sleep(0.1);

        $this->manager->sendToWorker(Command::make('start_workflow', [
            'task_count' => 3
        ]), 'Coordinator');

        \Co\sleep(2);


        $this->assertFileExists($tempFile);
        $content = file_get_contents($tempFile);
        $lines = array_filter(explode("\n", trim($content)));

        $this->assertGreaterThanOrEqual(7, count($lines), '应该有足够的工作流记录');

        $workflowStarted = false;
        $workflowCompleted = false;
        $taskProcessedCount = 0;

        foreach ($lines as $line) {
            $record = json_decode($line, true);

            if ($record['action'] === 'workflow_started') {
                $workflowStarted = true;
                $this->assertEquals(3, $record['task_count']);
            } elseif ($record['action'] === 'task_processed') {
                $taskProcessedCount++;
            } elseif ($record['action'] === 'workflow_completed') {
                $workflowCompleted = true;
                $this->assertEquals(3, $record['total_tasks']);
            }
        }

        $this->assertTrue($workflowStarted, '工作流应该已启动');
        $this->assertTrue($workflowCompleted, '工作流应该已完成');
        $this->assertEquals(3, $taskProcessedCount, '应该处理了3个任务');

        if (file_exists($tempFile)) {
            unlink($tempFile);
        }
    }
}
