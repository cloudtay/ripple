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
use Ripple\Process\Exception\ProcessException;
use Ripple\Socket;
use Ripple\Utils\Output;
use Ripple\Utils\Serialization\Zx7e;

use function Co\async;
use function Co\delay;
use function Co\process;
use function socket_create_pair;
use function socket_export_stream;
use function min;
use function pow;
use function is_int;

use const AF_INET;
use const AF_UNIX;
use const SOCK_STREAM;
use const SOL_SOCKET;
use const SO_KEEPALIVE;
use const SIGKILL;

/**
 * This class is used to define the context of the worker process, hiding the underlying communication details
 */
abstract class WorkerContext
{
    public const COMMAND_RELOAD    = '__worker__.reload';
    public const COMMAND_TERMINATE = '__worker__.terminate';
    public const COMMAND_SYNC_ID   = '__worker__.sync.id';

    /**
     *
     */
    private const MAX_RESTART_ATTEMPTS = 10;

    /*** @var string */
    protected string $name;

    /*** @var int */
    protected int $count = 1;

    /*** @var WorkerProcess[] */
    protected array $processes = [];

    /*** @var bool */
    private bool $running = false;

    /*** @var bool */
    private bool $terminated = false;

    /*** @var \Ripple\Worker\Manager */
    private Manager $manager;

    /*** @var array */
    private array $restartAttempts = [];

    /**
     * @Context  manager
     * @Author   cclilshy
     * @Date     2024/8/16 11:53
     *
     * @param Manager $manager
     *
     * @return void
     */
    public function register(Manager $manager): void
    {
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
    public function run(Manager $manager): bool
    {
        $this->manager = $manager;

        /*** @compatible:Windows */
        $count = Kernel::getInstance()->supportProcessControl() ? $this->getCount() : 1;
        for ($index = 1; $index <= $count; $index++) {
            if (!$this->guard($index)) {
                $this->terminate();
                $manager->remove($this->getName());
                return false;
            }
        }

        $this->running = true;
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
     * @param int $index
     *
     * @return bool
     */
    private function guard(int $index): bool
    {
        /*** @compatible:Windows */
        $domain = Kernel::getInstance()->supportProcessControl() ? AF_UNIX : AF_INET;

        if (!socket_create_pair($domain, SOCK_STREAM, 0, $sockets)) {
            return false;
        }

        $streamA = new Socket(socket_export_stream($sockets[0]));
        $streamB = new Socket(socket_export_stream($sockets[1]));
        $streamA->setBlocking(false);
        $streamB->setBlocking(false);

        $streamA->setOption(SOL_SOCKET, SO_KEEPALIVE, 1);
        $streamB->setOption(SOL_SOCKET, SO_KEEPALIVE, 1);

        $streamA->onClose(fn () => $streamB->close());
        $zx7e                    = new Zx7e();
        $runtime                 = process(fn () => $this->onProcess($streamB, $index))->run();
        $this->processes[$index] = $workerProcess = new WorkerProcess($runtime, $streamA, $index);

        $workerProcess->getStream()->onReadable(function (Socket $Socket) use ($streamA, $index, &$zx7e) {
            $content = $Socket->readContinuously(1024);
            foreach ($zx7e->decodeStream($content) as $string) {
                $this->manager->onCommand(
                    Command::fromString($string),
                    $this->getName(),
                    $index
                );
            }
        });

        $workerProcess->getRuntime()->finally(function (mixed $result) use ($index) {
            if (is_int($result)) {
                $exitCode = $result;
            } elseif ($result instanceof ProcessException) {
                $exitCode = $result->getCode();
            } else {
                $exitCode = 0;
            }

            $this->onExit($index, $exitCode);
        });

        return true;
    }

    /**
     * @param \Ripple\Socket $parentStream
     * @param int            $index
     *
     * @return void
     */
    abstract protected function onProcess(Socket $parentStream, int $index): void;

    /**
     * @Context  share
     * @Author   cclilshy
     * @Date     2024/8/17 01:05
     * @return string
     */
    public function getName(): string
    {
        if (!isset($this->name)) {
            $this->name = static::class;
        }
        return $this->name;
    }

    /**
     * @param int $index
     * @param int $exitCode
     *
     * @return void
     */
    private function onExit(int $index, int $exitCode): void
    {
        if (isset($this->processes[$index])) {
            unset($this->processes[$index]);
        }

        if ($exitCode === 128) {
            Output::error("Worker '{$this->getName()}' process has exited with code 1.");
            return;
        }

        // Restart the process
        if (!$this->terminated) {
            $this->restartAttempts[$index] = ($this->restartAttempts[$index] ?? 0) + 1;

            if ($this->restartAttempts[$index] > WorkerContext::MAX_RESTART_ATTEMPTS) {
                Output::warning('Worker process has exited too many times, please check the code.');
                return;
            }

            $delay = min(0.1 * pow(2, $this->restartAttempts[$index] - 1), 30);
            delay(function () use ($index) {
                $this->guard($index);
            }, $delay);
        }
    }

    /**
     * @return void
     */
    public function terminate(): void
    {
        if ($this->terminated) {
            return;
        }

        $this->terminated = true;

        foreach ($this->getWorkerProcess() as $workerProcess) {
            $workerProcess->command(Command::make(WorkerContext::COMMAND_TERMINATE));
            async(static function () use ($workerProcess) {
                \Co\sleep(1);
                $workerProcess->isRunning() && $workerProcess->getRuntime()->signal(SIGKILL);
            });
        }

        $this->running = false;
    }

    /**
     * @param int|null $index
     *
     * @return WorkerProcess[]|WorkerProcess|null
     */
    public function getWorkerProcess(int|null $index = null): array|WorkerProcess|null
    {
        if (!$index) {
            return $this->processes;
        }
        return $this->processes[$index] ?? null;
    }

    /**
     * @return bool
     */
    public function isRunning(): bool
    {
        return $this->running;
    }
}
