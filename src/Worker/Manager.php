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

namespace Ripple\Worker;

use Ripple\Kernel;
use Ripple\Utils\Output;
use Ripple\Utils\Serialization\Zx7e;
use Throwable;

use function getmypid;

/**
 * @Author cclilshy
 * @Date   2024/8/16 11:51
 */
class Manager
{
    public const COMMAND_COMMAND_TO_WORKER = '__manager__.commandToWorker';
    public const COMMAND_COMMAND_TO_ALL    = '__manager__.commandToAll';
    public const COMMAND_REFRESH_METADATA = '__manager__.refreshMetadata';
    public const COMMAND_GET_METADATA     = '__manager__.getMetadata';

    /*** @var Worker[] */
    protected array $workers = [];

    /*** @var Zx7e */
    protected Zx7e $zx7e;

    /*** @var int */
    protected int $index = 1;

    /*** @var int */
    protected int $processID;

    /**
     * @param \Ripple\Worker\Worker $worker
     *
     * @return void
     */
    public function add(Worker $worker): void
    {
        $workerName = $worker->getName();
        if (isset($this->workers[$workerName])) {
            Output::warning("Worker {$workerName} already exists");
            return;
        }
        $this->workers[$workerName] = $worker;
    }

    /**
     * @return \Ripple\Worker\Worker[]
     */
    public function getWorkers(): array
    {
        return $this->workers;
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/17 00:13
     *
     * @param Command $workerCommand
     * @param string  $name
     * @param int     $index
     *
     * @return void
     */
    public function onCommand(Command $workerCommand, string $name, int $index): void
    {
        switch ($workerCommand->name) {
            case WorkerContext::COMMAND_RELOAD:
                $name = $workerCommand->arguments['name'] ?? null;
                $this->reload($name);
                break;

            case WorkerContext::COMMAND_SYNC_ID:
                if ($workerProcess = $this->workers[$name]?->getWorkerProcess($index)) {
                    $sync    = $this->index++;
                    $id      = $workerCommand->arguments['id'];
                    $command = Command::make(WorkerContext::COMMAND_SYNC_ID, ['sync' => $sync, 'id' => $id]);
                    $workerProcess->command($command);
                }
                break;

            case Manager::COMMAND_COMMAND_TO_WORKER:
                $command = $workerCommand->arguments['command'];
                $target  = $workerCommand->arguments['name'];
                $targetIndex = $workerCommand->arguments['index'];
                $this->sendCommand($command, $target, $targetIndex);
                break;

            case Manager::COMMAND_COMMAND_TO_ALL:
                $command = $workerCommand->arguments['command'];
                $this->sendCommand($command);
                break;

            case Manager::COMMAND_REFRESH_METADATA:
                if ($workerProcess = $this->workers[$name]?->getWorkerProcess($index)) {
                    $metadata = $workerCommand->arguments['metadata'];
                    $workerProcess->refreshMetadata($metadata);
                }
                break;

            case Manager::COMMAND_GET_METADATA:
                $result = [];
                foreach ($this->workers as $worker) {
                    $name          = $worker->getName();
                    $result[$name] = [];
                    foreach ($worker->getWorkerProcess() as $workerProcess) {
                        foreach ($workerProcess->getMetadata() as $key => $value) {
                            $result[$name][$key] = $value;
                        }
                    }
                }
                $id      = $workerCommand->arguments['id'];
                $command = Command::make(Manager::COMMAND_GET_METADATA, ['metadata' => $result, 'id' => $id]);
                $this->sendCommand($command, $name, $index);
                break;
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/17 02:01
     *
     * @param string|null $name
     *
     * @return void
     */
    public function reload(string|null $name = null): void
    {
        if ($name) {
            if (isset($this->workers[$name])) {
                $this->sendCommand(Command::make(WorkerContext::COMMAND_RELOAD), $name);
            }
            return;
        }
        $this->sendCommand(Command::make(WorkerContext::COMMAND_RELOAD));
    }

    /**
     * @param \Ripple\Worker\Command $command
     * @param string|null            $name
     * @param int|null $index
     *
     * @return void
     */
    public function sendCommand(Command $command, string|null $name = null, int|null $index = null): void
    {
        if ($name) {
            if (!$worker = $this->workers[$name] ?? null) {
                return;
            }

            $workerProcesses = $index ? [$worker->getWorkerProcess($index)] : $worker->getWorkerProcess();
            foreach ($workerProcesses as $workerProcess) {
                $workerProcess?->command($command);
            }
        } else {
            $workers = $this->workers;
            foreach ($workers as $worker) {
                $this->sendCommand($command, $worker->getName());
            }
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/16 12:28
     * @return bool
     */
    public function run(): bool
    {
        /*** @compatible:Windows */
        if (!Kernel::getInstance()->supportProcessControl()) {
            $this->processID = getmypid();
        } else {
            $this->processID = Kernel::getInstance()->getProcessId();
        }

        $this->zx7e = new Zx7e();
        foreach ($this->workers as $worker) {
            try {
                $worker->register($this);
            } catch (Throwable $exception) {
                Output::error("Worker {$worker->getName()} registration failed: {$exception->getMessage()}, will be removed");
                $this->remove($worker->getName());
                return false;
            }
        }

        foreach ($this->workers as $worker) {
            if (!$worker->run($this)) {
                Output::error("worker {$worker->getName()} failed to start");
                $this->terminate();
            }
        }
        return true;
    }

    /**
     * @param string $name
     *
     * @return void
     */
    public function remove(string $name): void
    {
        if ($worker = $this->workers[$name] ?? null) {
            $worker->isRunning() && $this->terminate($name);
            unset($this->workers[$name]);
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/17 00:44
     *
     * @param string|null $name
     *
     * @return void
     */
    public function terminate(string|null $name = null): void
    {
        if ($name) {
            if ($worker = $this->workers[$name] ?? null) {
                $worker->terminate();
            }
            return;
        }

        foreach ($this->workers as $worker) {
            $worker->terminate();
        }
    }

    /**
     *
     */
    public function __destruct()
    {
        if (!Kernel::getInstance()->supportProcessControl()) {
            return;
        }

        if (isset($this->processID) && $this->processID === Kernel::getInstance()->getProcessId()) {
            $this->terminate();
        }
    }

    /**
     * @param string $name
     *
     * @return \Ripple\Worker\Worker|null
     */
    public function get(string $name): Worker|null
    {
        return $this->workers[$name] ?? null;
    }
}
