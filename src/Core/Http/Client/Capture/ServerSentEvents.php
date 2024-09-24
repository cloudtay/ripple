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

namespace Psc\Core\Http\Client\Capture;

use Closure;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

use function array_shift;
use function count;
use function ctype_xdigit;
use function explode;
use function hexdec;
use function str_contains;
use function strlen;
use function strpos;
use function strtolower;
use function strtoupper;
use function substr;
use function trim;

/**
 * @Author cclilshy
 * @Date   2024/9/4 12:21
 */
class ServerSentEvents
{
    /*** @var string */
    private string $buffer = '';

    /*** @var array */
    private array $headers = [];

    /*** @var bool */
    private bool $isSSE = false;

    /*** @var Closure|null */
    private Closure|null $onEvent = null;

    /*** @var ResponseInterface|null */
    private ResponseInterface|null $response = null;

    /*** @var bool */
    private bool $isChunked = false;

    /**
     * @param Closure $handler
     *
     * @return void
     */
    public function onEvent(Closure $handler): void
    {
        $this->onEvent = $handler;
    }

    /**
     * @return Closure
     */
    public function getWriteCapture(): Closure
    {
        return function (string $content, Closure $pass) {
            return $pass($content);
        };
    }

    /**
     * @return Closure
     */
    public function getReadCapture(): Closure
    {
        return function (string|false $content, Closure $pass) {
            if ($content === false) {
                return $pass($content);
            }

            $this->buffer .= $content;
            $this->processBuffer();
            return $pass($content);
        };
    }

    /**
     * @return void
     */
    private function processBuffer(): void
    {
        if (!$this->isSSE) {
            $headerEnd = strpos($this->buffer, "\r\n\r\n");
            if ($headerEnd !== false) {
                $header       = substr($this->buffer, 0, $headerEnd);
                $this->buffer = substr($this->buffer, $headerEnd + 4);
                $this->parseHeaders($header);

                if (!$this->isSSE) {
                    throw new RuntimeException('Response is not SSE');
                }

                $this->response  = new Response(200, $this->headers, '');
                $this->isChunked = isset($this->headers['TRANSFER-ENCODING']) && strtolower($this->headers['TRANSFER-ENCODING']) === 'chunked';
            }
        }
        $this->isChunked ? $this->processChunkedBuffer() : $this->parseEvents();
    }

    /**
     * @param string $header
     *
     * @return void
     */
    private function parseHeaders(string $header): void
    {
        $lines = explode("\r\n", $header);

        $firstLine = array_shift($lines);
        if (!$firstLine || count(explode(' ', $firstLine)) < 3) {
            throw new RuntimeException('Header parsing failed');
        }

        foreach ($lines as $line) {
            if (str_contains($line, ': ')) {
                [$key, $value] = explode(': ', $line, 2);
                $this->headers[strtoupper($key)] = $value;
            }
        }

        $this->isSSE = isset($this->headers['CONTENT-TYPE']) && str_contains($this->headers['CONTENT-TYPE'], 'text/event-stream');
    }

    /**
     * @return void
     */
    private function processChunkedBuffer(): void
    {
        while (true) {
            $sizeEnd = strpos($this->buffer, "\r\n");
            if ($sizeEnd === false) {
                return;
            }

            $sizeHex = trim(substr($this->buffer, 0, $sizeEnd));
            if (!ctype_xdigit($sizeHex)) {
                $this->parseEvents();
                return;
            }

            $size = hexdec($sizeHex);
            if ($size === 0) {
                $this->buffer = substr($this->buffer, $sizeEnd + 2);
                break;
            }

            $chunkStart = $sizeEnd + 2;

            if (strlen($this->buffer) < $chunkStart + $size + 2) {
                return;
            }

            $chunkData    = substr($this->buffer, $chunkStart, $size);
            $this->buffer = substr($this->buffer, $chunkStart + $size + 2);
            $this->buffer .= $chunkData;
        }

        $this->parseEvents();
    }


    /**
     * @return void
     */
    private function parseEvents(): void
    {
        while (($eventEnd = strpos($this->buffer, "\n\n")) !== false) {
            $eventData    = substr($this->buffer, 0, $eventEnd);
            $this->buffer = substr($this->buffer, $eventEnd + 2);

            $event = $this->parseEvent($eventData);

            if (isset($this->onEvent)) {
                ($this->onEvent)($event);
            }
        }
    }

    /**
     * @param string $eventData
     *
     * @return array
     */
    private function parseEvent(string $eventData): array
    {
        $event = [];
        $lines = explode("\n", $eventData);

        foreach ($lines as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }

            [$field, $value] = explode(':', $line, 2);
            $event[trim($field)] = trim($value);
        }
        return $event;
    }
}
