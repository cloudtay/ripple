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

use Closure;
use JetBrains\PhpStorm\NoReturn;
use Ripple\Socket;
use Ripple\Stream\Exception\ConnectionException;
use Ripple\Utils\Output;
use Ripple\Utils\Serialization\Zx7e;
use Throwable;

use function Co\promise;
use function Co\repeat;
use function spl_object_hash;
use function count;
use function memory_get_peak_usage;
use function memory_get_usage;
use function sys_getloadavg;

/**
 * @Author cclilshy
 * @Date   2024/8/16 11:53
 */
abstract class Worker extends WorkerContext
{
    /**
     * @Context worker
     * @var Zx7e
     */
    protected Zx7e $zx7e;

    /*** @var \Ripple\Socket */
    protected Socket $parentSocket;

    /*** @var array */
    private array $queue = [];

    /*** @var int */
    private int $index;

    /**
     * Use the worker to send commands to other workers
     *
     * @param \Ripple\Worker\Command $command
     * @param string|null            $name
     * @param int|null               $index
     *
     * @return void
     */
    public function forwardCommand(Command $command, string|null $name = null, int|null $index = null): void
    {
        if ($name) {
            $this->sc2m(Command::make(Manager::COMMAND_COMMAND_TO_WORKER, [
                'command' => $command,
                'name'    => $name,
                'index'   => $index
            ]));
        } else {
            $this->sc2m(Command::make(Manager::COMMAND_COMMAND_TO_ALL, [
                'command' => $command,
            ]));
        }
    }

    /**
     * 发送指令
     *
     * @Context  worker
     * @Author   cclilshy
     * @Date     2024/8/17 00:07
     *
     * @param Command $command
     *
     * @return void
     */
    public function sc2m(Command $command): void
    {
        try {
            $this->parentSocket->write($this->zx7e->encodeFrame($command->__toString()));
        } catch (ConnectionException $exception) {
            Output::error($exception->getMessage());

            // Writing a message to the parent process fails. There is only one possibility that the parent process has exited.
            exit(1);
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/17 17:32
     * @return int|false
     */
    protected function getSyncID(): int|false
    {
        try {
            return promise(function (Closure $resolve, Closure $reject) {
                $id      = spl_object_hash($resolve);
                $command = Command::make(WorkerContext::COMMAND_SYNC_ID, ['id' => $id]);
                $this->sc2m($command);

                $this->queue[$id] = [
                    'resolve' => $resolve,
                    'reject'  => $reject
                ];
            })->await();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array|false
     */
    protected function getManagerMateData(): array|false
    {
        try {
            return promise(function (Closure $resolve, Closure $reject) {
                $id      = spl_object_hash($resolve);
                $command = Command::make(Manager::COMMAND_GET_METADATA, ['id' => $id]);
                $this->sc2m($command);

                $this->queue[$id] = [
                    'resolve' => $resolve,
                    'reject'  => $reject
                ];
            })->await();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param \Ripple\Socket $parentStream
     * @param int            $index
     *
     * @return void
     */
    final protected function onProcess(Socket $parentStream, int $index): void
    {
        $this->parentSocket = $parentStream;

        $this->zx7e = new Zx7e();
        $this->parentSocket->onReadable(function (Socket $Socket) {
            $content = $Socket->readContinuously(1024);
            foreach ($this->zx7e->decodeStream($content) as $string) {
                $command = Command::fromString($string);
                switch ($command->name) {
                    case WorkerContext::COMMAND_RELOAD:
                        $this->onReload();
                        break;

                    case WorkerContext::COMMAND_TERMINATE:
                        $this->onTerminate();
                        exit(1);
                        break;

                    case WorkerContext::COMMAND_SYNC_ID:
                        $id   = $command->arguments['id'];
                        $sync = $command->arguments['sync'];
                        if ($callback = $this->queue[$id] ?? null) {
                            unset($this->queue[$id]);
                            $callback['resolve']($sync);
                        }
                        break;

                    case Manager::COMMAND_GET_METADATA:
                        $id = $command->arguments['id'];
                        if ($callback = $this->queue[$id] ?? null) {
                            unset($this->queue[$id]);
                            $callback['resolve']($command->arguments['metadata']);
                        }
                        break;

                    default:
                        $this->onCommand($command);
                }
            }
        });

        try {
            $this->onDefinedIndex($index);
            $this->boot();
            repeat(function () {
                $this->sc2m(Command::make(Manager::COMMAND_REFRESH_METADATA, [
                    'metadata' => $this->getMetadata()
                ]));
            }, 1);
        } catch (Throwable $exception) {
            Output::error('Worker boot failed: ' . $exception->getMessage());
            exit(128);
        }
    }

    /**
     * Triggered during hot restart. The notified process should follow the hot restart rules to release resources and then exit.
     *
     * @Context  worker
     * @Author   cclilshy
     * @Date     2024/8/17 00:59
     * @return void
     */
    #[NoReturn] protected function onReload(): void
    {
        exit(0);
    }

    /**
     * @return void
     */
    #[NoReturn] protected function onTerminate(): void
    {
        exit(0);
    }

    /**
     * Triggered when command is received
     *
     * @Context  worker
     * @Author   cclilshy
     * @Date     2024/8/16 11:54
     *
     * @param Command $command
     *
     * @return void
     */
    protected function onCommand(Command $command): void
    {
    }

    /**
     * @param int $index
     *
     * @return void
     */
    protected function onDefinedIndex(int $index): void
    {
        $this->index = $index;
    }

    /**
     * @Context  worker
     * @Author   cclilshy
     * @Date     2024/8/16 11:53
     * @return void
     */
    public function boot(): void
    {
    }

    /**
     * @return array
     */
    protected function getMetadata(): array
    {
        return [
            'memory'      => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'queue_size'  => count($this->queue),
            'cpu'         => sys_getloadavg(),
        ];
    }

    /**
     * @return int
     */
    protected function getIndex(): int
    {
        return $this->index;
    }
}
