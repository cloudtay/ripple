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

namespace Psc\Store\Net\Http\Server;

use Closure;
use P\IO;
use Psc\Core\Stream\SocketStream;
use Psc\Std\Stream\Exception\RuntimeException;
use Psc\Store\Net\Http\Server\Exception\FormatException;
use Psc\Store\Net\Http\Server\Upload\MultipartHandler;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;
use function call_user_func;
use function call_user_func_array;
use function count;
use function explode;
use function intval;
use function json_decode;
use function P\async;
use function P\await;
use function parse_str;
use function parse_url;
use function preg_match;
use function str_contains;
use function str_replace;
use function strlen;
use function strtok;
use function strtolower;
use function strtoupper;
use function substr;

/**
 * Http服务类
 */
class HttpServer
{
    /**
     * 请求处理器
     * @var Closure
     */
    public Closure       $onRequest;
    private SocketStream $server;

    /**
     * @param string     $address
     * @param mixed|null $context
     */
    public function __construct(string $address, mixed $context = null)
    {
        async(function () use (
            $address,
            $context
        ) {
            $addressExploded = explode('://', $address);
            if (count($addressExploded) !== 2) {
                throw new RuntimeException('Address format error');
            }

            $scheme             = $addressExploded[0];
            $tcpAddress         = $addressExploded[1];
            $tcpAddressExploded = explode(':', $tcpAddress);
            $host               = $tcpAddressExploded[0];
            $port               = $tcpAddressExploded[1] ?? match ($scheme) {
                'http' => 80,
                'https' => 443,
                default => throw new RuntimeException('Address format error')
            };

            /**
             * @var SocketStream $server
             */
            $this->server = match ($scheme) {
                'http' => await(IO::Socket()->streamSocketServer("tcp://{$host}:{$port}", $context)),
                'https' => await(IO::Socket()->streamSocketServerSSL("ssl://{$host}:{$port}", $context)),
                default => throw new RuntimeException('Address format error')
            };

            $this->server->setOption(SOL_SOCKET, SO_REUSEADDR, 1);
            $this->server->setOption(SOL_SOCKET, SO_REUSEPORT, 1);
            $this->server->setBlocking(false);
        });
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

            /**
             * Debug: 低水位 & 缓冲区
             */
            //$lowWaterMarkRecv = socket_get_option($clientSocket, SOL_SOCKET, SO_RCVLOWAT);
            //$lowWaterMarkSend = socket_get_option($clientSocket, SOL_SOCKET, SO_SNDLOWAT);
            //$recvBuffer       = socket_get_option($clientSocket, SOL_SOCKET, SO_RCVBUF);
            //$sendBuffer       = socket_get_option($clientSocket, SOL_SOCKET, SO_SNDBUF);
            //var_dump($lowWaterMarkRecv, $lowWaterMarkSend, $recvBuffer, $sendBuffer);

            /**
             * 优化缓冲区: 256kb标准速率帧
             */
            $client->setOption(SOL_SOCKET, SO_RCVBUF, 256000);
            $client->setOption(SOL_SOCKET, SO_SNDBUF, 256000);
            //$client->setOption(SOL_SOCKET, SO_KEEPALIVE, 1);

            /**
             * 设置发送低水位防止充盈内存
             * @deprecated 兼容未覆盖
             */
            //$client->setOption(SOL_SOCKET, SO_SNDLOWAT, 1024);

            /**
             * CPU亲密度
             * @deprecated 兼容未覆盖
             */
            //socket_set_option($clientSocket, SOL_SOCKET, SO_INCOMING_CPU, 1);
            $this->factory($client)->run();
        });
    }

    /**
     * @param SocketStream $stream
     * @return object
     */
    private function factory(SocketStream $stream): object
    {
        return new class ($stream, fn(Request $request, Response $response) => $this->onRequest($request, $response)) {
            private int                   $step;
            private array                 $query;
            private array                 $request;
            private array                 $attributes;
            private array                 $cookies;
            private array                 $files;
            private array                 $server;
            private string                $content;
            private string                $buffer;
            private MultipartHandler|null $requestUpload;
            private int                   $bodyLength;

            /**
             * @param SocketStream $stream
             * @param Closure      $onRequest
             */
            public function __construct(private readonly SocketStream $stream, private readonly Closure $onRequest)
            {
                $this->reset();
            }

            /**
             * @return void
             */
            public function reset(): void
            {
                $this->step          = 0;
                $this->query         = [];
                $this->request       = [];
                $this->attributes    = [];
                $this->cookies       = [];
                $this->files         = [];
                $this->server        = [];
                $this->content       = '';
                $this->buffer        = '';
                $this->requestUpload = null;
                $this->bodyLength    = 0;
            }

            /**
             * @return void
             */
            public function run(): void
            {
                $this->stream->onReadable(function (SocketStream $stream, Closure $cancel) {
                    $context = $stream->read(8192);
                    if ($context === '') {
                        $stream->close();
                        return;
                    }

                    $this->buffer .= $context;

                    if ($this->step === 0) {
                        if ($headerEnd = strpos($context, "\r\n\r\n")) {
                            /**
                             * 切割解析head与body部分
                             */
                            $this->step       = 1;
                            $header           = substr($context, 0, $headerEnd);
                            $this->content    = substr($context, $headerEnd + 4);
                            $this->bodyLength = strlen($this->content);
                            $baseContent      = strtok($header, "\r\n");

                            if (count($base = explode(' ', $baseContent)) !== 3) {
                                throw new RuntimeException('Request head is not match');
                            }

                            /**
                             * 初始化闭包参数
                             */
                            $url     = $base[1];
                            $version = $base[2];
                            $method  = $base[0];

                            $urlExploded = explode('?', $url);
                            $path        = parse_url($base[1], PHP_URL_PATH);

                            if (isset($urlExploded[1])) {
                                $queryArray = explode('&', $urlExploded[1]);
                                foreach ($queryArray as $item) {
                                    $item = explode('=', $item);
                                    if (count($item) === 2) {
                                        $this->query[$item[0]] = $item[1];
                                    }
                                }
                            }

                            $this->server['REQUEST_URI']     = $path;
                            $this->server['REQUEST_METHOD']  = $method;
                            $this->server['SERVER_PROTOCOL'] = $version;

                            /**
                             * 解析header
                             */
                            while ($line = strtok("\r\n")) {
                                $lineParam = explode(': ', $line, 2);
                                if (count($lineParam) >= 2) {
                                    $this->server['HTTP_' . str_replace('-', '_', strtoupper($lineParam[0]))] = $lineParam[1];
                                }
                            }

                            /**
                             * 解析文件
                             */
                            if ($method === 'GET') {
                                $this->bodyLength = 0;
                                $this->step       = 2;
                            }

                            if ($method === 'POST') {
                                if (!$contentType = $this->server['HTTP_CONTENT_TYPE'] ?? null) {
                                    throw new RuntimeException('Content-Type is not set');
                                }

                                if (!isset($this->server['HTTP_CONTENT_LENGTH'])) {
                                    throw new RuntimeException('Content-Length is not set');
                                }

                                if (str_contains($contentType, 'multipart/form-data')) {
                                    preg_match('/boundary=(.*)$/', $contentType, $matches);
                                    if (!isset($matches[1])) {
                                        throw new RuntimeException('boundary is not set');
                                    } else {
                                        $this->step          = 3;
                                        $this->requestUpload = new MultipartHandler($matches[1]);
                                        $stream->onClose(fn() => $this->requestUpload?->disconnect());
                                        $this->requestUpload->onFile = function (UploadedFile $file, string $name) {
                                            $this->files[$name][] = $file;
                                            if ($this->bodyLength === intval($this->server['HTTP_CONTENT_LENGTH'])) {
                                                $this->step = 2;
                                            }
                                            $this->requestUpload->done();
                                        };

                                        try {
                                            $this->requestUpload->push($this->content);
                                        } catch (Throwable $e) {
                                            $this->stream->close();
                                            return;
                                        }
                                        $this->content = '';
                                    }
                                }

                                if ($this->bodyLength === intval($this->server['HTTP_CONTENT_LENGTH'])) {
                                    $this->step = 2;
                                } elseif ($this->bodyLength > intval($this->server['HTTP_CONTENT_LENGTH'])) {
                                    throw new RuntimeException('Content-Length is not match');
                                }
                            }
                            $this->buffer = '';
                        }
                    }

                    /**
                     * 持续传输
                     */
                    if ($this->step === 1) {
                        $this->content    .= $context;
                        $this->bodyLength += strlen($context);
                        if ($this->bodyLength === intval($this->server['HTTP_CONTENT_LENGTH'])) {
                            $this->step = 2;
                        } elseif ($this->bodyLength > intval($this->server['HTTP_CONTENT_LENGTH'])) {
                            throw new RuntimeException('Content-Length is not match');
                        }
                    }

                    /**
                     * 文件传输
                     */
                    if ($this->step === 3) {
                        $this->bodyLength += strlen($this->buffer);
                        try {
                            $this->requestUpload->push($this->buffer);
                            $this->buffer = '';
                        } catch (Throwable $e) {
                            $this->stream->close();
                            return;
                        }
                    }

                    /**
                     * 请求解析完成
                     */
                    if ($this->step === 2) {
                        /**
                         * 解析cookie
                         */
                        if (isset($this->server['HTTP_COOKIE'])) {
                            $cookie = $this->server['HTTP_COOKIE'];
                            $cookie = explode('; ', $cookie);
                            foreach ($cookie as $item) {
                                $item                    = explode('=', $item);
                                $this->cookies[$item[0]] = $item[1];
                            }
                        }

                        /**
                         * 解析body
                         */
                        if ($this->server['REQUEST_METHOD'] === 'POST') {
                            if (str_contains($this->server['HTTP_CONTENT_TYPE'], 'application/json')) {
                                $this->request = json_decode($this->content, true);
                            } else {
                                parse_str($this->content, $this->request);
                            }
                        }

                        $symfonyRequest = new Request(
                            $this->query,
                            $this->request,
                            $this->attributes,
                            $this->cookies,
                            $this->files,
                            $this->server,
                            $this->content
                        );

                        $keepAlive = $symfonyRequest->headers->has('Connection')
                                     && strtolower($symfonyRequest->headers->get('Connection')) === 'keep-alive';

                        $symfonyResponse = new Response($this->stream, function () use ($keepAlive) {
                            $keepAlive ? $this->reset() : $this->stream->close();
                        });

                        try {
                            if ($keepAlive) {
                                $symfonyResponse->headers->set('Connection', 'keep-alive');
                            } else {
                                $cancel();
                            }

                            call_user_func($this->onRequest, $symfonyRequest, $symfonyResponse);
                        } catch (FormatException) {
                            /**
                             * 报文格式非法
                             */
                            $stream->write("HTTP/1.1 400 Bad Request\r\n\r\n");
                            $stream->close();
                        } catch (Throwable $e) {
                            /**
                             * 服务内部逻辑错误
                             */
                            $stream->write($e->getMessage());
                            $stream->close();
                        }
                    }
                });
            }
        };
    }

    /**
     * @param Request  $request
     * @param Response $response
     * @return void
     */
    private function onRequest(Request $request, Response $response): void
    {
        if (isset($this->onRequest)) {
            call_user_func_array($this->onRequest, [
                $request,
                $response
            ]);
        }
    }
}
