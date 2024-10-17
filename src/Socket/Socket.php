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

namespace Ripple\Socket;

use Closure;
use Ripple\Coroutine\Exception\Exception;
use Ripple\Coroutine\Promise;
use Ripple\LibraryAbstract;
use Ripple\Stream\Exception\ConnectionException;
use Throwable;

use function Co\cancel;
use function Co\delay;
use function Co\promise;
use function is_array;
use function str_replace;
use function stream_context_create;
use function stream_socket_client;
use function stream_socket_enable_crypto;
use function stream_socket_server;

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
class Socket extends LibraryAbstract
{
    /*** @var LibraryAbstract */
    protected static LibraryAbstract $instance;

    /**
     * @param string     $address
     * @param int        $timeout
     * @param mixed|null $context
     *
     * @return SocketStream
     * @throws \Ripple\Stream\Exception\ConnectionException
     */
    public function connectWithSSL(string $address, int $timeout = 0, mixed $context = null): SocketStream
    {
        $address      = str_replace('ssl://', 'tcp://', $address);
        $streamSocket = $this->connect($address, $timeout, $context);
        $this->enableSSL($streamSocket, $timeout);
        return $streamSocket;
    }

    /**
     * @param string     $address
     * @param int        $timeout
     * @param mixed|null $context
     *
     * @return SocketStream
     * @throws ConnectionException
     */
    public function connect(string $address, int $timeout = 0, mixed $context = null): SocketStream
    {
        try {
            return promise(static function (Closure $resolve, Closure $reject) use ($address, $timeout, $context) {
                $connection = stream_socket_client(
                    $address,
                    $_,
                    $_,
                    $timeout,
                    STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT,
                    $context
                );

                if (!$connection) {
                    $reject(new ConnectionException('Failed to connect to the server.', ConnectionException::CONNECTION_ERROR));
                    return;
                }

                $stream = new SocketStream($connection, $address);

                if ($timeout > 0) {
                    $timeoutEventId     = delay(static function () use ($stream, $reject) {
                        $stream->close();
                        $reject(new ConnectionException('Connection timeout.', ConnectionException::CONNECTION_TIMEOUT));
                    }, $timeout);
                    $timeoutEventCancel = fn () => cancel($timeoutEventId);
                } else {
                    $timeoutEventCancel = fn () => null;
                }

                $stream->onWritable(static function (SocketStream $stream, Closure $cancel) use ($resolve, $timeoutEventCancel) {
                    $cancel();
                    $resolve($stream);
                    $timeoutEventCancel();
                });
            })->await();
        } catch (Throwable $e) {
            throw new ConnectionException('Failed to connect to the server.', ConnectionException::CONNECTION_ERROR, $e);
        }
    }

    /**
     * @param SocketStream $stream
     * @param float        $timeout
     *
     * @return SocketStream
     * @throws ConnectionException
     */
    public function enableSSL(SocketStream $stream, float $timeout = 0): SocketStream
    {
        try {
            return promise(static function (Closure $resolve, Closure $reject, Promise $promise) use ($stream, $timeout) {
                if ($timeout > 0) {
                    $timeoutEventId = delay(static function () use ($reject) {
                        $reject(new ConnectionException('SSL handshake timeout.', ConnectionException::CONNECTION_TIMEOUT));
                    }, $timeout);
                    $promise->finally(static fn () => cancel($timeoutEventId));
                }

                $handshakeResult = stream_socket_enable_crypto(
                    $stream->stream,
                    true,
                    STREAM_CRYPTO_METHOD_SSLv23_CLIENT | STREAM_CRYPTO_METHOD_TLS_CLIENT
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
                    $stream->onReadable(static function (SocketStream $stream, Closure $cancel) use ($resolve, $reject) {
                        try {
                            $handshakeResult = stream_socket_enable_crypto(
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
        } catch (Throwable $e) {
            throw new ConnectionException('Failed to enable SSL.', ConnectionException::CONNECTION_CRYPTO, $e);
        }
    }

    /**
     * @param string     $address
     * @param mixed|null $context
     *
     * @return SocketStream|false
     */
    public function server(string $address, mixed $context = null): SocketStream|false
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

        return $server ? new SocketStream($server) : false;
    }
}
