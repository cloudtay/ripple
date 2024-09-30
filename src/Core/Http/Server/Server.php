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

namespace Psc\Core\Http\Server;

use Closure;
use Co\IO;
use InvalidArgumentException;
use Psc\Core\Http\Server\Exception\FormatException;
use Psc\Core\Socket\SocketStream;
use Psc\Core\Stream\Exception\ConnectionException;
use Psc\Utils\Output;
use Throwable;

use function call_user_func_array;
use function parse_url;
use function str_contains;
use function strtolower;

use const SO_KEEPALIVE;
use const SOL_SOCKET;

/**
 * Http service class
 */
class Server
{
    /**
     * request handler
     *
     * @var Closure
     */
    public Closure $onRequest;

    /*** @var SocketStream */
    private SocketStream $server;

    /**
     * @param string     $address
     * @param mixed|null $context
     *
     * @throws InvalidArgumentException|\Psc\Core\Stream\Exception\ConnectionException
     */
    public function __construct(string $address, mixed $context = null)
    {
        $addressInfo = parse_url($address);

        if (!$scheme = $addressInfo['scheme'] ?? null) {
            throw new InvalidArgumentException('Address format error');
        }

        if (!$host = $addressInfo['host']) {
            throw new InvalidArgumentException('Address format error');
        }

        $port = $addressInfo['port'] ?? match ($scheme) {
            'http'  => 80,
            'https' => 443,
            default => throw new InvalidArgumentException('Address format error')
        };

        $server = match ($scheme) {
            'http', 'https' => IO::Socket()->server("tcp://{$host}:{$port}", $context),
            default         => throw new InvalidArgumentException('Address format error')
        };

        if ($server === false) {
            throw new ConnectionException('Failed to create server', ConnectionException::CONNECTION_ERROR);
        }

        $this->server = $server;
        $this->server->setOption(SOL_SOCKET, SO_KEEPALIVE, 1);
        $this->server->setBlocking(false);
    }

    /**
     * @return void
     */
    public function listen(): void
    {
        $this->server->onReadable(function (SocketStream $stream) {
            try {
                $client = $stream->accept();
            } catch (Throwable) {
                return;
            }

            $client->setBlocking(false);

            /*** Debug: Low Water Level & Buffer*/
            //            $lowWaterMarkRecv = socket_get_option($clientSocket, SOL_SOCKET, SO_RCVLOWAT);
            //            $lowWaterMarkSend = socket_get_option($clientSocket, SOL_SOCKET, SO_SNDLOWAT);
            //            $recvBuffer       = socket_get_option($clientSocket, SOL_SOCKET, SO_RCVBUF);
            //            $sendBuffer       = socket_get_option($clientSocket, SOL_SOCKET, SO_SNDBUF);
            //            var_dump($lowWaterMarkRecv, $lowWaterMarkSend, $recvBuffer, $sendBuffer);

            /*** Optimized buffer: 256kb standard rate frame*/
            //            $client->setOption(SOL_SOCKET, SO_RCVBUF, 256000);
            //            $client->setOption(SOL_SOCKET, SO_SNDBUF, 256000);
            //            $client->setOption(SOL_TCP, TCP_NODELAY, 1);

            /*** Set sending low water level to prevent filling memory @deprecated compatible without coverage */
            //            $client->setOption(SOL_SOCKET, SO_SNDLOWAT, 1024);

            /*** CPU intimacy @deprecated compatible not covered */
            //            $stream->setOption(SOL_SOCKET, SO_INCOMING_CPU, 1);
            $this->listenSocket($client);
        });
    }

    /**
     * @param Closure $onRequest
     *
     * @return void
     */
    public function onRequest(Closure $onRequest): void
    {
        $this->onRequest = $onRequest;
    }

    /**
     * @param SocketStream $stream
     *
     * @return void
     */
    private function listenSocket(SocketStream $stream): void
    {
        $connection = new Connection($stream);
        $connection->listen(function (array $requestInfo) use ($stream) {
            $request = new Request(
                $stream,
                $requestInfo['query'],
                $requestInfo['request'],
                $requestInfo['cookies'],
                $requestInfo['files'],
                $requestInfo['server'],
                $requestInfo['content']
            );

            $symfonyResponse = $request->getResponse();
            $symfonyResponse->headers->set('Server', 'ripple');

            $keepAlive = false;
            if ($headerConnection = $requestInfo['server']['HTTP_CONNECTION'] ?? null) {
                if (str_contains(strtolower($headerConnection), 'keep-alive')) {
                    $keepAlive = true;
                }
            }

            if ($keepAlive) {
                $symfonyResponse->headers->set('Connection', 'keep-alive');
            }

            try {
                if (isset($this->onRequest)) {
                    call_user_func_array($this->onRequest, [$request]);
                }
            } catch (ConnectionException) {
                $stream->close();
            } catch (FormatException) {
                /**** The message format is illegal*/
                $symfonyResponse->setStatusCode(400)->respond();
            } catch (Throwable $e) {
                $symfonyResponse->setStatusCode(500)->setBody($e->getMessage())->respond();

                Output::exception($e);
            }
        });
    }
}
