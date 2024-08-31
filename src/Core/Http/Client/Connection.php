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

namespace Psc\Core\Http\Client;

use GuzzleHttp\Psr7\Response;
use Psc\Core\Socket\SocketStream;
use Psc\Core\Stream\Exception\RuntimeException;
use Psr\Http\Message\ResponseInterface;

use function count;
use function ctype_xdigit;
use function explode;
use function fclose;
use function fwrite;
use function hexdec;
use function intval;
use function strlen;
use function strpos;
use function strtok;
use function substr;
use function is_resource;

/**
 * @Author cclilshy
 * @Date   2024/8/27 21:47
 */
class Connection
{
    /**
     * @param SocketStream $stream
     */
    public function __construct(public SocketStream $stream)
    {
        $this->reset();
    }

    private int    $step          = 0;
    private int    $statusCode    = 0;
    private string $statusMessage = '';
    private int    $contentLength = 0;
    private array  $headers       = [];
    private string $content       = '';
    private int    $bodyLength    = 0;
    private string $versionString = '';
    private string $buffer        = '';
    private bool $chunk       = false;
    private int  $chunkLength = 0;
    private int  $chunkStep   = 0;

    /**
     * @param string $content
     * @return ResponseInterface|null
     * @throws RuntimeException
     */
    public function tick(string $content): ResponseInterface|null
    {
        $this->buffer .= $content;
        if ($this->step === 0) {
            if ($headerEnd = strpos($this->buffer, "\r\n\r\n")) {
                $buffer = $this->freeBuffer();

                /**
                 * 切割解析head与body部分
                 */
                $this->step = 1;
                $header     = substr($buffer, 0, $headerEnd);
                $base       = strtok($header, "\r\n");

                if (count($base = explode(' ', $base)) < 3) {
                    throw new RuntimeException('Request head is not match');
                }

                $this->versionString = $base[0];
                $this->statusCode    = intval($base[1]);
                $this->statusMessage = $base[2];

                /**
                 * 解析header
                 */
                while ($line = strtok("\r\n")) {
                    $lineParam = explode(': ', $line, 2);
                    if (count($lineParam) >= 2) {
                        $this->headers[$lineParam[0]] = $lineParam[1];
                    }
                }

                if ($this->chunk = isset($this->headers['Transfer-Encoding']) && $this->headers['Transfer-Encoding'] === 'chunked') {
                    $this->step   = 3;
                    $this->buffer = substr($buffer, $headerEnd + 4);
                } else {
                    $contentLength = $this->headers['Content-Length'] ?? null;
                    if ($this->statusCode === 200) {
                        if ($contentLength === null) {
                            throw new RuntimeException('Response content length is required');
                        }
                    }
                    $this->contentLength = intval($contentLength);
                    $buffer              = substr($buffer, $headerEnd + 4);
                    $this->output($buffer);
                    $this->bodyLength += strlen($buffer);
                    if ($this->bodyLength === $this->contentLength) {
                        $this->step = 2;
                    }
                }
            }
        }

        if ($this->step === 1 && $buffer = $this->freeBuffer()) {
            $this->output($buffer);
            $this->bodyLength += strlen($buffer);
            if ($this->bodyLength === $this->contentLength) {
                $this->step = 2;
            }
        }

        if ($this->step === 3 && $buffer = $this->freeBuffer()) {
            do {
                if ($this->chunkStep === 0) {
                    $chunkEnd = strpos($buffer, "\r\n");
                    if ($chunkEnd === false) {
                        break;
                    }

                    $chunkLengthHex = substr($buffer, 0, $chunkEnd);
                    if (!ctype_xdigit($chunkLengthHex)) {
                        throw new RuntimeException("Invalid chunk length: " . $chunkLengthHex);
                    }

                    $this->chunkLength = hexdec($chunkLengthHex);
                    $buffer = substr($buffer, $chunkEnd + 2);

                    if ($this->chunkLength === 0) {
                        if (strlen($buffer) < 2) {
                            break;
                        }
                        $buffer = substr($buffer, 2);
                        $this->step = 2;
                        break;
                    }

                    $this->chunkStep = 1;
                } else {
                    if (strlen($buffer) < $this->chunkLength + 2) {
                        break;
                    }

                    $chunkData = substr($buffer, 0, $this->chunkLength);
                    $this->output($chunkData);
                    $buffer = substr($buffer, $this->chunkLength + 2); // 跳过数据和尾部 CRLF
                    $this->chunkStep = 0;
                }
            } while ($this->step !== 2);

            $this->buffer = $buffer;
        }

        if ($this->step === 2) {
            $response = new Response(
                $this->statusCode,
                $this->headers,
                $this->content,
                $this->versionString,
                $this->statusMessage,
            );
            $this->reset();

            return $response;
        }
        return null;
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/27 21:47
     * @param string $content
     * @return void
     */
    private function output(string $content): void
    {
        if ($this->output) {
            fwrite($this->output, $content);
        } else {
            $this->content .= $content;
        }
    }

    /*** @var mixed|null */
    private mixed $output = null;

    /**
     * @param mixed $resource
     * @return void
     */
    public function setOutput(mixed $resource): void
    {
        $this->output = $resource;
    }

    /**
     * @return string
     */
    private function freeBuffer(): string
    {
        $buffer       = $this->buffer;
        $this->buffer = '';
        return $buffer;
    }

    /**
     * @return void
     */
    private function reset(): void
    {
        if ($this->output) {
            if (is_resource($this->output)) {
                fclose($this->output);
                $this->output = null;
            }
        }

        $this->step          = 0;
        $this->statusCode    = 0;
        $this->statusMessage = '';
        $this->contentLength = 0;
        $this->headers       = [];
        $this->content       = '';
        $this->bodyLength    = 0;
        $this->versionString = '';
        $this->buffer = '';
        $this->chunk = false;
        $this->chunkLength = 0;
        $this->chunkStep = 0;
    }
}
