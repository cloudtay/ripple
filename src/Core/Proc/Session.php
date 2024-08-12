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

namespace Psc\Core\Proc;

use Closure;
use Psc\Std\Stream\Exception\ConnectionException;
use Psc\Utils\Output;
use Throwable;

use function call_user_func;
use function implode;
use function is_array;
use function is_resource;
use function posix_kill;
use function proc_close;
use function proc_get_status;

/**
 *
 */
class Session
{
    private ProcStream $streamStdInput;
    private ProcStream $streamStdOutput;
    private ProcStream $streamStdError;
    private array      $status;

    /**
     * @param mixed $proc
     * @param mixed $streamStdInput
     * @param mixed $streamStdOutput
     * @param mixed $streamStdError
     */
    public function __construct(
        private readonly mixed $proc,
        mixed                  $streamStdInput,
        mixed                  $streamStdOutput,
        mixed                  $streamStdError,
    ) {
        $this->status          = proc_get_status($this->proc);
        $this->streamStdInput  = new ProcStream($streamStdInput);
        $this->streamStdOutput = new ProcStream($streamStdOutput);
        $this->streamStdError  = new ProcStream($streamStdError);
        $this->streamStdInput->setBlocking(false);
        $this->streamStdOutput->setBlocking(false);
        $this->streamStdError->setBlocking(false);

        $this->streamStdOutput->onReadable(function () {
            try {
                $content = $this->streamStdOutput->read(1024);
            } catch (Throwable) {
                $this->close();
                return;
            }
            if ($content === '') {
                $this->close();
                return;
            }

            if (isset($this->onMessage)) {
                call_user_func($this->onMessage, $content, $this);
            }
        });

        $this->streamStdError->onReadable(function () {
            try {
                $content = $this->streamStdError->read(1024);
            } catch (Throwable) {
                $this->close();
                return;
            }
            if ($content === '') {
                $this->close();
                return;
            }

            if (isset($this->onErrorMessage)) {
                call_user_func($this->onErrorMessage, $content, $this);
            }
        });

        $this->streamStdOutput->onClose(fn () => $this->close());
        $this->streamStdError->onClose(fn () => $this->close());
    }

    public Closure $onClose;
    public Closure $onErrorMessage;
    public Closure $onMessage;

    /**
     * @return void
     */
    public function close(): void
    {
        if (is_resource($this->proc)) {
            $this->streamStdError->close();
            $this->streamStdOutput->close();
            $this->streamStdInput->close();
            try {
                proc_close($this->proc);
            } catch (Throwable) {
                // ignore
            }

            if (isset($this->onClose)) {
                call_user_func($this->onClose, $this);
            }
        }
    }

    /**
     * @param string|array $content
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
     * @return bool
     */
    public function write(string $content): bool
    {
        try {
            $this->streamStdInput->write($content);
            return true;
        } catch (ConnectionException $e) {
            Output::error($e->getMessage());
            $this->close();
            return false;
        }
    }

    /**
     * @param string $key
     * @return mixed
     */
    public function getStatus(string $key): mixed
    {
        return $key
            ? ($this->status[$key] ?? null)
            : $this->status;
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
     * @return bool
     */
    public function inputSignal(int $signalCode): bool
    {
        return posix_kill($this->getStatus('pid'), $signalCode);
    }


    public function __destruct()
    {
        $this->close();
    }
}
