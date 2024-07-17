<?php

declare(strict_types=1);
/*
 * Copyright (c) 2024.
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

namespace Psc\Store\System\Proc;

use Closure;
use Psc\Core\Output;
use Psc\Std\Stream\Exception\ConnectionException;

use function call_user_func;
use function is_resource;
use function proc_close;

/**
 *
 */
class Session
{
    private ProcStream $streamStdInput;
    private ProcStream $streamStdOutput;
    private ProcStream $streamStdError;

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
        $this->streamStdInput  = new ProcStream($streamStdInput);
        $this->streamStdOutput = new ProcStream($streamStdOutput);
        $this->streamStdError  = new ProcStream($streamStdError);
        $this->streamStdInput->setBlocking(false);
        $this->streamStdOutput->setBlocking(false);
        $this->streamStdError->setBlocking(false);

        $this->streamStdInput->onClose(fn () => $this->close());
        $this->streamStdOutput->onClose(fn () => $this->close());
        $this->streamStdError->onClose(fn () => $this->close());

        $this->streamStdOutput->onReadable(function () {
            $content = $this->streamStdOutput->read(1024);
            if ($content === '') {
                $this->close();
                return;
            }

            if (isset($this->onMessage)) {
                call_user_func($this->onMessage, $content, $this);
            }
        });

        $this->streamStdError->onReadable(function () {
            $content = $this->streamStdError->read(1024);
            if ($content === '') {
                $this->close();
                return;
            }

            if (isset($this->onErrorMessage)) {
                call_user_func($this->onErrorMessage, $content, $this);
            }
        });
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
            proc_close($this->proc);
            $this->streamStdError->close();
            $this->streamStdOutput->close();
            $this->streamStdInput->close();

            if (isset($this->onClose)) {
                call_user_func($this->onClose, $this);
            }
        }
    }

    /**
     * @param string $content
     * @return bool
     */
    public function input(string $content): bool
    {
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
     *
     */
    public function __destruct()
    {
        $this->close();
    }
}
