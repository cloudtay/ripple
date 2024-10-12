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

use Psc\Core\Socket\SocketStream;

use function array_merge;
use function is_string;
use function json_encode;

/**
 * requesting entity
 */
class Request
{
    /*** @var array */
    public readonly array $REQUEST;

    /*** @var Response */
    protected Response $response;

    /**
     * @param SocketStream $stream
     * @param array        $GET
     * @param array        $POST
     * @param array        $COOKIE
     * @param array        $FILES
     * @param array        $SERVER
     * @param mixed|null   $CONTENT
     */
    public function __construct(
        public readonly SocketStream $stream,
        public readonly array        $GET = [],
        public readonly array        $POST = [],
        public readonly array        $COOKIE = [],
        public readonly array        $FILES = [],
        public readonly array        $SERVER = [],
        public readonly mixed        $CONTENT = null,
    ) {
        $this->REQUEST = array_merge($this->GET, $this->POST);
    }

    /**
     * @return Response
     */
    public function getResponse(): Response
    {
        if (!isset($this->response)) {
            $this->response = new Response($this->stream);
        }
        return $this->response;
    }

    /**
     * @return SocketStream
     */
    public function getStream(): SocketStream
    {
        return $this->stream;
    }

    /**
     * @param mixed $content
     * @param int   $statusCode
     * @param array $withHeaders
     *
     * @return void
     */
    public function respond(mixed $content, array $withHeaders = [], int $statusCode = 200): void
    {
        $response = $this->getResponse();

        if ($statusCode) {
            $response->setStatusCode($statusCode);
        }

        foreach ($withHeaders as $name => $value) {
            $response->withHeader($name, $value);
        }

        $response->setBody($content)->respond();
    }

    /**
     * @param mixed $content
     * @param array $withHeaders
     * @param int   $statusCode
     *
     * @return void
     */
    public function respondJson(mixed $content, array $withHeaders = [], int $statusCode = 200): void
    {
        $this->respond(
            is_string($content) ? $content : json_encode($content),
            array_merge(['Content-Type' => 'application/json'], $withHeaders),
            $statusCode
        );
    }

    /**
     * @param mixed $content
     * @param array $withHeaders
     * @param int   $statusCode
     *
     * @return void
     */
    public function respondText(string $content, array $withHeaders = [], int $statusCode = 200): void
    {
        $this->respond(
            $content,
            array_merge(['Content-Type' => 'text/plain'], $withHeaders),
            $statusCode
        );
    }

    /**
     * @param mixed $content
     * @param array $withHeaders
     * @param int   $statusCode
     *
     * @return void
     */
    public function respondHtml(string $content, array $withHeaders = [], int $statusCode = 200): void
    {
        $this->respond(
            $content,
            array_merge(['Content-Type' => 'text/html'], $withHeaders),
            $statusCode
        );
    }
}
