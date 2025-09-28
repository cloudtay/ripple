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

use Ripple\Runtime\Support\Stdin;
use Ripple\Serial\Zx7e;
use Ripple\Stream\Exception\ConnectionException;
use Ripple\Worker;
use Throwable;
use InvalidArgumentException;

/**
 * @Author cclilshy
 * @Date   2024/8/16 11:51
 */
class Manager
{
    public const COMMAND_COMMAND_TO_WORKER = '__manager__.COMMAND_COMMAND_TO_WORKER';

    public const COMMAND_COMMAND_TO_ALL = '__manager__.COMMAND_COMMAND_TO_ALL';

    public const COMMAND_REFRESH_METADATA = '__manager__.COMMAND_REFRESH_METADATA';

    public const COMMAND_SUPERVISOR_METADATA = '__manager__.COMMAND_SUPERVISOR_METADATA';

    /**
     * worker列表
     * @var Worker[]
     */
    public array $workers = [];

    /**
     * 编码器
     * @var Zx7e
     */
    public Zx7e $zx7e;

    /**
     * @var Array<string,Process[]>
     */
    public array $process = [];

    /**
     *
     */
    public function __construct()
    {
    }

    /**
     * @param Worker $worker
     * @return void
     */
    public function add(Worker $worker): void
    {
        $workerName = $worker->name;
        if (isset($this->workers[$workerName])) {
            throw new InvalidArgumentException("Worker {$workerName} already exists");
        }
        $this->workers[$workerName] = $worker;
    }

    /**
     * @param Command $command
     * @param string $name
     * @param int $index
     *
     * @return void
     */
    public function emitCommand(Command $command, string $name, int $index): void
    {
        switch ($command->name) {
            case BaseWorker::COMMAND_RELOAD:
                $name = $command->arguments['name'] ?? null;
                $this->reload($name);
                break;

            case Manager::COMMAND_COMMAND_TO_WORKER:
                $innerCommand = $command->arguments['command'];
                $target = $command->arguments['name'];
                $targetIndex = $command->arguments['index'];
                $this->sendToWorker($innerCommand, $target, $targetIndex);
                break;

            case Manager::COMMAND_COMMAND_TO_ALL:
                $innerCommand = $command->arguments['command'];
                $this->sendToWorker($innerCommand);
                break;

            case Manager::COMMAND_REFRESH_METADATA:
                $metadata = $command->arguments['metadata'];
                if (isset($this->process[$name][$index])) {
                    $this->process[$name][$index]->metadata = $metadata;
                }
                break;

            case Manager::COMMAND_SUPERVISOR_METADATA:
                $result = [];
                foreach ($this->workers as $worker) {
                    $name = $worker->name;
                    $result[$name] = [];

                    foreach ($this->process as $workerName => $processes) {
                        foreach ($processes as $index => $process) {
                            $result[$name][$workerName][$index] = $process->metadata;
                        }
                    }
                }

                $id = $command->arguments['id'];
                $command = Command::make(Manager::COMMAND_SUPERVISOR_METADATA, ['metadata' => $result, 'id' => $id]);
                $this->sendToWorker($command, $name, $index);
                break;
        }
    }

    /**
     * @param Command $command
     * @param ?string $name
     * @param int|null $index
     *
     * @return void
     */
    public function sendToWorker(Command $command, ?string $name = null, int|null $index = null): void
    {
        foreach ($this->process as $workerName => $processes) {
            if ($name && $workerName !== $name) {
                continue;
            }

            foreach ($processes as $workerIndex => $process) {
                if ($index && $workerIndex !== $index) {
                    continue;
                }

                try {
                    $process->send($command);
                } catch (ConnectionException $e) {
                    continue;
                }
            }
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/16 12:28
     * @return void
     */
    public function run(): void
    {
        $this->zx7e = new Zx7e();

        foreach ($this->workers as $worker) {
            try {
                $worker->register();
            } catch (Throwable $exception) {
                Stdin::println("Worker {$worker->name} registration failed: {$exception->getMessage()}, will be removed");
                $this->remove($worker->name);
                return;
            }
        }

        foreach ($this->workers as $worker) {
            $this->process[$worker->name] = [];

            for ($index = 0; $index < $worker->count; $index++) {
                try {
                    $this->process[$worker->name][$index] = Process::spawn($this, $worker, $index);
                } catch (Throwable $exception) {
                    Stdin::println("Worker {$worker->name} boot failed: {$exception->getMessage()}, will be removed");
                    foreach ($this->process[$worker->name] as $process) {
                        $process->terminate();
                    }

                    break;
                }
            }
        }
    }

    /**
     * @param string $name
     * @return void
     */
    public function remove(string $name): void
    {
        if ($this->workers[$name] ?? null) {
            $this->terminate($name);
            unset($this->workers[$name]);
        }
    }

    /**
     * @param ?string $name
     * @return void
     */
    public function reload(?string $name = null): void
    {
        if ($name) {
            foreach ($this->process[$name] ?? [] as $process) {
                $process->reload();
            }
            return;
        }

        foreach ($this->process as $name => $processes) {
            foreach ($processes as $index => $process) {
                $process->reload();
            }
        }
    }


    /**
     * @param ?string $name
     * @return void
     */
    public function terminate(?string $name = null): void
    {
        if ($name) {
            foreach ($this->process[$name] ?? [] as $process) {
                $process->terminate();
            }
            return;
        }

        foreach ($this->process as $name => $processes) {
            foreach ($processes as $index => $process) {
                $process->terminate();
            }
        }
    }

    /**
     * @param string $name
     * @return Worker|null
     */
    public function get(string $name): Worker|null
    {
        return $this->workers[$name] ?? null;
    }
}
