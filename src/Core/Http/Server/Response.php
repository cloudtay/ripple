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
use Generator;
use Override;
use Psc\Core\Socket\SocketStream;
use Psc\Core\Stream\Exception\ConnectionException;
use Psc\Core\Stream\Stream;
use Throwable;

use function basename;
use function Co\promise;
use function filesize;
use function is_resource;
use function is_string;
use function str_contains;
use function strlen;
use function strtolower;
use function strval;

/**
 * response entity
 */
class Response extends \Symfony\Component\HttpFoundation\Response
{
    /*** @var mixed */
    protected mixed $body;

    /**
     * @param SocketStream $stream
     */
    public function __construct(private readonly SocketStream $stream)
    {
        parent::__construct();
    }

    /**
     * @param mixed $content
     *
     * @return $this
     */
    #[Override] public function setContent(mixed $content): static
    {
        return $this->setBody($content);
    }

    /**
     * @return static
     * @throws ConnectionException
     */
    public function sendStatus(): static
    {
        $this->stream->write("HTTP/1.1 {$this->getStatusCode()} {$this->statusText}\r\n");
        return $this;
    }


    /**
     * @param int|null $statusCode
     *
     * @return static
     * @throws ConnectionException
     */
    #[Override] public function sendHeaders(int|null $statusCode = null): static
    {
        foreach ($this->headers->allPreserveCaseWithoutCookies() as $name => $values) {
            foreach ($values as $value) {
                $this->stream->write("$name: $value\r\n");
            }
        }

        foreach ($this->headers->getCookies() as $cookie) {
            $this->stream->write('Set-Cookie: ' . $cookie . "\r\n");
        }

        return $this;
    }

    /**
     * @Author cclilshy
     * @Date   2024/9/1 11:37
     * @return Response
     * @throws ConnectionException|Throwable
     */
    #[Override] public function sendContent(): static
    {
        $this->stream->write("\r\n");
        // An exception occurs during transfer with the HTTP client and the currently open file stream should be closed.
        if (is_string($this->body)) {
            $this->stream->write($this->body);
        } elseif ($this->body instanceof Stream) {
            promise(function (Closure $resolve, Closure $reject) {
                $this->body->onReadable(function (Stream $body) use ($resolve, $reject) {
                    $content = '';
                    while ($buffer = $body->read(8192)) {
                        $content .= $buffer;
                    }

                    try {
                        $this->stream->write($content);
                    } catch (Throwable $exception) {
                        $body->close();
                        $reject($exception);
                    }

                    if ($body->eof()) {
                        $body->close();
                        $resolve();
                    }
                });
            })->await();
        } elseif ($this->body instanceof Generator) {
            foreach ($this->body as $content) {
                $this->stream->write($content);
            }
            if ($this->body->getReturn() === false) {
                $this->stream->close();
            }
        } else {
            throw new ConnectionException('The response content is illegal.', ConnectionException::ERROR_ILLEGAL_CONTENT);
        }
        return $this;
    }

    /**
     * @param mixed $content
     *
     * @return static
     */
    public function setBody(mixed $content): static
    {
        if (is_string($content)) {
            $this->headers->set('Content-Length', strval(strlen($content)));
        } elseif ($content instanceof Generator) {
            $this->headers->remove('Content-Length');
        } elseif ($content instanceof Stream) {
            $path   = $content->getMetadata('uri');
            $length = filesize($path);
            $this->headers->set('Content-Length', strval($length));
            $this->headers->set('Content-Type', 'application/octet-stream');
            if (!$this->headers->get('Content-Disposition')) {
                $this->headers->set('Content-Disposition', 'attachment; filename=' . basename($path));
            }
        } elseif (is_resource($content)) {
            return $this->setBody(new Stream($content));
        } elseif ($content instanceof Closure) {
            return $this->setBody($content());
        }

        $this->body = $content;
        return $this;
    }

    /**
     * @return void
     */
    public function respond(): void
    {
        try {
            $this->sendStatus();
            $this->sendHeaders();
            $this->sendContent();
        } catch (Throwable) {
            $this->stream->close();
            return;
        }

        $headerConnection = $this->headers->get('Connection');
        if (!$headerConnection || !str_contains(strtolower($headerConnection), 'keep-alive')) {
            $this->stream->close();
        }
    }

    /**
     * @Author cclilshy
     * @Date   2024/9/1 14:12
     * @return SocketStream
     */
    public function getStream(): SocketStream
    {
        return $this->stream;
    }
}
