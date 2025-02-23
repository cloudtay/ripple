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

namespace Ripple;

use Closure;
use Revolt\EventLoop;
use Ripple\Coroutine\Coroutine;
use Ripple\Stream\Exception\ConnectionCloseException;
use Ripple\Stream\Exception\ConnectionException;
use Ripple\Stream\Exception\ConnectionTimeoutException;
use Ripple\Stream\Stream as StreamBase;
use Ripple\Utils\Format;
use Ripple\Utils\Output;
use Throwable;

use function call_user_func;
use function call_user_func_array;
use function Co\async;
use function Co\cancel;
use function Co\getContext;
use function is_resource;
use function stream_set_blocking;

/**
 * 2024/09/21
 *
 * After production testing and the design architecture of the current framework, the following decisions can meet the needs of the existing design,
 * so the following decisions are made:
 *
 * This class only focuses on the reliability of events and does not guarantee data integrity issues caused by write and buffer size.
 * It is positioned as a Stream in the application layer.
 *
 * Standards that are as safe and easy to use as possible should be followed, allowing some performance to be lost. For more
 * fine-grained control, please use the StreamBase class.
 *
 * Provide onReadable/onWriteable methods for monitoring readable and writeable events, and any uncaught ConnectionException
 * that occurs in the event will cause the Stream to close
 *
 * Both the onReadable and onWritable methods will automatically cancel the previous monitoring.
 *
 * The closed stream will automatically log out all monitored events. If there is a transaction, it will automatically
 * mark the transaction as failed.
 *
 * @Author cclilshy
 * @Date   2024/8/16 09:37
 */
class Stream extends StreamBase
{
    /**
     * @var string
     */
    protected string $onReadable;

    /**
     * @var string
     */
    protected string $onWriteable;

    /**
     * @var array
     */
    protected array $onCloseCallbacks = array();

    /**
     * @var int
     */
    protected int $index = 0;

    /**
     * @param mixed $resource
     */
    public function __construct(mixed $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @param bool $bool
     *
     * @return bool
     */
    public function setBlocking(bool $bool): bool
    {
        return stream_set_blocking($this->stream, $bool);
    }

    /**
     * Wait for readable events. This method is only valid when there are no readable events to listen for.
     * After enabling this method, it is forbidden to use the onReadable method elsewhere unless you know what you are doing.
     *
     * After getting the result of this event, please do not perform other asynchronous context operations including \Co\sleep
     * in the current coroutine except the waitForReadable method.
     * Please put other asynchronous operations in the new coroutine,
     *
     * @param int|float $timeout
     *
     * @return bool
     * @throws Throwable
     */
    public function waitForReadable(int|float $timeout = 0): bool
    {
        $context = getContext();
        if (!isset($this->onReadable)) {
            $this->onReadable(static fn () => Coroutine::resume($context, true));
        }

        // If the stream is closed, return false directly.
        $closeOID = $this->onClose(static function () use ($context) {
            Coroutine::throw(
                $context,
                new ConnectionCloseException('Stream has been closed', null)
            );
        });

        $resumed = false;
        if ($timeout > 0) {
            // If a timeout is set, the context will be canceled after the timeout
            async(static function () use ($context, $timeout, &$resumed) {
                \Co\sleep($timeout);
                $resumed || Coroutine::throw($context, new ConnectionTimeoutException('Stream write timeout'));
            });
        }

        try {
            $result  = Coroutine::suspend($context);
            $resumed = true;
            $this->cancelOnClose($closeOID);
            return $result;
        } finally {
            $this->cancelReadable();
        }
    }

    /**
     *
     * @param Closure $closure
     *
     * @return string
     */
    public function onReadable(Closure $closure): string
    {
        $this->cancelReadable();
        return $this->onReadable = EventLoop::onReadable($this->stream, function () use ($closure) {
            try {
                call_user_func_array($closure, [$this, fn () => $this->cancelReadable()]);
            } catch (Throwable $exception) {
                Output::error($exception->getMessage());
            }
        });
    }

    /**
     * @return void
     */
    public function cancelReadable(): void
    {
        if (isset($this->onReadable)) {
            cancel($this->onReadable);
            unset($this->onReadable);
        }
    }

    /**
     * @param Closure $closure
     *
     * @return string
     */
    public function onClose(Closure $closure): string
    {
        $this->onCloseCallbacks[$key = Format::int2string($this->index++)] = $closure;
        return $key;
    }

    /**
     * @param string $key
     *
     * @return void
     */
    public function cancelOnClose(string $key): void
    {
        unset($this->onCloseCallbacks[$key]);
    }

    /**
     * @return void
     */
    public function close(): void
    {
        if ($this->isClosed()) {
            return;
        }

        // Effective closing of the stream should occur before any callbacks to prevent the close method from being called again in the callbacks.
        parent::close();

        $this->cancelReadable();
        $this->cancelWriteable();

        foreach ($this->onCloseCallbacks as $callback) {
            try {
                call_user_func($callback);
            } catch (Throwable $exception) {
                Output::error($exception->getMessage());
            }
        }
    }

    /**
     * @return bool
     */
    public function isClosed(): bool
    {
        return !is_resource($this->stream);
    }

    /**
     * @return void
     */
    public function cancelWriteable(): void
    {
        if (isset($this->onWriteable)) {
            cancel($this->onWriteable);
            unset($this->onWriteable);
        }
    }

    /**
     * Wait for writeable events. This method is only valid when there is no writeable event listener.
     * After enabling this method, it is forbidden to use the onWritable method elsewhere unless you know what you are doing.
     *
     * After getting the result of this event, please do not perform other asynchronous context operations including \Co\sleep
     * in the current coroutine except the waitForWriteable method.
     * Please put other asynchronous operations in the new coroutine
     *
     * @param int|float $timeout
     *
     * @return bool
     * @throws Throwable
     */
    public function waitForWriteable(int|float $timeout = 0): bool
    {
        $context = getContext();
        if (!isset($this->onWriteable)) {
            $this->onWriteable(static fn () => Coroutine::resume($context, true));
        }

        // If the stream is closed, return false directly.
        $closeOID = $this->onClose(static function () use ($context) {
            Coroutine::throw(
                $context,
                new ConnectionCloseException('Stream has been closed')
            );
        });

        $resumed = false;
        if ($timeout > 0) {
            // If a timeout is set, the context will be canceled after the timeout
            async(static function () use ($context, $timeout, &$resumed) {
                \Co\sleep($timeout);
                $resumed || Coroutine::throw($context, new ConnectionTimeoutException('Stream write timeout'));
            });
        }

        try {
            $result  = Coroutine::suspend($context);
            $resumed = true;
            $this->cancelOnClose($closeOID);
            return $result;
        } finally {
            $this->cancelWriteable();
        }
    }

    /**
     * @param Closure $closure
     *
     * @return string
     */
    public function onWriteable(Closure $closure): string
    {
        $this->cancelWriteable();
        return $this->onWriteable = EventLoop::onWritable($this->stream, function () use ($closure) {
            try {
                call_user_func_array($closure, [$this, fn () => $this->cancelWriteable()]);
            } catch (Throwable $exception) {
                Output::error($exception->getMessage());
            }
        });
    }

    /**
     * @param int $length
     *
     * @return string
     * @throws ConnectionException
     */
    public function readContinuously(int $length): string
    {
        $content = '';
        while ($buffer = $this->read($length)) {
            $content .= $buffer;
        }

        return $content;
    }
}
