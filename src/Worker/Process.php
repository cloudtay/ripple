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

use Ripple\Coroutine;
use Ripple\Process as KernelProcess;
use Ripple\Runtime\Scheduler;
use Ripple\Serial\Zx7e;
use Ripple\Stream;
use Ripple\Stream\Exception\ConnectionException;
use RuntimeException;
use Throwable;

use function cli_set_process_title;
use function Co\go;
use function function_exists;
use function stream_set_blocking;
use function stream_socket_pair;

use const AF_UNIX;
use const SIGKILL;
use const SOCK_STREAM;

/**
 * @Author cclilshy
 * @Date   2025/9/25
 */
class Process
{
    /**
     * @var Zx7e
     */
    private Zx7e $zx7e;

    /**
     * @var Coroutine
     */
    private Coroutine $guard;

    /**
     * @var array
     */
    public array $metadata = [];

    /**
     * @param Manager $manager
     * @param BaseWorker $worker
     * @param int $index
     * @param int $pid
     * @param Stream $parentStream 父进程视角对端流
     * @throws ConnectionException
     */
    private function __construct(
        public readonly Manager    $manager,
        public readonly BaseWorker $worker,
        public readonly int        $index,
        public readonly int        $pid,
        public readonly Stream     $parentStream,
    ) {
        $this->spawnParent();
    }

    /**
     * 主进程视角：发送命令到子进程
     * @param Command $command
     * @return void
     * @throws ConnectionException
     */
    public function send(Command $command): void
    {
        $this->parentStream->writeAll(Zx7e::encode($command->__toString()));
    }

    /**
     * @return void
     */
    public function reload(): void
    {
        try {
            $this->send(Command::make(BaseWorker::COMMAND_RELOAD));
        } catch (ConnectionException $e) {
        }
    }

    /**
     * @return void
     */
    public function terminate(): void
    {
        Scheduler::terminate($this->guard)->unwrap();

        try {
            $this->send(Command::make(BaseWorker::COMMAND_TERMINATE));
        } catch (ConnectionException) {
        } finally {
            go(function () {
                \Co\sleep(1);
                KernelProcess::signal($this->pid, SIGKILL);
                $this->parentStream->close();
                unset($this->manager->process[$this->worker->name][$this->index]);
                if (empty($this->manager->process[$this->worker->name])) {
                    unset($this->manager->process[$this->worker->name]);
                }
            });
        }
    }

    /**
     * 启动并管理一个子进程
     * @param Manager $manager
     * @param BaseWorker $worker
     * @param int $index
     * @return Process
     * @throws ConnectionException
     */
    public static function spawn(Manager $manager, BaseWorker $worker, int $index): Process
    {
        if (!$pair = stream_socket_pair(AF_UNIX, SOCK_STREAM, 0)) {
            throw new RuntimeException('Could not create socket pair');
        }

        $parentStream = new Stream($pair[0]);
        $childStream = new Stream($pair[1]);

        stream_set_blocking($pair[0], false);
        stream_set_blocking($pair[1], false);

        $pid = KernelProcess::fork(static fn () => self::spawnChild($worker, $childStream));

        return new Process(
            manager: $manager,
            worker: $worker,
            index: $index,
            pid: $pid,
            parentStream: $parentStream,
        );
    }

    /**
     * @return void
     * @throws ConnectionException
     */
    private function spawnParent(): void
    {
        $this->zx7e = new Zx7e();

        $this->parentStream->watchRead(fn () => go(function () {
            while ($content = $this->parentStream->read(1024)) {
                foreach ($this->zx7e->fill($content) as $string) {
                    $this->manager->emitCommand(Command::fromString($string), $this->worker->name, $this->index);
                }

                if ($this->parentStream->eof()) {
                    $this->parentStream->close();
                }
            }
        }));

        $this->guard = go(function () {
            try {
                $exitCode = KernelProcess::wait($this->pid);
                $this->parentStream->close();
                unset($this->manager->process[$this->worker->name][$this->index]);
            } catch (Throwable) {
                return;
            }

            if ($exitCode === 1) {
                return;
            }

            if ($exitCode === 128) {
                return;
            }

            \Co\sleep(1);
            $this->manager->process[$this->worker->name][$this->index] = self::spawn($this->manager, $this->worker, $this->index);
        });
    }

    /**
     * @param BaseWorker $worker
     * @param Stream $childStream
     * @return void
     * @throws ConnectionException
     */
    private static function spawnChild(BaseWorker $worker, Stream $childStream): void
    {
        $worker->parentStream = $childStream;
        $worker->boot();

        if (function_exists('cli_set_process_title')) {
            cli_set_process_title("ripple-{$worker->name}");
        }

        $childZx7e = new Zx7e();
        $childStream->watchRead(static fn () => go(static function () use ($worker, $childStream, &$childZx7e) {
            while ($content = $childStream->read(1024)) {
                foreach ($childZx7e->fill($content) as $string) {
                    $command = Command::fromString($string);
                    switch ($command->name) {
                        case BaseWorker::COMMAND_RELOAD:
                            $worker->onReload();
                            break;

                        case BaseWorker::COMMAND_TERMINATE:
                            $worker->onTerminate();
                            break;

                        case Manager::COMMAND_SUPERVISOR_METADATA:
                            $id = $command->arguments['id'];
                            if ($owner = $worker->subs[$id] ?? null) {
                                unset($worker->subs[$id]);
                                Scheduler::resume($owner, $command->arguments['metadata']);
                            }
                            break;

                        default:
                            $worker->onCommand($command);
                    }
                }

                if ($childStream->eof()) {
                    exit(0);
                }
            }
        }));

        go(static function () use ($worker) {
            while (1) {
                $worker->sendToManager(
                    Command::make(
                        Manager::COMMAND_REFRESH_METADATA,
                        [
                            'metadata' => $worker->metrics()
                        ]
                    )
                );

                \Co\sleep(1);
            }
        });
    }
}
