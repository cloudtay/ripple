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

namespace P\Net\Http\Server;

use Psc\Core\Stream\Stream;
use Psc\Std\Stream\Exception\RuntimeException;

/**
 * 请求单例
 */
class RequestSingle
{
    /**
     * @var string
     */
    public string $hash;

    /**
     * @var string
     */
    public string $method;

    /**
     * @var string
     */
    public string $url;

    /**
     * @var string
     */
    public string $version;

    /**
     * @var string
     */
    public string $header;

    /**
     * @var array
     */
    public array $headers = [];

    /**
     * @var string
     */
    public string $body = '';

    /**
     * @var int
     */
    public int $bodyLength = 0;


    /**
     * @var string $statusCode
     */
    public string $statusCode;

    /**
     * @var Stream
     */
    public Stream $client;


    /**
     * @var bool
     */
    public bool $upload = false;

    /**
     * @var string
     */
    public string $boundary = '';

    /**
     * @var RequestUpload
     */
    public RequestUpload $uploadHandler;

    /**
     * RequestSingle constructor.
     * @param Stream $client
     */
    public function __construct(Stream $client)
    {

    }

    /**
     * Push request body
     * @param string $context
     * @return void
     * @throws RuntimeException()
     */
    public function revolve(string $context): void
    {
        if (!isset($this->method)) {
            //
            if (!$this->parseRequestHead($context)) {
                return;
            }

            switch ($this->method) {
                case 'GET':
                    $this->statusCode = RequestFactory::COMPLETE;
                    return;
                case 'POST':
                case 'PUT':
                case 'PATCH':
                case 'DELETE':
                    break;
            }
        } else {
            //

            switch ($this->method) {
                case 'POST':
                case 'PUT':
                case 'PATCH':
                case 'DELETE':
                    $this->bodyLength += strlen($context);
                    if ($this->upload) {
                        $this->uploadHandler->push($context);
                    } else {
                        $this->body .= $context;
                    }

                    if ($this->bodyLength === intval($this->headers['Content-Length'])) {
                        $this->statusCode = RequestFactory::COMPLETE;
                    } elseif ($this->bodyLength > intval($this->headers['Content-Length'])) {
                        throw new RuntimeException('Content-Length is not match');
                    } else {
                        $this->statusCode = RequestFactory::INCOMPLETE;
                    }
                    break;
            }
        }


        if ($this->bodyLength === intval($this->headers['Content-Length'])) {
            $this->statusCode = RequestFactory::COMPLETE;
        } elseif ($this->bodyLength > intval($this->headers['Content-Length'])) {
            throw new RuntimeException('Content-Length is not match');
        } else {
            $this->statusCode = RequestFactory::INCOMPLETE;
        }
    }

    /**
     * @param string $context
     * @return bool
     * @throws RuntimeException()
     */
    private function parseRequestHead(string $context): bool
    {
        if ($headerEnd = strpos($context, "\r\n\r\n")) {
            $this->header     = substr($context, 0, $headerEnd);
            $this->body       = substr($context, $headerEnd + 4);
            $this->bodyLength = strlen($this->body);

            $baseContent = strtok($this->header, "\r\n");
            if (count($base = explode(' ', $baseContent)) !== 3) {
                throw new RuntimeException('Request head is not match');
            }
            $this->url     = $base[1];
            $this->version = $base[2];
            $this->method  = $base[0];
            while ($line = strtok("\r\n")) {
                $lineParam = explode(': ', $line, 2);
                if (count($lineParam) >= 2) {
                    $this->headers[$lineParam[0]] = $lineParam[1];
                }
            }

            //
            if ($this->method === 'POST') {
                if (!$contentType = $this->headers['Content-Type'] ?? null) {
                    throw new RuntimeException('Content-Type is not set');
                }

                if (!isset($this->headers['Content-Length'])) {
                    throw new RuntimeException('Content-Length is not set');
                }

                if (str_contains($contentType, 'multipart/form-data')) {
                    preg_match('/boundary=(.*)$/', $contentType, $matches);
                    if (isset($matches[1])) {
                        $this->boundary      = $matches[1];
                        $this->upload        = true;
                        $this->uploadHandler = new RequestUpload($this, $this->boundary);
                        $this->uploadHandler->push($this->body);
                        $this->body = '';
                    } else {
                        throw new RuntimeException('boundary is not set');
                    }
                }
            }

            return true;
        } else {
            $this->body .= $context;
        }
        return false;
    }

    /**
     * 打包请求
     * @return Request
     */
    public function build(): Request
    {
        return new Request($this);
    }
}
