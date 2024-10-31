<?php declare(strict_types=1);
/*
 * Copyright (c) 2023-2024.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * 特此免费授予任何获得本软件及相关文档文件（“软件”）副本的人，不受限制地处理
 * 本软件，包括但不限于使用、复制、修改、合并、出版、发行、再许可和/或销售
 * 软件副本的权利，并允许向其提供本软件的人做出上述行为，但须符合以下条件：
 *
 * 上述版权声明和本许可声明应包含在本软件的所有副本或主要部分中。
 *
 * 本软件按“原样”提供，不提供任何形式的保证，无论是明示或暗示的，
 * 包括但不限于适销性、特定目的的适用性和非侵权性的保证。在任何情况下，
 * 无论是合同诉讼、侵权行为还是其他方面，作者或版权持有人均不对
 * 由于软件或软件的使用或其他交易而引起的任何索赔、损害或其他责任承担责任。
 */

namespace Ripple;

use Closure;
use Co\System;
use JetBrains\PhpStorm\NoReturn;
use Ripple\Process\Runtime;
use Ripple\Socket\SocketStream;
use Ripple\Stream\Exception\ConnectionException;
use Ripple\Utils\Output;
use Ripple\Utils\Serialization\Zx7e;
use Ripple\Worker\Command;
use Ripple\Worker\Manager;
use Throwable;

use function Co\delay;
use function Co\promise;
use function socket_create_pair;
use function socket_export_stream;
use function spl_object_hash;

use const AF_INET;
use const AF_UNIX;
use const SOCK_STREAM;

/**
 * @Author cclilshy
 * @Date   2024/8/16 11:53
 */
abstract class Worker
{
    public const COMMAND_RELOAD  = 'worker.reload';
    public const COMMAND_SYNC_ID = 'worker.sync.id';

    /**
     * @Context manager
     * @var Runtime[]
     */
    public array $runtimes = array();

    /**
     * @Context manager
     * @var SocketStream[]
     */
    public array $streams = array();

    /**
     * @Context share
     * @var bool
     */
    protected bool $parent = true;

    /**
     * @Context worker
     * @var Zx7e
     */
    protected Zx7e $zx7e;

    /**
     * @Context worker
     * @var SocketStream
     */
    protected SocketStream $parentSocket;

    /**
     * @var int
     */
    protected int $count = 1;

    /**
     * @var string
     */
    protected string $name = 'worker';

    /**
     * @var array
     */
    private array $queue = [];

    /**
     * Send instructions to the specified Worker
     *
     * @Context  worker
     * @Author   cclilshy
     * @Date     2024/8/17 00:18
     *
     * @param Command $command
     * @param string  $name
     *
     * @return void
     */
    public function commandToWorker(Command $command, string $name): void
    {
        $this->command(Command::make(Manager::COMMAND_COMMAND_TO_WORKER, [
            'command' => $command,
            'name'    => $name
        ]));
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
    public function command(Command $command): void
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
     * @Context  worker
     * @Author   cclilshy
     * @Date     2024/8/17 09:48
     *
     * @param Command $command
     *
     * @return void
     */
    public function commandToAll(Command $command): void
    {
        $this->command(Command::make(Manager::COMMAND_COMMAND_TO_ALL, [
            'command' => $command
        ]));
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/17 17:32
     * @return int|false
     */
    public function syncID(): int|false
    {
        try {
            return promise(function (Closure $resolve, Closure $reject) {
                $id      = spl_object_hash($resolve);
                $command = Command::make(Worker::COMMAND_SYNC_ID, ['id' => $id]);
                $this->command($command);

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
     * @Context manager
     * @Author  cclilshy
     * @Date    2024/8/16 23:50
     *
     * @param Manager $manager
     *
     * @return bool
     */
    public function __invoke(Manager $manager): bool
    {
        /*** @compatible:Windows */
        $count = !Kernel::getInstance()->supportProcessControl() ? 1 : $this->getCount();
        for ($index = 1; $index <= $count; $index++) {
            if (!$this->guard($manager, $index)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @Context  share
     * @Author   cclilshy
     * @Date     2024/8/17 01:06
     * @return int
     */
    public function getCount(): int
    {
        return $this->count;
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/17 14:25
     *
     * @param Manager $manager
     * @param int     $index
     *
     * @return bool
     */
    private function guard(Manager $manager, int $index): bool
    {
        /*** @compatible:Windows */
        $domain = !Kernel::getInstance()->supportProcessControl() ? AF_INET : AF_UNIX;

        if (!socket_create_pair($domain, SOCK_STREAM, 0, $sockets)) {
            return false;
        }

        $streamA = new SocketStream(socket_export_stream($sockets[0]));
        $streamB = new SocketStream(socket_export_stream($sockets[1]));
        $streamA->setBlocking(false);
        $streamB->setBlocking(false);
        $streamA->onClose(fn () => $streamB->close());

        $zx7e                  = new Zx7e();
        $this->streams[$index] = $streamA;
        $this->streams[$index]->onReadable(function (SocketStream $socketStream) use ($streamA, $index, $zx7e, $manager) {
            $content = $socketStream->readContinuously(1024);
            foreach ($zx7e->decodeStream($content) as $string) {
                $manager->onCommand(Command::fromString($string), $this->getName(), $index);
            }
        });

        $this->runtimes[$index] = $runtime = System::Process()->task(function () use ($streamB) {
            $this->parent       = false;
            $this->parentSocket = $streamB;
            $this->boot();

            $this->zx7e = new Zx7e();
            $this->parentSocket->onReadable(function (SocketStream $socketStream) {
                $content = $socketStream->readContinuously(1024);
                foreach ($this->zx7e->decodeStream($content) as $string) {
                    $this->__onCommand(Command::fromString($string));
                }
            });
        })->run();

        $runtime->finally(function () use ($manager, $index) {
            if (isset($this->streams[$index])) {
                $this->streams[$index]->close();
                unset($this->streams[$index]);
            }

            if (isset($this->runtimes[$index])) {
                unset($this->runtimes[$index]);
            }
            delay(function () use ($manager, $index) {
                $this->guard($manager, $index);
            }, 0.1);
        });

        return true;
    }

    /**
     * Triggered when command is received
     *
     * @Context  worker
     * @Author   cclilshy
     * @Date     2024/8/16 11:54
     *
     * @param Command $workerCommand
     *
     * @return void
     */
    public function onCommand(Command $workerCommand): void
    {
    }

    /**
     * @Context  share
     * @Author   cclilshy
     * @Date     2024/8/17 01:05
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @Context  worker
     * @Author   cclilshy
     * @Date     2024/8/16 11:53
     * @return void
     */
    abstract public function boot(): void;

    /**
     * @Context  worker
     * @Author   cclilshy
     * @Date     2024/8/17 01:03
     *
     * @param Command $workerCommand
     *
     * @return void
     */
    private function __onCommand(Command $workerCommand): void
    {
        switch ($workerCommand->name) {
            case Worker::COMMAND_RELOAD:
                $this->onReload();
                break;

            case Worker::COMMAND_SYNC_ID:
                $id   = $workerCommand->arguments['id'];
                $sync = $workerCommand->arguments['sync'];

                if ($callback = $this->queue[$id] ?? null) {
                    unset($this->queue[$id]);
                    $callback['resolve']($sync);
                }
                break;

            default:
                $this->onCommand($workerCommand);
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
    #[NoReturn] public function onReload(): void
    {
        exit(0);
    }

    /**
     * @Context  manager
     * @Author   cclilshy
     * @Date     2024/8/16 11:53
     *
     * @param Manager $manager
     *
     * @return void
     */
    abstract public function register(Manager $manager): void;
}
