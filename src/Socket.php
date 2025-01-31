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
use Ripple\Coroutine\Exception\Exception;
use Ripple\File\File;
use Ripple\Stream\Exception\ConnectionException;
use RuntimeException;
use Throwable;

use function Co\cancel;
use function Co\delay;
use function Co\promise;
use function explode;
use function file_exists;
use function intval;
use function is_array;
use function socket_get_option;
use function socket_import_stream;
use function socket_last_error;
use function socket_recv;
use function socket_set_option;
use function socket_strerror;
use function str_replace;
use function stream_context_create;
use function stream_socket_accept;
use function stream_socket_client;
use function stream_socket_enable_crypto;
use function stream_socket_get_name;
use function stream_socket_server;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const SO_SNDLOWAT;
use const SOL_SOCKET;
use const STREAM_CLIENT_ASYNC_CONNECT;
use const STREAM_CLIENT_CONNECT;
use const STREAM_CRYPTO_METHOD_SSLv23_CLIENT;
use const STREAM_CRYPTO_METHOD_TLS_CLIENT;
use const STREAM_SERVER_BIND;
use const STREAM_SERVER_LISTEN;

/**
 * @Author cclilshy
 * @Date   2024/8/16 09:36
 */
class Socket extends Stream
{
    public \Socket $socket;

    /*** @var bool */
    protected bool $blocking = false;

    /*** @var static|null */
    protected Stream|null $storageCacheWrite = null;

    /*** @var static|null */
    protected Stream|null $storageCacheRead = null;

    /*** @var string|null */
    protected string|null $address;

    /*** @var string|null */
    protected string|null $host;

    /*** @var int|null */
    protected int|null $port;

    /*** @var Promise */
    protected Promise $writePromise;

    /**
     * @param mixed       $resource
     * @param string|null $peerName
     */
    public function __construct(mixed $resource, string|null $peerName = null)
    {
        parent::__construct($resource);

        if (!$socket = socket_import_stream($this->stream)) {
            throw new RuntimeException('Failed to import stream');
        }

        $this->socket = $socket;

        if (!$peerName) {
            $peerName = stream_socket_get_name($this->stream, true);
        }

        if ($peerName) {
            $this->address = $peerName;
        } else {
            $this->address = null;
        }

        if ($this->address) {
            $exploded   = explode(':', $this->address);
            $this->host = $exploded[0];
            $this->port = intval($exploded[1] ?? 0);
        }
    }

    /**
     * @param string     $address
     * @param int|float $timeout
     * @param mixed|null $context
     *
     * @return Socket
     * @throws \Ripple\Stream\Exception\ConnectionException
     */
    public static function connectWithSSL(string $address, int|float $timeout = 0, mixed $context = null): Socket
    {
        $address      = str_replace('ssl://', 'tcp://', $address);
        $Socket = Socket::connect($address, $timeout, $context);
        $Socket->enableSSL();
        return $Socket;
    }

    /**
     * @param string     $address
     * @param int|float $timeout
     * @param mixed|null $context
     *
     * @return Socket
     * @throws ConnectionException
     */
    public static function connect(string $address, int|float $timeout = 0, mixed $context = null): Socket
    {
        try {
            $connection = @stream_socket_client(
                $address,
                $_,
                $_,
                $timeout,
                STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT,
                $context
            );

            if (!$connection) {
                throw (new ConnectionException('Failed to connect to the server.', ConnectionException::CONNECTION_ERROR));
            }

            $stream = new static($connection, $address);
            $stream->waitForWriteable($timeout);
            $stream->cancelWriteable();
            return $stream;
        } catch (Throwable $exception) {
            throw new ConnectionException('Failed to connect to the server.', ConnectionException::CONNECTION_ERROR, $exception);
        }
    }

    /**
     * @param int|float $timeout
     * @param int|null  $cryptoMethod
     *
     * @return Socket
     * @throws \Ripple\Stream\Exception\ConnectionException
     */
    public function enableSSL(
        int|float $timeout = 0,
        int|null  $cryptoMethod = null,
    ): Socket {
        if (!$cryptoMethod) {
            $cryptoMethod = STREAM_CRYPTO_METHOD_SSLv23_CLIENT | STREAM_CRYPTO_METHOD_TLS_CLIENT;
        }
        try {
            return promise(function (Closure $resolve, Closure $reject, Promise $promise) use ($cryptoMethod, $timeout) {
                $stream = $this;
                if ($timeout > 0) {
                    $timeoutEventID = delay(static function () use ($reject) {
                        $reject(new ConnectionException('SSL handshake timeout.', ConnectionException::CONNECTION_TIMEOUT));
                    }, $timeout);
                    $promise->finally(static fn () => cancel($timeoutEventID));
                }

                $handshakeResult = @stream_socket_enable_crypto(
                    $stream->stream,
                    true,
                    $cryptoMethod
                );

                if ($handshakeResult === false) {
                    $stream->close();
                    $reject(new ConnectionException('Failed to enable crypto.', ConnectionException::CONNECTION_CRYPTO));
                    return;
                }

                if ($handshakeResult === true) {
                    $resolve($stream);
                    return;
                }

                if ($handshakeResult === 0) {
                    $stream->onReadable(static function (Socket $stream, Closure $cancel) use ($resolve, $reject) {
                        try {
                            $handshakeResult = @stream_socket_enable_crypto(
                                $stream->stream,
                                true,
                                STREAM_CRYPTO_METHOD_SSLv23_CLIENT | STREAM_CRYPTO_METHOD_TLS_CLIENT
                            );
                        } catch (Throwable $exception) {
                            $stream->close();
                            $reject($exception);
                            return;
                        }

                        if ($handshakeResult === false) {
                            $stream->close();
                            $reject(new Exception('Failed to enable crypto.'));
                            return;
                        }

                        if ($handshakeResult === true) {
                            $cancel();
                            $resolve($stream);
                            return;
                        }
                    });
                }
            })->await();
        } catch (Throwable $exception) {
            throw new ConnectionException('Failed to enable SSL.', ConnectionException::CONNECTION_CRYPTO, $exception);
        }
    }

    /**
     * @param string     $address
     * @param mixed|null $context
     *
     * @return static|false
     */
    public static function server(string $address, mixed $context = null): Socket|false
    {
        if (is_array($context)) {
            $context = stream_context_create($context);
        }

        $server = stream_socket_server(
            $address,
            $_errCode,
            $_errMsg,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
        );

        return $server ? new static($server) : false;
    }

    /**
     * @param int|float $timeout
     *
     * @return static|false
     */
    public function accept(int|float $timeout = 0): Socket|false
    {
        $socket = @stream_socket_accept($this->stream, $timeout, $peerName);
        if ($socket === false) {
            return false;
        }
        return new static($socket, $peerName);
    }

    /**
     * @param int   $level
     * @param int   $option
     * @param mixed $value
     *
     * @return void
     */
    public function setOption(int $level, int $option, mixed $value): void
    {
        if (!socket_set_option($this->socket, $level, $option, $value)) {
            throw new RuntimeException('Failed to set socket option: ' . socket_strerror(socket_last_error($this->socket)));
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/9/2 20:41
     *
     * @param int      $length
     * @param mixed    $target
     * @param int|null $flags
     *
     * @return int
     * @throws ConnectionException
     */
    public function receive(int $length, mixed &$target, int|null $flags = 0): int
    {
        $realLength = socket_recv($this->socket, $target, $length, $flags);
        if ($realLength === false) {
            $this->close();
            throw new ConnectionException('Unable to read from stream', ConnectionException::CONNECTION_READ_FAIL);
        }
        return $realLength;
    }

    /**
     * @param string $string
     *
     * @return int
     * @throws ConnectionException
     */
    public function write(string $string): int
    {
        try {
            return $this->writeInternal($string);
        } catch (Throwable $exception) {
            $this->close();
            throw new ConnectionException($exception->getMessage(), ConnectionException::CONNECTION_WRITE_FAIL);
        }
    }

    /**
     * @param string $string
     * @param bool   $wait
     *
     * @return int
     * @throws \Ripple\Stream\Exception\ConnectionException
     */
    public function writeInternal(string $string, bool $wait = true): int
    {
        $writeLength = 0;
        if (!$this->blocking) {
            $length       = parent::write($string);
            $string2cache = substr($string, $length);
            if ($string2cache === '') {
                return $length;
            }

            $writeLength += $length;

            $this->blocking = true;
            $tempFilePath   = sys_get_temp_dir() . '/' . uniqid('buf_');

            $this->storageCacheWrite = File::open($tempFilePath, 'w+');
            $this->storageCacheWrite->setBlocking(true);

            $this->storageCacheRead = File::open($tempFilePath, 'r+');
            $this->storageCacheRead->setBlocking(false);

            $eventID = $this->onClose(function () use ($tempFilePath) {
                $this->cleanupTempFiles($tempFilePath);
            });

            $this->onWriteable(function (Socket $_, Closure $cancel) use ($tempFilePath, $eventID) {
                if ($buffer = $this->storageCacheRead->read($this->getOption(SOL_SOCKET, SO_SNDLOWAT))) {
                    try {
                        parent::write($buffer);
                    } catch (ConnectionException $exception) {
                        $this->blocking = false;
                        $this->cleanupTempFiles($tempFilePath);
                        $cancel();
                        $this->cancelOnClose($eventID);

                        if (isset($this->writePromise)) {
                            $this->writePromise->reject($exception);

                            unset($this->writePromise);
                        }
                        return;
                    }
                }

                if ($this->storageCacheRead->eof()) {
                    $this->blocking = false;
                    $this->cleanupTempFiles($tempFilePath);
                    $cancel();
                    $this->cancelOnClose($eventID);

                    if (isset($this->writePromise)) {
                        $this->writePromise->resolve(0);

                        unset($this->writePromise);
                    }
                }
            });
        } else {
            $string2cache = $string;
        }

        $writeLength += $this->storageCacheWrite->write($string2cache);

        /*** @var Promise $writePromise */
        if ($wait) {
            if (!isset($this->writePromise)) {
                $this->writePromise = promise(static function () {
                });
            }

            try {
                $this->writePromise->await();
                return strlen($string);
            } catch (Throwable $exception) {
                $this->close();
                throw new ConnectionException($exception->getMessage(), ConnectionException::CONNECTION_WRITE_FAIL);
            }
        }

        return $writeLength;
    }

    /**
     * Clean up temp files and close file handles.
     *
     * @param string $tempFilePath
     */
    protected function cleanupTempFiles(string $tempFilePath): void
    {
        $this->storageCacheWrite->close();
        $this->storageCacheRead->close();
        if (file_exists($tempFilePath)) {
            unlink($tempFilePath);
        }
        $this->storageCacheWrite = null;
        $this->storageCacheRead  = null;
    }

    /**
     * @param int $level
     * @param int $option
     *
     * @return array|int
     */
    public function getOption(int $level, int $option): array|int
    {
        $option = socket_get_option($this->socket, $level, $option);
        if ($option === false) {
            throw new RuntimeException('Failed to get socket option: ' . socket_strerror(socket_last_error($this->socket)));
        }
        return $option;
    }

    /**
     * @return string
     */
    public function getAddress(): string
    {
        return $this->address;
    }

    /**
     * @return string
     */
    public function getHost(): string
    {
        return $this->host;
    }

    /**
     * @return int
     */
    public function getPort(): int
    {
        return $this->port;
    }
}
