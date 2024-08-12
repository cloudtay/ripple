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

namespace Psc\Core\Stream;

use Closure;
use Psc\Std\Stream\Exception\ConnectionException;
use Psc\Utils\Output;
use Revolt\EventLoop;
use Throwable;

use function array_search;
use function call_user_func;
use function call_user_func_array;
use function is_resource;
use function P\cancel;
use function stream_set_blocking;

/**
 *
 */
class Stream extends \Psc\Std\Stream\Stream
{
    /**
     * @var string[]
     */
    private array $onReadable = array();

    /**
     * @var string[]
     */
    private array $onWritable = array();

    /**
     * @var array
     */
    private array $onCloseCallbacks = array();

    /**
     * @param mixed $resource
     */
    public function __construct(mixed $resource)
    {
        parent::__construct($resource);
        $this->onClose(function () {
            foreach ($this->onReadable as $id) {
                cancel($id);
            }
            foreach ($this->onWritable as $id) {
                cancel($id);
            }
        });
    }

    /**
     * @param Closure $closure
     * @return void
     */
    public function onClose(Closure $closure): void
    {
        $this->onCloseCallbacks[] = $closure;
    }

    /**
     * @param Closure $closure
     * @return string
     */
    public function onReadable(Closure $closure): string
    {
        $this->onReadable[] = $eventId = EventLoop::onReadable($this->stream, function (string $cancelId) use ($closure) {
            try {
                call_user_func_array($closure, [
                    $this,
                    function () use ($cancelId) {
                        cancel($cancelId);
                        $index = array_search($cancelId, $this->onReadable);
                        if ($index !== false) {
                            unset($this->onReadable[$index]);
                        }
                    }
                ]);
            } catch (ConnectionException $e) {
                $this->close();
            } catch (Throwable $e) {
                Output::error($e->getMessage());
            }
        });
        return $eventId;
    }



    /**
     * @param Closure $closure
     * @return string
     */
    public function onWritable(Closure $closure): string
    {
        $this->onWritable[] = $eventId = EventLoop::onWritable($this->stream, function (string $cancelId) use ($closure) {
            try {
                call_user_func_array($closure, [
                    $this,
                    function () use ($cancelId) {
                        cancel($cancelId);
                        $index = array_search($cancelId, $this->onWritable);
                        if ($index !== false) {
                            unset($this->onWritable[$index]);
                        }
                    }
                ]);
            } catch (ConnectionException $e) {
                $this->close();
                Output::error($e->getMessage());
            } catch (Throwable $e) {
                Output::error($e->getMessage());
            }
        });
        return $eventId;
    }

    /**
     * @param bool $bool
     * @return bool
     */
    public function setBlocking(bool $bool): bool
    {
        return stream_set_blocking($this->stream, $bool);
    }

    /**
     * @return void
     */
    public function close(): void
    {
        if (is_resource($this->stream) === false) {
            return;
        }

        parent::close();

        foreach ($this->onCloseCallbacks as $callback) {
            call_user_func($callback);
        }
    }
}
