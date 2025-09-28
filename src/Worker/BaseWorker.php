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

use JetBrains\PhpStorm\NoReturn;
use Ripple\Runtime\Support\Stdin;
use Ripple\Serial\Zx7e;
use Ripple\Stream;
use Throwable;

use function count;
use function memory_get_peak_usage;
use function memory_get_usage;
use function sys_getloadavg;

/**
 * @Author cclilshy
 * @Date   2024/8/16 11:53
 */
abstract class BaseWorker
{
    //
    public const COMMAND_RELOAD = '__worker__.COMMAND_RELOAD';

    //
    public const COMMAND_TERMINATE = '__worker__.COMMAND_TERMINATE';

    /**
     * 子进程视角父进程
     * @var Stream
     */
    public Stream $parentStream;

    /**
     * 订阅命令
     * @var array
     */
    public array $subs = [];

    /**
     * worker名称
     * @var string
     */
    public string $name;

    /**
     * 进程数
     * @var int
     */
    public int $count = 1;

    /**
     *
     */
    public function __construct()
    {
        if (!isset($this->name)) {
            $this->name = static::class;
        }
    }

    /**
     * @return void
     */
    public function register(): void
    {
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
     * @return void
     */
    #[NoReturn]
    public function onReload(): void
    {
        exit(0);
    }

    /**
     * @return void
     */
    #[NoReturn]
    public function onTerminate(): void
    {
        exit(1);
    }

    /**
     * Triggered when command is received
     * @param Command $command
     * @return void
     */
    public function onCommand(Command $command): void
    {
    }

    /**
     * Use the worker to send commands to other workers
     * @param Command $command
     * @param string|null $name
     * @param int|null $index
     * @return void
     */
    public function sendToWorker(Command $command, string|null $name = null, int|null $index = null): void
    {
        if ($name) {
            $this->sendToManager(Command::make(Manager::COMMAND_COMMAND_TO_WORKER, [
                'command' => $command,
                'name' => $name,
                'index' => $index
            ]));
        } else {
            $this->sendToManager(Command::make(Manager::COMMAND_COMMAND_TO_ALL, [
                'command' => $command,
            ]));
        }
    }

    /**
     * 发送指令
     * @param Command $command
     * @return void
     */
    public function sendToManager(Command $command): void
    {
        try {
            $this->parentStream->write(Zx7e::encode($command->__toString()));
        } catch (Throwable $exception) {
            Stdin::println($exception->getMessage());

            // Writing a message to the parent process fails. There is only one possibility that the parent process has exited.
            exit(1);
        }
    }

    /**
     * @return array|false
     */
    public function supervisorMetadata(): array|false
    {
        try {
            $owner = \Co\current();
            $id = $owner->id();
            $command = Command::make(Manager::COMMAND_SUPERVISOR_METADATA, ['id' => $id]);
            $this->sendToManager($command);
            $this->subs[$id] = $owner;
            return $owner->suspend();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array
     */
    public function metrics(): array
    {
        return [
            'memory' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true),
            'queue_size' => count($this->subs),
            'cpu' => sys_getloadavg(),
        ];
    }
}
