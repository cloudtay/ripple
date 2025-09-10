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
use Ripple\Coroutine\Context;
use Ripple\Coroutine\Coroutine;
use Ripple\Stream\CloseEvent;
use Ripple\Stream\ConnectionAbortReason;
use Ripple\Stream\Exception\AbortConnection;
use Ripple\Stream\Exception\ConnectionException;
use Ripple\Stream\Exception\ConnectionTimeoutException;
use Ripple\Stream\Exception\TransportException;
use Ripple\Stream\Exception\WriteClosedException;
use Ripple\Stream\Stream as StreamBase;
use Ripple\Utils\Format;
use Ripple\Utils\Output;
use Throwable;

use function call_user_func;
use function call_user_func_array;
use function Co\async;
use function Co\cancel;
use function Co\getContext;
use function feof;
use function fread;
use function is_resource;
use function stream_set_blocking;
use function get_resource_id;
use function substr;
use function strlen;
use function end;
use function error_get_last;
use function str_contains;

/**
 * Enhanced Stream class with proper connection lifecycle management
 *
 * This class provides application-layer stream functionality with:
 * - Event-driven I/O (onReadable/onWriteable)
 * - Connection lifecycle events (onClose/onReadableEnd/onWritableEnd)
 * - Half-close support for protocols that need it
 * - Proper exception handling with clear separation between:
 *   - Internal control-flow exceptions (ConnectionException - DO NOT CATCH)
 *   - Application-level exceptions (TransportException and subclasses - safe to catch)
 *
 * Connection Lifecycle Events:
 * - onClose(CloseEvent): Triggered once when connection terminates, includes reason and initiator
 * - onReadableEnd(): Triggered when read side closes (EOF) but write may continue (half-close)
 * - onWritableEnd(): Triggered when write side closes but read may continue
 *
 * Exception Handling:
 * - ConnectionException: Internal reactor control-flow exception - NEVER catch this in user code
 * - TransportException: Recoverable transport errors - safe to catch and handle
 * - WriteClosedException: Attempt to write to closed write side - safe to catch
 *
 * Half-Close Support:
 * - When supportsHalfClose=true and onReadableEnd/onWritableEnd callbacks are registered,
 *   EOF conditions trigger the respective end events instead of immediate connection termination
 * - This allows protocols like HTTP to continue writing responses after reading the complete request
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
     * @var array
     */
    protected array $onReadableEndCallbacks = array();

    /**
     * @var array
     */
    protected array $onWritableEndCallbacks = array();

    /**
     * @var int
     */
    protected int $index = 0;

    /**
     * @var bool
     */
    protected bool $isReadOpen = true;

    /**
     * @var bool
     */
    protected bool $isWriteOpen = true;

    /**
     * @var bool
     */
    protected bool $isClosing = false;

    /**
     * @var bool
     */
    protected bool $supportsHalfClose = true;

    /**
     * @var int $id
     */
    public readonly int $id;

    /**
     * @param mixed $resource
     */
    public function __construct(mixed $resource)
    {
        parent::__construct($resource);
        $this->id = get_resource_id($resource);
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
                new TransportException('Stream has been closed')
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
            } catch (AbortConnection $e) {
                // Internal control-flow exception - close connection immediately
                $this->cancelReadable();
                $this->cancelWriteable();
                parent::close();

                // Extract reason from ConnectionException if available
                $reason = ($e instanceof ConnectionException)
                    ? $e->reason
                    : ConnectionAbortReason::RESET;
                $this->emitCloseEvent($reason, 'system', $e);
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
     * Register callback for readable end event (EOF/half-close)
     *
     * @param Closure $closure
     * @return string
     */
    public function onReadableEnd(Closure $closure): string
    {
        $this->onReadableEndCallbacks[$key = Format::int2string($this->index++)] = $closure;
        return $key;
    }

    /**
     * @param string $key
     * @return void
     */
    public function cancelOnReadableEnd(string $key): void
    {
        unset($this->onReadableEndCallbacks[$key]);
    }

    /**
     * Register callback for writable end event (write side closed)
     *
     * @param Closure $closure
     * @return string
     */
    public function onWritableEnd(Closure $closure): string
    {
        $this->onWritableEndCallbacks[$key = Format::int2string($this->index++)] = $closure;
        return $key;
    }

    /**
     * @param string $key
     * @return void
     */
    public function cancelOnWritableEnd(string $key): void
    {
        unset($this->onWritableEndCallbacks[$key]);
    }

    /**
     * @internal
     * Emit readable end event (EOF/half-close)
     */
    private function emitReadableEnd(): void
    {
        if (!$this->isReadOpen) {
            return; // Already emitted
        }

        $this->isReadOpen = false;
        $this->cancelReadable();

        foreach ($this->onReadableEndCallbacks as $callback) {
            try {
                call_user_func($callback, $this);
            } catch (Throwable $exception) {
                Output::error("Error in onReadableEnd callback: " . $exception->getMessage());
            }
        }
    }

    /**
     * @internal
     * Emit writable end event (write side closed)
     */
    private function emitWritableEnd(): void
    {
        if (!$this->isWriteOpen) {
            return; // Already emitted
        }

        $this->isWriteOpen = false;
        $this->cancelWriteable();

        foreach ($this->onWritableEndCallbacks as $callback) {
            try {
                call_user_func($callback, $this);
            } catch (Throwable $exception) {
                Output::error("Error in onWritableEnd callback: " . $exception->getMessage());
            }
        }
    }

    /**
     * @internal
     * Emit close event with reason
     */
    private function emitCloseEvent(ConnectionAbortReason $reason, string $initiator, Throwable|null $lastError = null): void
    {
        if ($this->isClosing) {
            return; // Already closing
        }

        $this->isClosing = true;
        $closeEvent = new CloseEvent($reason, $initiator, null, $lastError);

        foreach ($this->onCloseCallbacks as $callback) {
            try {
                call_user_func($callback, $closeEvent);
            } catch (Throwable $exception) {
                Output::error("Error in onClose callback: " . $exception->getMessage());
            }
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

        // Emit close event with local initiator
        $this->emitCloseEvent(ConnectionAbortReason::LOCAL_CLOSE, 'local');
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
                new TransportException('Stream has been closed')
            );
        });

        $resumed = false;
        if ($timeout > 0) {
            // If a timeout is set, the context will be canceled after the timeout
            async(function () use ($context, $timeout, &$resumed) {
                \Co\sleep($timeout);
                if (!$resumed) {
                    $this->close();
                    Coroutine::throw($context, new ConnectionTimeoutException('Stream write timeout'));
                }
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
            } catch (AbortConnection $e) {
                // Internal control-flow exception - close connection immediately
                $this->cancelReadable();
                $this->cancelWriteable();
                parent::close();

                // Extract reason from ConnectionException if available
                $reason = ($e instanceof ConnectionException)
                    ? $e->reason
                    : ConnectionAbortReason::RESET;
                $this->emitCloseEvent($reason, 'system', $e);
            } catch (Throwable $exception) {
                Output::error($exception->getMessage());
            }
        });
    }

    /**
     * Override parent read method to support half-close detection
     *
     * @param int $length
     * @return string
     * @throws ConnectionException
     */
    public function read(int $length): string
    {
        $content = @fread($this->stream, $length);
        if ($content === false) {
            // Fatal I/O error - throw internal exception for immediate termination
            throw new ConnectionException(ConnectionAbortReason::RESET, 'Unable to read from stream');
        }

        if ($content === '' && feof($this->stream)) {
            // EOF detected - handle based on half-close support
            if ($this->supportsHalfClose && !empty($this->onReadableEndCallbacks)) {
                // Half-close supported and user has registered onReadableEnd
                $this->emitReadableEnd();
                return $content; // Return empty string, don't throw
            } else {
                // No half-close support or no onReadableEnd handler - treat as fatal
                throw new ConnectionException(ConnectionAbortReason::PEER_CLOSED, 'Peer closed connection');
            }
        }

        return $content;
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

    /*** @var string */
    protected string $buffer = '';

    /*** @var bool */
    protected bool $clearBufferIsRunning = false;

    /*** @var Context[] */
    protected array $clearBufferWaiters = [];

    /**
     * 写入数据
     *
     * @param string $string
     *
     * @return int
     * @throws ConnectionException|WriteClosedException
     */
    public function write(string $string): int
    {
        // Check if write side is already closed
        if (!$this->isWriteOpen) {
            throw new WriteClosedException('Write side of connection is closed');
        }

        $this->buffer .= $string;

        try {
            if (!$this->clearBufferIsRunning) {
                $writeLength  = $this->writeToSocket($this->buffer);
                $this->buffer = substr($this->buffer, $writeLength);

                if ($this->buffer === '') {
                    return strlen($string);
                }

                $this->startClearBuffer();
            }

            // Only suspend if there's still buffered data to write
            if ($this->buffer !== '') {
                $this->clearBufferWaiters[] = getContext();
                Coroutine::suspend(end($this->clearBufferWaiters));
            }
            return strlen($string);
        } catch (AbortConnection $e) {
            // Re-throw internal control flow exception
            throw $e;
        } catch (Throwable $e) {
            $this->close();
            throw new TransportException('Write operation failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @internal
     * Handle low-level write with proper error classification
     */
    private function writeToSocket(string $data): int
    {
        $result = @parent::write($data);

        if ($result === false) {
            $error = error_get_last();
            $errorMsg = $error['message'] ?? 'Unknown write error';

            // Classify the error
            if (str_contains($errorMsg, 'Broken pipe') || str_contains($errorMsg, 'EPIPE')) {
                // Peer closed read side
                if ($this->supportsHalfClose && !empty($this->onWritableEndCallbacks)) {
                    $this->emitWritableEnd();
                    return 0; // Indicate no bytes written but don't throw
                } else {
                    throw new ConnectionException(ConnectionAbortReason::PEER_READ_CLOSED, 'Peer closed read side');
                }
            } elseif (str_contains($errorMsg, 'Connection reset') || str_contains($errorMsg, 'ECONNRESET')) {
                throw new ConnectionException(ConnectionAbortReason::RESET, 'Connection reset by peer');
            } else {
                throw new ConnectionException(ConnectionAbortReason::WRITE_FAILURE, 'Write failed: ' . $errorMsg);
            }
        }

        return $result;
    }

    /**
     * @return void
     */
    private function startClearBuffer(): void
    {
        $this->onWriteable(function () {
            try {
                $writeLength = $this->writeToSocket($this->buffer);

                if ($writeLength === 0 && !$this->isWriteOpen) {
                    // Write side closed during half-close
                    $this->clearBufferIsRunning = false;
                    $this->failAllWaiters();
                    return;
                }

                $this->buffer = substr($this->buffer, $writeLength);

                if ($this->buffer === '') {
                    $this->cancelWriteable();
                    $this->clearBufferIsRunning = false;
                    $this->resumeAllWaiters();
                }
            } catch (AbortConnection $e) {
                // Internal control flow exception - will be caught by onWriteable handler
                throw $e;
            } catch (Throwable $e) {
                $this->close();
                $this->failAllWaiters();
                throw new TransportException('Buffer clear failed: ' . $e->getMessage(), 0, $e);
            }
        });

        $this->clearBufferIsRunning = true;
    }

    /**
     * @return void
     */
    private function resumeAllWaiters(): void
    {
        $waiters                  = $this->clearBufferWaiters;
        $this->clearBufferWaiters = [];

        foreach ($waiters as $waiter) {
            \Ripple\Coroutine::resume($waiter);
        }
    }

    /**
     * @return void
     */
    private function failAllWaiters(): void
    {
        $waiters                  = $this->clearBufferWaiters;
        $this->clearBufferWaiters = [];

        foreach ($waiters as $waiter) {
            \Ripple\Coroutine::throw(
                $waiter,
                new TransportException('Unable to write to stream - connection closed')
            );
        }
    }
}
