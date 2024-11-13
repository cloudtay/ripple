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
use Exception;
use Revolt\EventLoop;
use Ripple\Coroutine\Coroutine;
use Ripple\Coroutine\Suspension;
use Ripple\Stream\Exception\ConnectionCloseException;
use Ripple\Stream\Exception\ConnectionException;
use Ripple\Stream\Exception\ConnectionTimeoutException;
use Ripple\Stream\Stream as StreamBase;
use Ripple\Stream\Transaction;
use Ripple\Utils\Format;
use Ripple\Utils\Output;
use Throwable;

use function call_user_func;
use function call_user_func_array;
use function Co\cancel;
use function Co\delay;
use function Co\getSuspension;
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
        $this->onCloseCallbacks[$key = Format::int2string($this->index++)] = $closure;
        return $key;
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
    public function cancelWriteable(): void
    {
        if (isset($this->onWriteable)) {
            cancel($this->onWriteable);
            unset($this->onWriteable);
        }
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
     * @throws Throwable
     */
    public function waitForReadable(int $timeout = 0): bool
    {
        $suspension = getSuspension();
        if (!isset($this->onReadable)) {
            $this->onReadable(fn () => Coroutine::resume($suspension, true));
            $suspension instanceof Suspension && $suspension->promise->finally(fn () => $this->cancelReadable());
        }

        // If the stream is closed, return false directly.
        $closeOID = $this->onClose(function () use ($suspension) {
            Coroutine::throw(
                $suspension,
                new ConnectionCloseException('Stream has been closed', null)
            );
            $this->close();
        });

        if ($timeout > 0) {
            // If a timeout is set, the suspension will be canceled after the timeout
            $timeoutOID = delay(static function () use ($suspension) {
                Coroutine::throw($suspension, new ConnectionTimeoutException('Stream read timeout', null));
                $this->close();
            }, $timeout);
        }

        $result = Coroutine::suspend($suspension);
        $this->cancelOnClose($closeOID);
        isset($timeoutOID) && cancel($timeoutOID);
        return $result;
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
     * @param string $key
     *
     * @return void
     */
    public function cancelOnClose(string $key): void
    {
        unset($this->onCloseCallbacks[$key]);
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
     * @throws Throwable
     */
    public function waitForWriteable(int $timeout = 0): bool
    {
        $suspension = getSuspension();
        if (!isset($this->onWriteable)) {
            $this->onWriteable(fn () => Coroutine::resume($suspension, true));
            $suspension instanceof Suspension && $suspension->promise->finally(fn () => $this->cancelWriteable());
        }

        // If the stream is closed, return false directly.
        $closeOID = $this->onClose(function () use ($suspension) {
            Coroutine::throw($suspension, new ConnectionCloseException('Stream has been closed'));
            $this->close();
        });

        if ($timeout > 0) {
            // If a timeout is set, the suspension will be canceled after the timeout
            $timeoutOID = delay(static function () use ($suspension) {
                Coroutine::throw($suspension, new ConnectionTimeoutException('Stream write timeout'));
            }, $timeout);
        }

        $result = Coroutine::suspend($suspension);
        $this->cancelOnClose($closeOID);
        isset($timeoutOID) && cancel($timeoutOID);
        return $result;
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
}
