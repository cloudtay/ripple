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
use Psc\Core\Http\Server\Exception\FormatException;
use Psc\Core\Socket\SocketStream;
use Psc\Core\Stream\Exception\ConnectionException;
use Psc\Core\Stream\Exception\RuntimeException;
use Psc\Kernel;
use Throwable;

use function call_user_func_array;
use function count;
use function explode;
use function str_contains;
use function strlen;
use function strtolower;

use const SO_KEEPALIVE;
use const SO_REUSEADDR;
use const SO_REUSEPORT;
use const SOL_SOCKET;

/**
 * Http服务类
 */
class Server
{
    /**
     * 请求处理器
     *
     * @var Closure
     */
    public Closure       $onRequest;
    private SocketStream $server;

    /**
     * @param string     $address
     * @param mixed|null $context
     *
     * @throws Throwable
     */
    public function __construct(string $address, mixed $context = null)
    {
        $addressExploded = explode('://', $address);

        if (count($addressExploded) !== 2) {
            throw new RuntimeException('Address format error');
        }

        $scheme             = $addressExploded[0];
        $tcpAddress         = $addressExploded[1];
        $tcpAddressExploded = explode(':', $tcpAddress);
        $host               = $tcpAddressExploded[0];
        $port               = $tcpAddressExploded[1] ?? match ($scheme) {
            'http'  => 80,
            'https' => 443,
            default => throw new RuntimeException('Address format error')
        };

        /**
         * @var SocketStream $server
         */
        $this->server = match ($scheme) {
            'http', 'https' => IO::Socket()->streamSocketServer("tcp://{$host}:{$port}", $context),
            default         => throw new RuntimeException('Address format error')
        };

        $this->server->setOption(SOL_SOCKET, SO_REUSEADDR, 1);

        /**
         * @compatible:Windows
         */
        if (Kernel::getInstance()->supportProcessControl()) {
            $this->server->setOption(SOL_SOCKET, SO_REUSEPORT, 1);
        }

        $this->server->setOption(SOL_SOCKET, SO_KEEPALIVE, 1);
        $this->server->setBlocking(false);
        $this->setRequestFactory(new RequestFactory());
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

            /*** Debug: 低水位 & 缓冲区*/
            //            $lowWaterMarkRecv = socket_get_option($clientSocket, SOL_SOCKET, SO_RCVLOWAT);
            //            $lowWaterMarkSend = socket_get_option($clientSocket, SOL_SOCKET, SO_SNDLOWAT);
            //            $recvBuffer       = socket_get_option($clientSocket, SOL_SOCKET, SO_RCVBUF);
            //            $sendBuffer       = socket_get_option($clientSocket, SOL_SOCKET, SO_SNDBUF);
            //            var_dump($lowWaterMarkRecv, $lowWaterMarkSend, $recvBuffer, $sendBuffer);

            /*** 优化缓冲区: 256kb标准速率帧*/
            //            $client->setOption(SOL_SOCKET, SO_RCVBUF, 256000);
            //            $client->setOption(SOL_SOCKET, SO_SNDBUF, 256000);
            //            $client->setOption(SOL_TCP, TCP_NODELAY, 1);

            /*** 设置发送低水位防止充盈内存 @deprecated 兼容未覆盖 */
            //$client->setOption(SOL_SOCKET, SO_SNDLOWAT, 1024);

            /*** CPU亲密度 @deprecated 兼容未覆盖 */
            //socket_set_option($clientSocket, SOL_SOCKET, SO_INCOMING_CPU, 1);
            $this->listenSocket($client);
        });
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
            $symfonyRequest = $this->buildRequest(
                $requestInfo['query'],
                $requestInfo['request'],
                $requestInfo['attributes'],
                $requestInfo['cookies'],
                $requestInfo['files'],
                $requestInfo['server'],
                $requestInfo['content']
            );

            $keepAlive = false;
            if ($headerConnection = $requestInfo['header']['Connection'] ?? null) {
                if (str_contains(strtolower($headerConnection), 'keep-alive')) {
                    $keepAlive = true;
                }
            }

            $symfonyResponse = new Response($stream);
            $symfonyResponse->headers->set('Server', 'PServer');
            if ($keepAlive) {
                $symfonyResponse->headers->set('Connection', 'keep-alive');
            }

            try {
                if (isset($this->onRequest)) {
                    call_user_func_array($this->onRequest, [$symfonyRequest, $symfonyResponse]);
                }
            } catch (FormatException) {
                /**
                 * 报文格式非法
                 */
                try {
                    $stream->write("HTTP/1.1 400 Bad Request\r\n\r\n");
                } catch (Throwable) {
                }
                $stream->close();
            } catch (ConnectionException) {
                $stream->close();
            } catch (Throwable $e) {
                /**
                 * 服务内部逻辑错误
                 */
                $contentLength = strlen($message = $e->getMessage());
                try {
                    $stream->write(
                        "HTTP/1.1 500 Internal Server Error\r\n" .
                        "Content-Type: text/plain\r\n" .
                        "Content-Length: {$contentLength}\r\n" .
                        "\r\n" .
                        $message
                    );
                } catch (Throwable) {
                }
                $stream->close();
            }
        });
    }

    /*** @var \Psc\Core\Http\Server\RequestFactoryInterface */
    private RequestFactoryInterface $requestFactory;

    /**
     * @param array $query
     * @param array $request
     * @param array $attributes
     * @param array $cookies
     * @param array $files
     * @param array $server
     * @param mixed $content
     *
     * @return mixed
     */
    private function buildRequest(array $query, array $request, array $attributes, array $cookies, array $files, array $server, mixed $content): mixed
    {
        return $this->requestFactory->__invoke(
            $query,
            $request,
            $attributes,
            $cookies,
            $files,
            $server,
            $content
        );
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
     * @param \Psc\Core\Http\Server\RequestFactoryInterface $factory
     *
     * @return void
     */
    public function setRequestFactory(RequestFactoryInterface $factory): void
    {
        $this->requestFactory = $factory;
    }
}
