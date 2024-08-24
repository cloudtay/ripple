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
use Psc\Core\Socket\SocketStream;
use Psc\Core\Stream\Stream;
use Psc\Std\Stream\Exception\ConnectionException;

use function basename;
use function call_user_func;
use function filesize;
use function is_resource;
use function is_string;
use function strlen;
use function strval;

/**
 * 响应实体
 */
class Response extends \Symfony\Component\HttpFoundation\Response
{
    /**
     * @var mixed
     */
    protected mixed $body;

    /**
     * @param SocketStream $stream
     * @param Closure      $done
     */
    public function __construct(public readonly SocketStream $stream, private readonly Closure $done)
    {
        parent::__construct();
    }

    /**
     * @param mixed       $content
     * @param string|null $contentType
     * @return $this
     */
    public function setContent(mixed $content, string $contentType = null): static
    {
        if (is_string($content)) {
            $this->headers->set('Content-Length', strval(strlen($content)));
        }

        if (is_resource($content)) {
            $content = new Stream($content);
        }

        if ($content instanceof Stream) {
            $path   = $content->getMetadata('uri');
            $length = filesize($path);
            $this->headers->set('Content-Length', strval($length));
            $this->headers->set('Content-Type', 'application/octet-stream');
            if (!$this->headers->get('Content-Disposition')) {
                $this->headers->set('Content-Disposition', 'attachment; filename=' . basename($path));
            }

            $this->stream->onClose(function () {
                $this->body->close();
                $this->done();
            });
        }

        if ($contentType) {
            $this->headers->set('Content-Type', $contentType);
        }

        $this->body = $content;
        return $this;
    }

    /**
     * @return void
     */
    private function done(): void
    {
        call_user_func($this->done);
    }

    /**
     * @return void
     * @throws ConnectionException
     */
    public function respond(): void
    {
        $this->sendHeaders();
        $this->sendContent();
    }

    /**
     * @param int|null $statusCode
     * @return $this
     * @throws ConnectionException
     */
    public function sendHeaders(?int $statusCode = null): static
    {
        /**
         *
         */
        $this->stream->write("HTTP/1.1 {$this->getStatusCode()} {$this->statusText}\r\n");
        foreach ($this->headers->allPreserveCaseWithoutCookies() as $name => $values) {
            foreach ($values as $value) {
                $this->stream->write("$name: $value\r\n");
            }
        }

        foreach ($this->headers->getCookies() as $cookie) {
            $this->stream->write('Set-Cookie: ' . $cookie . "\r\n");
        }

        $this->stream->write("\r\n");
        return $this;
    }

    /**
     * @return $this
     * @throws ConnectionException
     */
    public function sendContent(): static
    {
        if (is_string($this->body)) {
            $this->stream->write($this->body);
            $this->done();
        } elseif ($this->body instanceof Stream) {
            $this->body->onReadable(function () {
                $this->stream->write($this->body->read(8192));
                if ($this->body->eof()) {
                    $this->body->close();
                    $this->done();
                }
            });
        } else {
            $this->done();
        }

        return $this;
    }
}
