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
use Psc\Core\Coroutine\Promise;
use Psc\Core\Stream\Stream;
use Psc\Std\Stream\Exception\RuntimeException;
use Psc\Store\Net\Exception\ConnectionException;
use Psc\Store\Net\Http\Server\Exception\FormatException;
use Psc\Store\Net\Http\Server\Upload\MultipartHandler;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;
use function P\async;
use function P\await;

/**
 * Http服务类
 */
class HttpServer
{
    /**
     * 请求处理器
     * @var Closure
     */
    public Closure $requestHandler;

    /**
     * @param string     $address
     * @param mixed|null $context
     */
    public function __construct(string $address, mixed $context = null)
    {
        async(function ()
        use (
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
             * @var Stream $server
             */
            $server = match ($scheme) {
                'http' => await(IO::Socket()->streamSocketServer("tcp://{$host}:{$port}", $context)),
                'https' => await(IO::Socket()->streamSocketServerSSL("ssl://{$host}:{$port}", $context)),
                default => throw new RuntimeException('Address format error')
            };
            $server->setBlocking(false);

            //设置复用
            $socket = socket_import_stream($server->stream);
            socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
            socket_set_option($socket, SOL_SOCKET, SO_REUSEPORT, 1);

            while (1) {
                $client = await(IO::Socket()->streamSocketAccept($server));
                $client->setBlocking(false);
                $this->listenClient($client);
            }
        });
    }

    /**
     * @param Stream $stream
     * @return void
     */
    private function listenClient(Stream $stream): void
    {
        async(function () use ($stream) {
            while (1) {
                try {
                    /**
                     * @var Request $request
                     */
                    $request = await($this->factory($stream));
                    $this->onRequest(
                        $request,
                        $stream
                    );
                    //TODO: 是否为 keep-alive

                    //TODO: 畅通无助,继续监听
                } catch (ConnectionException) {
                    /**
                     * 客户端关闭连接
                     */
                    $stream->close();
                    break;
                } catch (FormatException) {
                    /**
                     * 报文格式非法
                     */
                    $stream->write("HTTP/1.1 400 Bad Request\r\n\r\n");
                    $stream->close();
                    break;
                } catch (Throwable $e) {
                    /**
                     * 服务内部逻辑错误
                     */
                    $stream->write($e->getMessage());
                    $stream->close();
                    break;
                }
            }
        });
    }

    /**
     * @param Request $request
     * @param Stream  $stream
     * @return void
     */
    private function onRequest(Request $request, Stream $stream): void
    {
        if (isset($this->requestHandler)) {
            $response = new Response($stream);
            call_user_func_array($this->requestHandler, [
                $request,
                $response,
                $stream
            ]);
        }
    }

    /**
     * @param Stream $stream
     * @return Promise
     */
    private function factory(Stream $stream): Promise
    {
        return async(function (Closure $r, Closure $d) use ($stream) {
            /**
             * @param array                $query      The GET parameters
             * @param array                $request    The POST parameters
             * @param array                $attributes The request attributes (parameters parsed from the PATH_INFO, ...)
             * @param array                $cookies    The COOKIE parameters
             * @param array                $files      The FILES parameters
             * @param array                $server     The SERVER parameters
             * @param string|resource|null $content    The raw body data
             */

            /**
             * public function __construct(array $query = [], array $request = [], array $attributes = [], array $cookies = [], array $files = [], array $server = [], $content = null);
             */
            $query         = [];
            $request       = [];
            $attributes    = [];
            $cookies       = [];
            $files         = [];
            $server        = [];
            $content       = '';
            $buffer        = '';
            $requestUpload = null;

            /**
             * 0: 解析请求头
             * 1: 解析请求体
             * 2: 请求解析完成
             * 3: 文件传输
             */

            $step       = 0;
            $bodyLength = 0;

            $stream->onReadable(function (Stream $stream, Closure $cancel) use (
                &$query,
                &$request,
                &$attributes,
                &$cookies,
                &$files,
                &$server,
                &$content,
                &$step,
                &$buffer,
                &$bodyLength,
                &$requestUpload,

                $r,
                $d,
            ) {
                $context = $stream->read(8192);
                if ($context === '') {
                    $d(new RuntimeException('Client close connection'));
                    return;
                }

                $buffer .= $context;
                if ($step === 0) {
                    if ($headerEnd = strpos($context, "\r\n\r\n")) {
                        /**
                         * 切割解析head与body部分
                         */
                        $step        = 1;
                        $header      = substr($context, 0, $headerEnd);
                        $content     = substr($context, $headerEnd + 4);
                        $bodyLength  = strlen($content);
                        $baseContent = strtok($header, "\r\n");

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
                                    $query[$item[0]] = $item[1];
                                }
                            }
                        }

                        $server['REQUEST_URI']     = $path;
                        $server['REQUEST_METHOD']  = $method;
                        $server['SERVER_PROTOCOL'] = $version;

                        /**
                         * 解析header
                         */
                        while ($line = strtok("\r\n")) {
                            $lineParam = explode(': ', $line, 2);
                            if (count($lineParam) >= 2) {
                                $server['HTTP_' . str_replace('-', '_', strtoupper($lineParam[0]))] = $lineParam[1];
                            }
                        }

                        /**
                         * 解析文件
                         */
                        if ($method === 'GET') {
                            $bodyLength = 0;
                            $step       = 2;
                        }

                        if ($method === 'POST') {
                            if (!$contentType = $server['HTTP_CONTENT_TYPE'] ?? null) {
                                throw new RuntimeException('Content-Type is not set');
                            }

                            if (!isset($server['HTTP_CONTENT_LENGTH'])) {
                                throw new RuntimeException('Content-Length is not set');
                            }

                            if (str_contains($contentType, 'multipart/form-data')) {
                                preg_match('/boundary=(.*)$/', $contentType, $matches);
                                if (!isset($matches[1])) {
                                    throw new RuntimeException('boundary is not set');
                                } else {
                                    $step          = 3;
                                    $requestUpload = new MultipartHandler($matches[1]);
                                    $stream->onClose(fn() => $requestUpload->disconnect());
                                    $requestUpload->onFile = function (UploadedFile $file, string $name) use (
                                        &$files,
                                        &$bodyLength,
                                        &$server,
                                        &$step,
                                        $requestUpload
                                    ) {
                                        $files[$name][] = $file;
                                        if ($bodyLength === intval($server['HTTP_CONTENT_LENGTH'])) {
                                            $step = 2;
                                        }
                                        $requestUpload->done();
                                    };

                                    try {
                                        $requestUpload->push($content);
                                    } catch (Throwable $e) {
                                        $d($e);
                                    }
                                    $content = '';
                                }
                            }

                            if ($bodyLength === intval($server['HTTP_CONTENT_LENGTH'])) {
                                $step = 2;
                            } elseif ($bodyLength > intval($server['HTTP_CONTENT_LENGTH'])) {
                                throw new RuntimeException('Content-Length is not match');
                            }

                        }
                        $buffer = '';
                    }
                }

                /**
                 * 持续传输
                 */
                if ($step === 1) {
                    $content    .= $context;
                    $bodyLength += strlen($context);
                    if ($bodyLength === intval($server['HTTP_CONTENT_LENGTH'])) {
                        $step = 2;
                    } elseif ($bodyLength > intval($server['HTTP_CONTENT_LENGTH'])) {
                        throw new RuntimeException('Content-Length is not match');
                    }
                }

                /**
                 * 文件传输
                 */
                if ($step === 3) {
                    $bodyLength += strlen($buffer);
                    try {
                        $requestUpload->push($buffer);
                        $buffer = '';
                    } catch (Throwable $e) {
                        $d($e);
                    }
                }

                /**
                 * 请求解析完成
                 */
                if ($step === 2) {
                    $cancel();

                    /**
                     * 解析cookie
                     */
                    if (isset($server['HTTP_COOKIE'])) {
                        $cookie = $server['HTTP_COOKIE'];
                        $cookie = explode('; ', $cookie);
                        foreach ($cookie as $item) {
                            $item              = explode('=', $item);
                            $cookies[$item[0]] = $item[1];
                        }
                    }

                    /**
                     * 解析body
                     */
                    if ($server['REQUEST_METHOD'] === 'POST') {
                        if (str_contains($server['HTTP_CONTENT_TYPE'], 'application/json')) {
                            $request = json_decode($content, true);
                        } else {
                            $request = [];
                            parse_str($content, $request);
                        }
                    }

                    $requestObject = new Request(
                        $query,
                        $request,
                        $attributes,
                        $cookies,
                        $files,
                        $server,
                        $content
                    );

                    $r($requestObject);
                }
            });
        });
    }
}
