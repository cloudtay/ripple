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

namespace Psc\Worker;

use P\System;
use Psc\Core\Process\Runtime;
use Psc\Core\Stream\SocketStream;
use Psc\Std\Stream\Exception\ConnectionException;
use Psc\Utils\Output;
use Psc\Utils\Serialization\Zx7e;

use function socket_create_pair;
use function socket_export_stream;

use const AF_UNIX;
use const SOCK_STREAM;

/**
 * @Author cclilshy
 * @Date   2024/8/16 11:53
 */
abstract class Worker
{
    public const COMMAND_RELOAD = 'worker.reload';

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
     * @Context  manager
     * @Author   cclilshy
     * @Date     2024/8/16 11:53
     * @param Manager $manager
     * @return void
     */
    abstract public function register(Manager $manager): void;

    /**
     * @Context  worker
     * @Author   cclilshy
     * @Date     2024/8/16 11:53
     * @return void
     */
    abstract public function boot(): void;

    /**
     * @Context  worker
     * 热重启时触发,被通知的进程应遵循热重启规则释放资源后退出
     * @Author   cclilshy
     * @Date     2024/8/17 00:59
     * @return void
     */
    abstract public function onReload(): void;

    /**
     * 收到指令时触发
     * @Context  worker
     * @Author   cclilshy
     * @Date     2024/8/16 11:54
     * @param Command $workerCommand
     * @return void
     */
    abstract public function onCommand(Command $workerCommand): void;

    /**
     * @Context  share
     * @Author   cclilshy
     * @Date     2024/8/17 01:05
     * @return string
     */
    abstract public function getName(): string;

    /**
     * @Context  share
     * @Author   cclilshy
     * @Date     2024/8/17 01:06
     * @return int
     */
    abstract public function getCount(): int;

    /**
     * 发送指令
     * @Context  worker
     * @Author   cclilshy
     * @Date     2024/8/17 00:07
     * @param Command $command
     * @return void
     */
    protected function command(Command $command): void
    {
        try {
            $this->parentSocket->write($this->zx7e->encodeFrame($command->__toString()));
        } catch (ConnectionException $exception) {
            Output::error($exception->getMessage());
            exit(1);
        }
    }

    /**
     * 发送指令到指定Worker
     * @Context  worker
     * @Author   cclilshy
     * @Date     2024/8/17 00:18
     * @param Command $command
     * @param string  $name
     * @return void
     */
    protected function commandToWorker(Command $command, string $name): void
    {
        $this->command(Command::make(Manager::COMMAND_COMMAND_TO_WORKER, [
            'command' => $command,
            'name'    => $name
        ]));
    }

    /**
     * @Context  worker
     * @Author   cclilshy
     * @Date     2024/8/17 09:48
     * @param Command $command
     * @return void
     */
    protected function commandToAll(Command $command): void
    {
        $this->command(Command::make(Manager::COMMAND_COMMAND_TO_ALL, [
            'command' => $command
        ]));
    }

    /**
     * @Context  worker
     * @Author   cclilshy
     * @Date     2024/8/17 01:03
     * @param Command $workerCommand
     * @return void
     */
    private function _onCommand(Command $workerCommand): void
    {
        switch ($workerCommand->name) {
            case Worker::COMMAND_RELOAD:
                $this->onReload();
                break;
            default:
                $this->onCommand($workerCommand);
        }
    }

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
     * @Context manager
     * @Author  cclilshy
     * @Date    2024/8/16 23:50
     * @param Manager $manager
     * @return bool
     */
    public function __invoke(Manager $manager): bool
    {
        for ($index = 1; $index <= $this->getCount(); $index++) {
            if(!$this->guard($manager, $index)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/17 14:25
     * @param Manager $manager
     * @param int     $index
     * @return bool
     */
    private function guard(Manager $manager, int $index): bool
    {
        if (!socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets)) {
            return false;
        }

        $streamA = new SocketStream(socket_export_stream($sockets[0]));
        $streamB = new SocketStream(socket_export_stream($sockets[1]));
        $streamA->setBlocking(false);
        $streamB->setBlocking(false);

        $zx7e                  = new Zx7e();
        $this->streams[$index] = $streamA;
        $this->streams[$index]->onReadable(function (SocketStream $socketStream) use ($streamA, $index, $zx7e, $manager) {
            foreach ($zx7e->decodeStream($socketStream->read(8192)) as $string) {
                $manager->onCommand(Command::fromString($string), $this->getName(), $index);
            }
        });

        $this->runtimes[$index] = $runtime = System::Process()->task(function () use ($streamB) {
            $this->parent       = false;
            $this->parentSocket = $streamB;
            $this->boot();

            $this->zx7e = new Zx7e();
            $this->parentSocket->onReadable(function (SocketStream $socketStream) {
                foreach ($this->zx7e->decodeStream($socketStream->read(8192)) as $string) {
                    $this->_onCommand(Command::fromString($string));
                }
            });
        })->run();

        $runtime->finally(fn () => $this->guard($manager, $index));
        return true;
    }
}
