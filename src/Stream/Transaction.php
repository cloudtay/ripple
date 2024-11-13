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

namespace Ripple\Stream;

use Closure;
use Ripple\Promise;
use Ripple\Stream;
use Throwable;

use function call_user_func_array;
use function Co\cancel;
use function Co\promise;

final class Transaction
{
    /**
     * @var string
     */
    protected string $onReadableID;

    /**
     * @var string
     */
    protected string $onWriteableID;

    /**
     * @var string[]
     */
    protected array $onCloseIDs = [];

    /**
     * @var Closure
     */
    protected Closure $resolve;

    /**
     * @var Closure
     */
    protected Closure $reject;

    /**
     * @var Promise
     */
    protected Promise $promise;

    /**
     * @param Stream $stream
     */
    public function __construct(protected readonly Stream $stream)
    {
        $this->promise = promise(function (Closure $resolve, Closure $reject) {
            $this->resolve = $resolve;
            $this->reject  = $reject;
        })->finally(fn () => $this->cancelAll());
    }

    /**
     * @return void
     */
    protected function cancelAll(): void
    {
        foreach ($this->onCloseIDs as $id) {
            $this->stream->cancelOnClose($id);
        }

        if (isset($this->onReadableID)) {
            cancel($this->onReadableID);
            unset($this->onReadableID);
        }

        if (isset($this->onWriteableID)) {
            cancel($this->onWriteableID);
            unset($this->onWriteableID);
        }
    }

    /**
     * @param Closure $closure
     *
     * @return string
     */
    public function onReadable(Closure $closure): string
    {
        return $this->onReadableID = $this->stream->onReadable(function (Stream $stream, Closure $cancel) use ($closure) {
            try {
                call_user_func_array($closure, [$stream, $cancel]);
            } catch (Throwable $exception) {
                $this->fail($exception);
            }
        });
    }

    /**
     * @param Throwable $exception
     *
     * @return void
     */
    public function fail(Throwable $exception): void
    {
        if ($this->promise->getStatus() !== Promise::PENDING) {
            return;
        }
        ($this->reject)($exception);
    }

    /**
     * @param Closure $closure
     *
     * @return string
     */
    public function onWriteable(Closure $closure): string
    {
        return $this->onWriteableID = $this->stream->onWriteable(function (Stream $stream, Closure $cancel) use ($closure) {
            try {
                call_user_func_array($closure, [$stream, $cancel]);
            } catch (Throwable $exception) {
                $this->fail($exception);
            }
        });
    }

    /**
     * @return void
     */
    public function complete(): void
    {
        if ($this->promise->getStatus() !== Promise::PENDING) {
            return;
        }
        ($this->resolve)();
    }

    /**
     * @param Closure $closure
     *
     * @return string
     */
    public function onClose(Closure $closure): string
    {
        $id                 = $this->stream->onClose($closure);
        $this->onCloseIDs[] = $id;
        return $id;
    }

    /**
     * @return Promise
     */
    public function getPromise(): Promise
    {
        return $this->promise;
    }

    /**
     * @return Stream
     */
    public function getStream(): Stream
    {
        return $this->stream;
    }
}
