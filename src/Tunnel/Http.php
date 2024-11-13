<?php declare(strict_types=1);
/**
 * Copyright © 2024 cclilshy
 * Email: jingnigg@gmail.com
 *
 * This software is licensed under the MIT License.
 * For full license details, please visit: https://opensource.org/licenses/MIT
 *
 * By using this software, you agree to the terms of the license.
 * Contributions, suggestions, and feedback are always welcome!
 */

namespace Ripple\Tunnel;

use Closure;
use Exception;
use Ripple\Stream\Exception\ConnectionException;
use Throwable;

use function base64_encode;
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
    private string $readEventID;
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
            $this->readEventID = $this->proxy->onReadable(function () use ($resolve, $reject) {
                try {
                    $this->buffer .= $this->proxy->readContinuously(8192);
                    $this->processBuffer($resolve, $reject);
                } catch (Exception $exception) {
                    $reject($exception);
                }
            });
        })->finally(function () {
            if (isset($this->readEventID)) {
                cancel($this->readEventID);
                unset($this->readEventID);
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
                   "Proxy-Connection: Keep-Alive\r\n";

        if (isset($this->payload['username'], $this->payload['password'])) {
            $request .= "Proxy-Authorization: Basic " . base64_encode("{$this->payload['username']}:{$this->payload['password']}") . "\r\n";
        }

        $request .= "\r\n";
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
