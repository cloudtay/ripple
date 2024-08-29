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

namespace Psc\Core\Socket\Proxy;

use Co\IO;
use Psc\Core\Coroutine\Promise;
use Psc\Core\Socket\SocketStream;
use Throwable;

use function Co\await;
use function stream_context_create;
use function stream_context_set_option;

/**
 * 该标准适用于透传模式的所有代理,通过该方法创建的套接字可以直接作为目标套接字访问
 * 需要注意getMeta的url部分不能表示为目标地址,使用者应手动做映射
 *
 * @Author cclilshy
 * @Date   2024/8/29 11:28
 */
abstract class Base
{
    /**
     * 与代理之间的套接字连接
     * @var SocketStream
     */
    protected SocketStream $proxy;

    /**
     * @param string $address
     * @param array  $payload
     * @param bool   $ssl
     * @throws Throwable
     */
    public function __construct(protected string $address, protected array $payload, bool $ssl = false)
    {
        $context = stream_context_create();
        stream_context_set_option($context, 'ssl', 'verify_peer', false);
        stream_context_set_option($context, 'ssl', 'verify_peer_name', false);

        if ($ssl) {
            $this->proxy = await(IO::Socket()->streamSocketClientSSL($address, 10, $context));
        } else {
            $this->proxy = await(IO::Socket()->streamSocketClient($address, 10, $context));
        }

        $this->proxy->setBlocking(false);
        await($this->handshake());
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/29 12:33
     * @return SocketStream
     */
    public function getSocketStream(): SocketStream
    {
        return $this->proxy;
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/29 11:34
     * @return Promise<bool>
     */
    abstract protected function handshake(): Promise;

    /**
     * @Author cclilshy
     * @Date   2024/8/29 12:38
     * @param string $address
     * @param array  $payload
     * @param bool   $ssl
     * @return static
     * @throws Throwable
     */
    public static function connect(string $address, array $payload, bool $ssl = false): static
    {
        return new static($address, $payload,$ssl);
    }
}
