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
    private string $readEventID;
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
            $this->readEventID = $this->proxy->onReadable(function () use ($resolve, $reject) {
                try {
                    $this->buffer .= $this->proxy->readContinuously(1024);
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
            if (isset($this->readEventID)) {
                cancel($this->readEventID);
                unset($this->readEventID);
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
