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
use Ripple\Stream\Exception\ConnectionException;
use Ripple\Utils\Output;
use Ripple\Utils\Serialization\Zx7e;

use function getmypid;
use function posix_getpid;

/**
 * @Author cclilshy
 * @Date   2024/8/16 11:51
 */
class Manager
{
    public const COMMAND_COMMAND_TO_WORKER = 'manager.commandToWorker';
    public const COMMAND_COMMAND_TO_ALL    = 'manager.commandToAll';

    /**
     * @var Worker[]
     */
    private array $workers = [];

    /**
     * @var Zx7e
     */
    private Zx7e $zx7e;

    /**
     * @var int
     */
    private int $index = 1;

    /**
     * @var int
     */
    private int $processID;

    /**
     * @Author cclilshy
     * @Date   2024/8/16 12:28
     *
     * @param Worker $worker
     *
     * @return void
     */
    public function addWorker(Worker $worker): void
    {
        if (isset($this->workers[$worker->getName()])) {
            Output::warning('Worker name already exists');
            return;
        }
        $this->workers[$worker->getName()] = $worker;
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/17 10:11
     *
     * @param string $name
     *
     * @return void
     */
    public function removeWorker(string $name): void
    {
        if ($this->workers[$name] ?? null) {
            $this->stopWorker($name);
            unset($this->workers[$name]);
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/17 10:14
     *
     * @param string $name
     *
     * @return void
     */
    public function stopWorker(string $name): void
    {
        if ($worker = $this->workers[$name] ?? null) {
            foreach ($worker->runtimes as $runtime) {
                $runtime->stop();
            }
            foreach ($worker->streams as $stream) {
                $stream->close();
            }
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/17 00:44
     * @return void
     */
    public function stop(): void
    {
        foreach ($this->workers as $worker) {
            $this->stopWorker($worker->getName());
        }
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
     * @throws ConnectionException
     */
    public function onCommand(Command $workerCommand, string $name, int $index): void
    {
        switch ($workerCommand->name) {
            case Worker::COMMAND_RELOAD:
                $name = $workerCommand->arguments['name'] ?? null;
                $this->reload($name);
                return;
            case Manager::COMMAND_COMMAND_TO_WORKER:
                $command = $workerCommand->arguments['command'];
                $target  = $workerCommand->arguments['name'];
                $this->commandToWorker($command, $target);
                break;
            case Manager::COMMAND_COMMAND_TO_ALL:
                $command = $workerCommand->arguments['command'];
                $this->commandToAll($command);
                break;
            case Worker::COMMAND_SYNC_ID:
                if ($stream = $this->workers[$name]?->streams[$index] ?? null) {
                    $sync    = $this->index++;
                    $id      = $workerCommand->arguments['id'];
                    $command = Command::make(Worker::COMMAND_SYNC_ID, ['sync' => $sync, 'id' => $id]);
                    $stream->write($this->zx7e->encodeFrame($command->__toString()));
                }
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
     * @throws ConnectionException
     */
    public function reload(string|null $name = null): void
    {
        if ($name) {
            if (isset($this->workers[$name])) {
                $this->commandToWorker(Command::make(Worker::COMMAND_RELOAD), $name);
            }
            return;
        }
        $this->commandToAll(Command::make(Worker::COMMAND_RELOAD));
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/17 09:28
     *
     * @param Command $command
     * @param string  $name
     *
     * @return void
     * @throws ConnectionException
     */
    public function commandToWorker(Command $command, string $name): void
    {
        if (isset($this->workers[$name])) {
            foreach ($this->workers[$name]->streams as $stream) {
                $stream->write($this->zx7e->encodeFrame($command->__toString()));
            }
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/17 09:23
     *
     * @param Command $command
     *
     * @return void
     * @throws ConnectionException
     */
    public function commandToAll(Command $command): void
    {
        $workers = $this->workers;
        foreach ($workers as $worker) {
            foreach ($worker->streams as $stream) {
                $stream->write($this->zx7e->encodeFrame($command->__toString()));
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
            $this->processID = posix_getpid();
        }
        $this->zx7e = new Zx7e();
        foreach ($this->workers as $worker) {
            $worker->register($this);
            if (!$worker($this)) {
                Output::error("worker {$worker->getName()} failed to start");
                $this->stop();
            }
        }
        return true;
    }

    public function __destruct()
    {
        if (!Kernel::getInstance()->supportProcessControl()) {
            return;
        }
        if (isset($this->processID) && $this->processID === posix_getpid()) {
            $this->stop();
        }
    }
}
