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

namespace Ripple\Proc;

use Closure;
use Ripple\Kernel;
use Ripple\Stream\Exception\ConnectionException;
use Ripple\Utils\Output;
use Throwable;

use function call_user_func;
use function implode;
use function is_array;
use function is_resource;
use function posix_kill;
use function proc_close;
use function proc_get_status;
use function proc_terminate;

use const SIGTERM;

/**
 * @Author cclilshy
 * @Date   2024/8/16 09:36
 */
class Session
{
    /*** @var Closure */
    public Closure $onClose;

    /*** @var Closure */
    public Closure $onErrorMessage;

    /*** @var Closure */
    public Closure $onMessage;

    /*** @var Stream */
    public readonly Stream $streamStdInput;

    public readonly Stream $streamStdOutput;

    /*** @var Stream */
    public readonly Stream $streamStdError;

    /*** @var bool */
    public bool $closed = false;

    /**
     * @param mixed $proc
     * @param mixed $streamStdInput
     * @param mixed $streamStdOutput
     * @param mixed $streamStdError
     */
    public function __construct(
        protected readonly mixed $proc,
        mixed                    $streamStdInput,
        mixed                    $streamStdOutput,
        mixed                    $streamStdError,
    ) {
        $this->streamStdInput = new Stream($streamStdInput);
        $this->streamStdOutput = new Stream($streamStdOutput);
        $this->streamStdError = new Stream($streamStdError);
        $this->streamStdInput->setBlocking(false);
        $this->streamStdOutput->setBlocking(false);
        $this->streamStdError->setBlocking(false);

        $this->streamStdOutput->onReadable(function () {
            try {
                $content = '';
                while ($buffer = $this->streamStdOutput->read(1024)) {
                    $content .= $buffer;
                }
            } catch (Throwable) {
                $this->close();
                return;
            }
            if ($content === '') {
                if ($this->streamStdOutput->eof()) {
                    $this->close();
                }
                return;
            }

            if (isset($this->onMessage)) {
                call_user_func($this->onMessage, $content, $this);
            }
        });

        $this->streamStdError->onReadable(function () {
            try {
                $content = '';
                while ($buffer = $this->streamStdError->read(1024)) {
                    $content .= $buffer;
                }
            } catch (Throwable) {
                $this->close();
                return;
            }

            if ($content === '') {
                if ($this->streamStdError->eof()) {
                    $this->close();
                }
                return;
            }

            if (isset($this->onErrorMessage)) {
                call_user_func($this->onErrorMessage, $content, $this);
            }
        });

        $this->streamStdOutput->onClose(fn () => $this->close());
        $this->streamStdError->onClose(fn () => $this->close());
    }

    /**
     * @return void
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        if (is_resource($this->proc)) {
            $this->streamStdError->close();
            $this->streamStdOutput->close();
            $this->streamStdInput->close();

            if (!is_resource($this->proc)) {
                return;
            }

            try {
                if (!$this->getStatus('running')) {
                    proc_close($this->proc);
                } else {
                    $this->terminate(SIGTERM);
                    if (!$this->getStatus('running')) {
                        proc_close($this->proc);
                    }
                }
            } catch (Throwable) {
                proc_close($this->proc);
            }

            if (isset($this->onClose)) {
                call_user_func($this->onClose, $this);
            }
        }
    }

    /**
     * @param string|null $key
     *
     * @return mixed
     */
    public function getStatus(string|null $key = null): mixed
    {
        $status = proc_get_status($this->proc);
        return $key ? ($status[$key] ?? null) : $status;
    }

    /**
     * @param int|null $signal
     *
     * @return bool
     */
    public function terminate(int|null $signal = null): bool
    {
        if ($signal) {
            return proc_terminate($this->proc, $signal);
        }
        return proc_terminate($this->proc);
    }

    /**
     * @param string|array $content
     *
     * @return bool
     */
    public function input(string|array $content): bool
    {
        if (is_array($content)) {
            $content = implode(' ', $content);
        }
        return $this->write("{$content}\n");
    }

    /**
     * @param string $content
     *
     * @return bool
     */
    public function write(string $content): bool
    {
        try {
            $this->streamStdInput->write($content);
            return true;
        } catch (ConnectionException $exception) {
            Output::error($exception->getMessage());
            $this->close();
            return false;
        }
    }

    /**
     * @return void
     */
    public function inputEot(): void
    {
        $this->streamStdInput->close();
    }

    /**
     * @param int $signalCode
     *
     * @return bool
     */
    public function inputSignal(int $signalCode): bool
    {
        /*** @compatible:Windows */
        if (!Kernel::getInstance()->supportProcessControl()) {
            return false;
        }

        return posix_kill($this->getStatus('pid'), $signalCode);
    }

    /**
     * @return mixed
     */
    public function getProc(): mixed
    {
        return $this->proc;
    }

    public function __destruct()
    {
        $this->close();
    }
}
