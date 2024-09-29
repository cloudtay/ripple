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

namespace Psc\Core\Socket\Tunnel;

use Closure;
use Exception;
use Psc\Core\Stream\Exception\ConnectionException;
use Throwable;

use function Co\cancel;
use function Co\promise;
use function preg_match;
use function str_contains;
use function strlen;
use function strpos;
use function substr;

/**
 * @Author cclilshy
 * @Date   2024/8/29 12:45
 */
class Http extends Tunnel
{
    private int    $step   = 0;
    private string $readEventId;
    private string $buffer = '';

    /**
     * @return void
     * @throws Throwable
     */
    public function handshake(): void
    {
        promise(function (Closure $resolve, Closure $reject) {
            $this->sendConnectRequest();

            // Wait for handshake response
            $this->readEventId = $this->proxy->onReadable(function () use ($resolve, $reject) {
                try {
                    $this->buffer .= $this->proxy->readContinuously(1024);
                    $this->processBuffer($resolve, $reject);
                } catch (Exception $e) {
                    $reject($e);
                }
            });
        })->finally(function () {
            if (isset($this->readEventId)) {
                cancel($this->readEventId);
                unset($this->readEventId);
            }
        })->await();
    }

    /**
     * Send initial CONNECT request to proxy server
     *
     * @return void
     * @throws ConnectionException
     */
    private function sendConnectRequest(): void
    {
        $host    = $this->payload['host'];
        $port    = $this->payload['port'];
        $request = "CONNECT {$host}:{$port} HTTP/1.1\r\n" .
                   "Host: {$host}:{$port}\r\n" .
                   "Proxy-Connection: Keep-Alive\r\n\r\n";

        $this->proxy->write($request);
        $this->step = 1;
    }

    /**
     * Process the received data and handle the different handshake steps
     *
     * @param Closure $resolve
     * @param Closure $reject
     *
     * @return void
     */
    private function processBuffer(Closure $resolve, Closure $reject): void
    {
        switch ($this->step) {
            case 1:
                $this->handleConnectResponse($resolve, $reject);
                break;
            default:
                $reject(new Exception("Unexpected step {$this->step}"));
                break;
        }
    }

    /**
     * @param Closure $resolve
     * @param Closure $reject
     *
     * @return void
     */
    private function handleConnectResponse(Closure $resolve, Closure $reject): void
    {
        if (!str_contains($this->buffer, "\r\n\r\n")) {
            return;
        }

        $response     = substr($this->buffer, 0, strpos($this->buffer, "\r\n\r\n") + 4);
        $this->buffer = substr($this->buffer, strlen($response));

        if (preg_match('/^HTTP\/\d\.\d 200/', $response)) {
            $resolve();
        } else {
            $reject(new Exception("CONNECT request failed: {$response}"));
        }
    }
}
