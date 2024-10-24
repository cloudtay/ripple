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

namespace Ripple\Stream;

use Closure;
use Exception;
use Revolt\EventLoop;
use Ripple\Coroutine\Coroutine;
use Ripple\Coroutine\Promise;
use Ripple\Coroutine\Suspension;
use Ripple\Stream\Exception\ConnectionCloseException;
use Ripple\Stream\Exception\ConnectionException;
use Ripple\Stream\Exception\ConnectionTimeoutException;
use Ripple\Utils\Output;
use Throwable;

use function call_user_func;
use function call_user_func_array;
use function Co\cancel;
use function Co\delay;
use function Co\getSuspension;
use function int2string;
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
     * @var Transaction
     */
    protected Transaction $transaction;

    /**
     * @param mixed $resource
     */
    public function __construct(mixed $resource)
    {
        parent::__construct($resource);

        $this->onClose(function () {
            $this->cancelReadable();
            $this->cancelWriteable();
        });
    }

    /**
     * @param Closure $closure
     *
     * @return string
     */
    public function onClose(Closure $closure): string
    {
        $this->onCloseCallbacks[$key = int2string($this->index++)] = $closure;
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
            } catch (Throwable $e) {
                Output::error($e->getMessage());
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

        if (isset($this->transaction)) {
            $this->failTransaction(new ConnectionException(
                'Stream has been closed',
                ConnectionException::CONNECTION_CLOSED,
                null,
                $this
            ));
        }

        foreach ($this->onCloseCallbacks as $callback) {
            try {
                call_user_func($callback);
            } catch (Throwable $e) {
                Output::error($e->getMessage());
            }
        }
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
     * @param Throwable $exception
     *
     * @return void
     */
    public function failTransaction(Throwable $exception): void
    {
        if (isset($this->transaction)) {
            $this->transaction->fail($exception);
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
            } catch (Throwable $e) {
                Output::error($e->getMessage());
            }
        });
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
     * @param Closure $closure
     *
     * @return void
     * @throws Throwable
     */
    public function transaction(Closure $closure): void
    {
        if (isset($this->transaction) && $this->transaction->getPromise()->getStatus() === Promise::PENDING) {
            throw new Exception('Transaction has been completed');
        }

        $this->setTransaction(new Transaction($this));
        call_user_func_array($closure, [$this->getTransaction()]);
    }

    /**
     * @param Transaction $transaction
     *
     * @return void
     */
    protected function setTransaction(Transaction $transaction): void
    {
        if (isset($this->transaction)) {
            $this->completeTransaction();
            unset($this->transaction);
        }
        $this->transaction = $transaction;
    }

    /**
     * @return void
     */
    public function completeTransaction(): void
    {
        if (isset($this->transaction)) {
            $this->transaction->complete();
        }
    }

    /**
     * @return Transaction|null
     */
    public function getTransaction(): Transaction|null
    {
        if (isset($this->transaction)) {
            return $this->transaction;
        }
        return null;
    }

    /**
     * Wait for readable events. This method is only valid when there are no readable events to listen for.
     * After enabling this method, it is forbidden to use the onReadable method elsewhere unless you know what you are doing.
     *
     * After getting the result of this event, please do not perform other asynchronous suspension operations including \Co\sleep
     * in the current coroutine except the waitForReadable method.
     * Please put other asynchronous operations in the new coroutine,
     *
     * @param int $timeout
     *
     * @return bool
     * @throws ConnectionTimeoutException
     * @throws ConnectionCloseException
     */
    public function waitForReadable(int $timeout = 0): bool
    {
        $suspension = getSuspension();
        if (!isset($this->onReadable)) {
            $this->onReadable(static fn () => Coroutine::resume($suspension, true));
            $suspension instanceof Suspension && $suspension->promise->finally(fn () => $this->cancelReadable());
        }

        // If the stream is closed, return false directly.
        $closeOID = $this->onClose(static fn () => Coroutine::throw(
            $suspension,
            new ConnectionCloseException('Stream has been closed', null, $this)
        ));
        if ($timeout > 0) {
            // If a timeout is set, the suspension will be canceled after the timeout
            $timeoutOID = delay(static fn () => Coroutine::throw(
                $suspension,
                new ConnectionTimeoutException('Stream read timeout', null, $this, false)
            ), $timeout);
        }

        try {
            $result = Coroutine::suspend($suspension);
            $this->cancelOnClose($closeOID);
            isset($timeoutOID) && cancel($timeoutOID);
            return $result;
        } catch (ConnectionTimeoutException $e) {
            throw $e;
        } catch (ConnectionCloseException $e) {
            $this->close();
            throw $e;
        } catch (Throwable) {
            //Generally speaking, no exception will be thrown here
            $this->close();
            return false;
        }
    }

    /**
     * Wait for writeable events. This method is only valid when there is no writeable event listener.
     * After enabling this method, it is forbidden to use the onWritable method elsewhere unless you know what you are doing.
     *
     * After getting the result of this event, please do not perform other asynchronous suspension operations including \Co\sleep
     * in the current coroutine except the waitForWriteable method.
     * Please put other asynchronous operations in the new coroutine
     *
     * @param int $timeout
     *
     * @return bool
     * @throws ConnectionCloseException
     * @throws ConnectionTimeoutException
     */
    public function waitForWriteable(int $timeout = 0): bool
    {
        $suspension = getSuspension();
        if (!isset($this->onWriteable)) {
            $this->onWriteable(static fn () => Coroutine::resume($suspension, true));
            $suspension instanceof Suspension && $suspension->promise->finally(fn () => $this->cancelWriteable());
        }

        // If the stream is closed, return false directly.
        $closeOID = $this->onClose(static fn () => Coroutine::throw(
            $suspension,
            new ConnectionCloseException('Stream has been closed', null, $this)
        ));
        if ($timeout > 0) {
            // If a timeout is set, the suspension will be canceled after the timeout
            $timeoutOID = delay(static fn () => Coroutine::throw(
                $suspension,
                new ConnectionTimeoutException('Stream write timeout', null, $this, false)
            ), $timeout);
        }

        try {
            $result = Coroutine::suspend($suspension);
            $this->cancelOnClose($closeOID);
            isset($timeoutOID) && cancel($timeoutOID);
            return $result;
        } catch (ConnectionTimeoutException $e) {
            throw $e;
        } catch (ConnectionCloseException $e) {
            $this->close();
            throw $e;
        } catch (Throwable) {
            //Generally speaking, no exception will be thrown here
            $this->close();
            return false;
        }
    }

    /**
     * @return bool
     */
    public function isClosed(): bool
    {
        return !is_resource($this->stream);
    }
}
