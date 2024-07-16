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

namespace Psc\Store\IO;

use Closure;
use Psc\Core\Coroutine\Exception;
use Psc\Core\Coroutine\Promise;
use Psc\Core\StoreAbstract;
use Psc\Core\Stream\SocketStream;
use Throwable;
use function P\async;
use function P\await;
use function P\delay;
use function P\promise;
use function str_replace;
use function stream_socket_accept;
use function stream_socket_client;
use function stream_socket_enable_crypto;
use function stream_socket_server;

class Socket extends StoreAbstract
{
    /**
     * @var StoreAbstract
     */
    protected static StoreAbstract $instance;

    /**
     * @param string     $address
     * @param int        $timeout
     * @param mixed|null $context
     * @return Promise
     */
    public function streamSocketClientSSL(string $address, int $timeout = 0, mixed $context = null): Promise
    {
        return async(function (Closure $r, Closure $d) use ($address, $timeout, $context) {
            $address                   = str_replace('ssl://', 'tcp://', $address);
            $streamSocketClientPromise = $this->streamSocketClient($address, $timeout, $context);

            /**
             * @var SocketStream $streamSocket
             */
            $streamSocket = await($streamSocketClientPromise);
            $promise      = $this->streamEnableCrypto($streamSocket)->then($r)->except($d);

            if ($timeout > 0) {
                delay(function () use ($promise, $streamSocket, $d) {
                    if ($promise->getStatus() === Promise::PENDING) {
                        $streamSocket->close();
                        $d(new Exception('Connection timeout.'));
                    }
                }, $timeout);
            }
        });
    }

    /**
     * @param string     $address
     * @param int        $timeout
     * @param mixed|null $context
     * @return Promise<SocketStream>
     */
    public function streamSocketClient(string $address, int $timeout = 0, mixed $context = null): Promise
    {
        return promise(function (Closure $r, Closure $d) use ($address, $timeout, $context) {
            $connection = stream_socket_client(
                $address,
                $_,
                $_,
                $timeout,
                STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT,
                $context
            );

            if (!$connection) {
                $d(new Exception('Failed to connect to the server.'));
                return;
            }

            $stream = new SocketStream($connection);
            $stream->onWritable(function (SocketStream $stream, Closure $cancel) use ($r) {
                $cancel();
                $r($stream);
            });
        });
    }

    /**
     * @param SocketStream $stream
     * @return Promise
     */
    public function streamEnableCrypto(SocketStream $stream): Promise
    {
        return new Promise(function ($r, $d) use ($stream) {
            $handshakeResult = stream_socket_enable_crypto($stream->stream, true, STREAM_CRYPTO_METHOD_SSLv23_CLIENT);

            if ($handshakeResult === false) {
                $stream->close();
                $d(new Exception('Failed to enable crypto.'));
                return;
            }

            if ($handshakeResult === true) {
                $r($stream);
                return;
            }

            if ($handshakeResult === 0) {
                $stream->onReadable(function (SocketStream $stream, Closure $cancel) use ($r, $d) {
                    try {
                        $handshakeResult = stream_socket_enable_crypto($stream->stream, true, STREAM_CRYPTO_METHOD_SSLv23_CLIENT);
                    } catch (Throwable $exception) {
                        $stream->close();
                        $d($exception);
                        return;
                    }

                    if ($handshakeResult === false) {
                        $stream->close();
                        $d(new Exception('Failed to enable crypto.'));
                        return;
                    }

                    if ($handshakeResult === true) {
                        $cancel();
                        $r($stream);
                        return;
                    }
                });
            }
        });
    }

    /**
     * @param string     $address
     * @param mixed|null $context
     * @return Promise
     */
    public function streamSocketServerSSL(string $address, mixed $context = null): Promise
    {
        return async(function (Closure $r, Closure $d) use ($address, $context) {
            $streamSocketServerPromise = $this->streamSocketServer($address, $context);

            /**
             * @var SocketStream $server
             */
            $server = await($streamSocketServerPromise);
            $server->onReadable(function (SocketStream $server, Closure $cancel) use ($r, $d) {
                $connection = stream_socket_accept($server->stream, 0);
                if (!$connection) {
                    $d(new Exception('Failed to accept connection.'));
                    return;
                }

                $stream = new SocketStream($connection);
                $this->streamEnableCrypto($stream)->then($r)->except($d);
                $cancel();
            });
        });
    }

    /**
     * @param string     $address
     * @param mixed|null $context
     * @return Promise
     */
    public function streamSocketServer(string $address, mixed $context = null): Promise
    {
        return promise(function (Closure $r, Closure $d) use ($address, $context) {
            $server = stream_socket_server($address, $_errCode, $_errMsg, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
            if (!$server) {
                $d(new Exception($_errMsg, $_errCode));
                return;
            }

            $r(new SocketStream($server));
            return;
        });
    }

    /**
     * @param SocketStream $server
     * @return Promise
     */
    public function streamSocketAccept(SocketStream $server): Promise
    {
        return new Promise(function ($r, $d) use ($server) {
            $server->onReadable(function (SocketStream $server, Closure $cancel) use ($r, $d) {
                $connection = stream_socket_accept($server->stream, 0);
                if (!$connection) {
                    $d(new Exception('Failed to accept connection.'));
                    return;
                }

                $clientSocketStream = new SocketStream($connection);
                $r($clientSocketStream);
                $cancel();
            });
        });
    }
}
