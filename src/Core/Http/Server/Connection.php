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

use Psc\Core\Http\Server\Upload\MultipartHandler;
use Psc\Core\Stream\SocketStream;
use Psc\Std\Stream\Exception\RuntimeException;

use function array_merge;
use function count;
use function explode;
use function in_array;
use function intval;
use function is_array;
use function is_string;
use function json_decode;
use function parse_str;
use function parse_url;
use function preg_match;
use function rawurldecode;
use function str_contains;
use function str_replace;
use function strlen;
use function strpos;
use function strtok;
use function strtoupper;
use function substr;

use const PHP_URL_PATH;

class Connection
{
    /*** @var int */
    private int                   $step;

    /*** @var array */
    private array                 $query;

    /*** @var array */
    private array                 $request;

    /*** @var array */
    private array                 $attributes;

    /*** @var array */
    private array                 $cookies;

    /*** @var array */
    private array                 $files;

    /*** @var array */
    private array                 $server;

    /*** @var string */
    private string                $content;

    /*** @var string */
    private string                $buffer;

    /*** @var MultipartHandler|null */
    private MultipartHandler|null $multipartHandler;

    /*** @var int */
    private int                   $bodyLength;

    /*** @var int */
    private int $contentLength;

    /**
     * @param SocketStream $stream
     */
    public function __construct(private readonly SocketStream $stream)
    {
        $this->reset();
    }

    /**
     * @return void
     */
    private function reset(): void
    {
        $this->step          = 0;
        $this->query         = array();
        $this->request       = array();
        $this->attributes    = array();
        $this->cookies       = array();
        $this->files         = array();
        $this->server        = array();
        $this->content       = '';
        $this->buffer        = '';
        $this->multipartHandler = null;
        $this->bodyLength    = 0;
        $this->contentLength = 0;
    }

    /**
     * @param string $content
     * @return Request|null
     * @throws Exception\FormatException
     * @throws RuntimeException
     */
    public function tick(string $content): Request|null
    {
        $this->buffer .= $content;
        if ($this->step === 0) {
            if ($headerEnd = strpos($this->buffer, "\r\n\r\n")) {
                $buffer = $this->freeBuffer();

                /**
                 * 切割解析head与body部分
                 */
                $this->step       = 1;
                $header           = substr($buffer, 0, $headerEnd);
                $base             = strtok($header, "\r\n");

                if (count($base = explode(' ', $base)) !== 3) {
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

                $body = substr($buffer, $headerEnd + 4);
                $this->bodyLength += strlen($body);

                /**
                 * 解析请求头
                 */
                if (in_array($method, ['GET', 'HEAD'])) {
                    $this->bodyLength = 0;
                    $this->step       = 2;
                } elseif ($method === 'POST') {
                    if (!$contentType = $this->server['HTTP_CONTENT_TYPE'] ?? null) {
                        $contentType = '';
                    }
                    if (!isset($this->server['HTTP_CONTENT_LENGTH'])) {
                        throw new RuntimeException('Content-Length is not set 1');
                    }

                    $this->contentLength = intval($this->server['HTTP_CONTENT_LENGTH']);

                    if (str_contains($contentType, 'multipart/form-data')) {
                        preg_match('/boundary=(.*)$/', $contentType, $matches);
                        if (!isset($matches[1])) {
                            throw new RuntimeException('boundary is not set');
                        } else {
                            $this->step          = 3;
                            $this->multipartHandler = new MultipartHandler($matches[1]);
                            $this->stream->onClose(fn () => $this->multipartHandler?->cancel());

                            foreach ($this->multipartHandler->tick($body) as $name => $multipartResult) {
                                if (is_string($multipartResult)) {
                                    $this->request[$name] = $multipartResult;
                                } elseif(is_array($multipartResult)) {
                                    foreach ($multipartResult as $file) {
                                        $this->files[$name][] = $file;
                                    }
                                }
                            }

                            if ($this->bodyLength === $this->contentLength) {
                                $this->step = 2;
                                $this->multipartHandler->cancel();
                            } elseif ($this->bodyLength > $this->contentLength) {
                                throw new RuntimeException('Content-Length is not match 2');
                            }
                            $this->content = '';
                        }
                    } else {
                        $this->content = $body;
                    }

                    if ($this->bodyLength === $this->contentLength) {
                        $this->step = 2;
                    } elseif ($this->bodyLength > $this->contentLength) {
                        throw new RuntimeException('Content-Length is not match 3');
                    }
                } elseif(in_array($method, ['PUT', 'DELETE','PATCH','OPTIONS','TRACE','CONNECT'])) {
                    //not body
                    if (!isset($this->server['HTTP_CONTENT_LENGTH'])) {
                        $this->step = 2;
                    } else {
                        if ($this->bodyLength === $this->contentLength) {
                            $this->step = 2;
                        } elseif ($this->bodyLength > $this->contentLength) {
                            throw new RuntimeException('Content-Length is not match 4');
                        }
                    }
                }
            }
        }

        /**
         * 持续传输
         */
        if ($this->step === 1 && $buffer = $this->freeBuffer()) {
            $this->content .= $buffer;
            $this->bodyLength += strlen($buffer);
            if ($this->bodyLength === $this->contentLength) {
                $this->step = 2;
            } elseif ($this->bodyLength > $this->contentLength) {
                throw new RuntimeException('Content-Length is not match 5');
            }
        }

        /**
         * 文件传输
         */
        if ($this->step === 3 && $buffer = $this->freeBuffer()) {
            $this->bodyLength += strlen($buffer);
            foreach ($this->multipartHandler->tick($buffer) as $name => $multipartResult) {
                if (is_string($multipartResult)) {
                    $this->request[$name] = $multipartResult;
                } elseif(is_array($multipartResult)) {
                    foreach ($multipartResult as $file) {
                        $this->files[$name][] = $file;
                    }
                }
            }

            if ($this->bodyLength === $this->contentLength) {
                $this->step = 2;
                $this->multipartHandler->cancel();
            } elseif ($this->bodyLength > $this->contentLength) {
                throw new RuntimeException('Content-Length is not match 6');
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
                    $this->cookies[$item[0]] = rawurldecode($item[1]);
                }
            }

            /**
             * 解析body
             */
            if ($this->server['REQUEST_METHOD'] === 'POST') {
                if (str_contains($this->server['HTTP_CONTENT_TYPE'] ?? '', 'application/json')) {
                    $this->request = array_merge($this->request, json_decode($this->content, true) ?? []);
                } else {
                    parse_str($this->content, $requestParams);
                    $this->request = array_merge($this->request, $requestParams);
                }
            }

            /**
             * 解析用户IP信息
             */
            $address = $this->stream->getAddress();
            $host    = $this->stream->getHost();
            $port    = $this->stream->getPort();

            $this->server['REMOTE_ADDR'] = $host;
            $this->server['REMOTE_PORT'] = $port;
            if ($xForwardedProto = $this->server['HTTP_X_FORWARDED_PROTO'] ?? null) {
                $this->server['HTTPS'] = $xForwardedProto === 'https' ? 'on' : 'off';
            }

            $request =  new Request(
                $this->query,
                $this->request,
                $this->attributes,
                $this->cookies,
                $this->files,
                $this->server,
                $this->content,
            );

            $this->reset();
            return $request;
        }
        return null;
    }

    /**
     * @return string
     */
    private function freeBuffer(): string
    {
        $buffer = $this->buffer;
        $this->buffer = '';
        return $buffer;
    }
}
