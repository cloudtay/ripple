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

namespace Ripple;

use Closure;
use Ripple\Coroutine\Exception\Exception;
use Ripple\Coroutine\Exception\PromiseAggregateError;
use Ripple\Utils\Output;
use Throwable;

use function call_user_func;
use function call_user_func_array;
use function Co\async;
use function Co\await;
use function count;

/**
 * @Author cclilshy
 * @Date   2024/8/16 09:35
 *
 * Strictly follow the design philosophy of ES6-Promise/A+
 * @see    https://promisesaplus.com/
 */
class Promise
{
    public const PENDING   = 'pending';
    public const FULFILLED = 'fulfilled';
    public const REJECTED  = 'rejected';

    /*** @var mixed */
    public mixed $result;

    /*** @var string */
    protected string $status = Promise::PENDING;

    /*** @var Closure[] */
    protected array $onFulfilled = array();

    /*** @var Closure[] */
    protected array $onRejected = array();

    /*** @param Closure $closure */

    /**
     *
     * The created Promise instance will immediately execute the passed closure instead of pushing it to the next step in the queue.
     *
     * @param Closure $closure
     */
    public function __construct(Closure $closure)
    {
        $this->execute($closure);
    }

    /**
     * execute closure
     *
     * @param Closure $closure
     *
     * @return void
     */
    protected function execute(Closure $closure): void
    {
        try {
            call_user_func_array($closure, [
                fn (mixed $result = null) => $this->resolve($result),
                fn (mixed $result = null) => $this->reject($result),
                $this
            ]);
        } catch (Throwable $exception) {
            try {
                $this->reject($exception);
            } catch (Throwable $exception) {
                Output::warning($exception->getMessage());
            }
        }
    }

    /**
     * Change the promise status to completed and pass the result to subsequent actions,
     * The completed status cannot be changed, the second call will be ignored
     *
     * @param mixed $value
     *
     * @return void
     */
    public function resolve(mixed $value): void
    {
        if ($value instanceof Promise) {
            try {
                $this->resolve(await($value));
            } catch (Throwable $exception) {
                $this->reject($exception);
            }
            return;
        }

        if ($this->status !== Promise::PENDING) {
            return;
        }

        $this->status = Promise::FULFILLED;
        $this->result = $value;

        foreach (($this->onFulfilled) as $onFulfilled) {
            try {
                call_user_func($onFulfilled, $value);
            } catch (Throwable $exception) {
                Output::warning($exception->getMessage());
            }
        }
    }

    /**
     * Change the promise status to rejected and pass the reason to subsequent actions,
     * Unable to change rejected status, second call will be ignored
     *
     * @param Throwable $reason
     *
     * @return void
     */
    public function reject(mixed $reason): void
    {
        if ($this->status !== Promise::PENDING) {
            return;
        }

        $this->status = Promise::REJECTED;
        $this->result = $reason;
        foreach (($this->onRejected) as $onRejected) {
            try {
                call_user_func($onRejected, $reason);
            } catch (Throwable $reason) {
                Output::warning($reason->getMessage());
            }
        }
    }

    /**
     * This method returns a Promise object, which will only be triggered successfully when
     * all promise objects in the iterable parameter object are successful.
     *
     * @param Promise[] $promises
     *
     * @return \Ripple\Promise
     */
    public static function all(array $promises): Promise
    {
        return new Promise(static function (Closure $resolve, Closure $reject) use ($promises) {
            Promise::allSettled($promises)
                ->then(static function (array $results) use ($resolve, $reject) {
                    $values = [];
                    foreach ($results as $result) {
                        if ($result->getStatus() === Promise::FULFILLED) {
                            $values[] = $result->getResult();
                        } else {
                            $reject($result->getResult());
                            return;
                        }
                    }
                    $resolve($values);
                });
        });
    }

    /**
     * Define subsequent behavior. When the Promise state changes, it will be called in the order of the then method.
     * If the Promise has been completed, execute it immediately
     *
     * @param Closure|null $onFulfilled
     * @param Closure|null $onRejected
     *
     * @return $this
     */
    public function then(Closure|null $onFulfilled = null, Closure|null $onRejected = null): Promise
    {
        if ($onFulfilled) {
            if ($this->status === Promise::FULFILLED) {
                try {
                    call_user_func($onFulfilled, $this->result);
                } catch (Throwable $exception) {
                    Output::warning($exception->getMessage());
                }
                return $this;
            } else {
                $this->onFulfilled[] = $onFulfilled;
            }
        }

        if ($onRejected) {
            $this->except($onRejected);
        }
        return $this;
    }

    /**
     * Define the behavior after rejection. When the Promise state changes, it will be called in the order of the except method.
     * If the Promise has been rejected, execute it immediately
     *
     * @param Closure $onRejected
     *
     * @return $this
     */
    public function except(Closure $onRejected): Promise
    {
        if ($this->status === Promise::REJECTED) {
            try {
                call_user_func($onRejected, $this->result);
            } catch (Throwable $exception) {
                Output::warning($exception->getMessage());
            }
            return $this;
        } else {
            $this->onRejected[] = $onRejected;
        }
        return $this;
    }

    /**
     * This method is used to wait for all Promises to be settled, whether fulfilled or rejected.
     * The final callback function will receive an array containing all promises
     *
     * @param Promise[] $promises
     *
     * @return \Ripple\Promise
     */
    public static function allSettled(array $promises): Promise
    {
        return async(static function (Closure $resolve) use ($promises) {
            $waitGroup = new WaitGroup(count($promises));

            foreach ($promises as $promise) {
                $promise->then(
                    static fn () => $waitGroup->done(),
                    static fn () => $waitGroup->done()
                );
            }

            $waitGroup->wait();

            $resolve($promises);
        });
    }

    /**
     * This method is different from onReject, which allows accepting any type of rejected futures object.
     * When await promise is rejected, an error will be thrown instead of returning the rejected value.
     *
     * If the rejected value is a non-Error object, it will be wrapped into a `PromiseRejectException` object,
     * The `getReason` method of this object can obtain the rejected value
     *
     * @param bool $unwrap
     *
     * @return mixed
     * @throws Throwable
     */
    public function wait(bool $unwrap = true): mixed
    {
        return $this->await();
    }

    /**
     * This method is different from onReject, which allows accepting any type of rejected futures object.
     * When await promise is rejected, an error will be thrown instead of returning the rejected value.
     *
     * If the rejected value is a non-Error object, it will be wrapped into a `PromiseRejectException` object,
     * The `getReason` method of this object can obtain the rejected value
     *
     * @return mixed
     * @throws Throwable
     */
    public function await(): mixed
    {
        return await($this);
    }

    /**
     * @return string
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * @return mixed
     */
    public function getResult(): mixed
    {
        return $this->result;
    }

    /**
     * This new Promise will be completed when any of the
     * passed Promise is completed (whether successful or failed)
     *
     * @param Promise[] $promises
     *
     * @return Promise
     */
    public static function race(array $promises): Promise
    {
        return new Promise(static function (Closure $resolve) use ($promises) {
            foreach ($promises as $promise) {
                $promise->finally(static function () use ($promise, $resolve) {
                    $resolve($promise->getResult());
                });
            }
        });
    }

    /**
     * Define subsequent behavior. When the Promise state changes, it will be called in the order of the then method.
     *
     * @param Closure $onFinally
     *
     * @return $this
     */
    public function finally(Closure $onFinally): Promise
    {
        if ($this->status !== Promise::PENDING) {
            try {
                call_user_func($onFinally, $this->result);
            } catch (Throwable $exception) {
                Output::warning($exception->getMessage());
            }
            return $this;
        } else {
            $this->onFulfilled[] = $onFinally;
            $this->onRejected[]  = $onFinally;
        }

        return $this;
    }

    /**
     * When any of the incoming Promise succeeds, this new Promise will succeed.
     * If all Promises fail, the new Promise will fail with an AggregateError.
     *
     * @param Promise[] $promises
     */
    public static function any(array $promises): Promise
    {
        return async(static function (Closure $resolve, Closure $reject) use ($promises) {
            $waitGroup = new WaitGroup(count($promises));
            foreach ($promises as $item) {
                $item
                    ->then(static fn ($value) => $resolve($value))
                    ->finally(static fn () => $waitGroup->done());
            }

            $waitGroup->wait();
            $reject(new PromiseAggregateError('All promises were rejected'));
        });
    }

    /**
     * @param array $promises
     *
     * @return \Ripple\Futures
     */
    public static function futures(array $promises): Futures
    {
        return new Futures($promises);
    }

    /**
     * Define the behavior after rejection. When the Promise state changes, it will be called in the order of the catch method.
     * If the Promise has been rejected, execute it immediately
     *
     * @param Closure $onRejected
     *
     * @return $this
     * @deprecated You should use the except method because this method is a reserved keyword
     */
    public function catch(Closure $onRejected): Promise
    {
        return $this->except($onRejected);
    }

    /**
     * @return string
     */
    public function getState(): string
    {
        return $this->status;
    }

    /**
     * @return void
     * @throws Exception
     */
    public function cancel(): void
    {
        // TODO: Implement cancel() method.
        throw new Exception('Method not implemented');
    }

    /**
     * @param Closure $onRejected
     *
     * @return Promise
     */
    public function otherwise(Closure $onRejected): Promise
    {
        return $this->except($onRejected);
    }
}
