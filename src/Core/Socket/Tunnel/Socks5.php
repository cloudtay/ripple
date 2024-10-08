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

use function bin2hex;
use function chr;
use function Co\cancel;
use function Co\promise;
use function pack;
use function strlen;
use function substr;

/**
 * @Author cclilshy
 * @Date   2024/8/29 12:16
 */
class Socks5 extends Tunnel
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
            $this->sendInitialHandshake();

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
     * @return void
     * @throws ConnectionException
     */
    private function sendInitialHandshake(): void
    {
        if (isset($this->payload['username'], $this->payload['password'])) {
            $request = "\x05\x01\x02";
        } else {
            $request = "\x05\x01\x00";
        }
        $this->proxy->write($request);
        $this->step = 1;
    }

    /**
     * @param Closure $resolve
     * @param Closure $reject
     *
     * @return void
     * @throws ConnectionException
     */
    private function processBuffer(Closure $resolve, Closure $reject): void
    {
        switch ($this->step) {
            case 1:
                $this->handleHandshakeResponse($resolve, $reject);
                break;
            case 2:
                $this->handleBindResponse($resolve, $reject);
                break;
            case 3:
                $this->handleAuthResponse($resolve, $reject);
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
     * @throws ConnectionException
     */
    private function handleHandshakeResponse(Closure $resolve, Closure $reject): void
    {
        if (strlen($this->buffer) < 2) {
            return;
        }

        $response     = substr($this->buffer, 0, 2);
        $this->buffer = substr($this->buffer, 2);

        if ($response === "\x05\x00") {
            // No authentication required, go to binding step
            $this->sendBindRequest($this->payload['host'], $this->payload['port']);
            $this->step = 2;
        } elseif ($response === "\x05\x02") {
            if (isset($this->payload['username'], $this->payload['password'])) {
                $this->sendAuthRequest($this->payload['username'], $this->payload['password']);
                $this->step = 3;
            } else {
                $reject(new Exception("Authentication required but credentials missing."));
            }
        } else {
            $reject(new Exception("Invalid handshake response: " . bin2hex($response)));
        }
    }

    /**
     * @param string $host
     * @param int    $port
     *
     * @return void
     * @throws ConnectionException
     */
    private function sendBindRequest(string $host, int $port): void
    {
        $hostLen    = chr(strlen($host));
        $portPacked = pack('n', $port);
        $request    = "\x05\x01\x00\x03" . $hostLen . $host . $portPacked;
        $this->proxy->write($request);
    }

    /**
     * @param string $username
     * @param string $password
     *
     * @return void
     * @throws ConnectionException
     */
    private function sendAuthRequest(string $username, string $password): void
    {
        $request = "\x01" . chr(strlen($username)) . $username . chr(strlen($password)) . $password;
        $this->proxy->write($request);
    }

    /**
     * @param Closure $resolve
     * @param Closure $reject
     *
     * @return void
     */
    private function handleBindResponse(Closure $resolve, Closure $reject): void
    {
        if (strlen($this->buffer) < 10) {
            return;
        }

        $response     = substr($this->buffer, 0, 10);
        $this->buffer = substr($this->buffer, 10);

        if ($response[1] === "\x00") {
            if (isset($this->readEventId)) {
                cancel($this->readEventId);
                unset($this->readEventId);
            }
            $resolve();
        } else {
            $reject(new Exception("Bind request failed with response: " . bin2hex($response)));
        }
    }

    /**
     * @param Closure $resolve
     * @param Closure $reject
     *
     * @return void
     * @throws ConnectionException
     */
    private function handleAuthResponse(Closure $resolve, Closure $reject): void
    {
        if (strlen($this->buffer) < 2) {
            return;
        }

        $response     = substr($this->buffer, 0, 2);
        $this->buffer = substr($this->buffer, 2);

        if ($response === "\x01\x00") {
            $this->sendBindRequest($this->payload['host'], $this->payload['port']);
            $this->step = 2;
        } else {
            $reject(new Exception("Authentication failed with response: " . bin2hex($response)));
        }
    }
}
