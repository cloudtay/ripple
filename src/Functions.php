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

namespace Co;

use Closure;
use Ripple\Promise\Exception\RejectException;
use Ripple\Promise;
use Ripple\Runtime\Scheduler;
use Throwable;

function async(Closure $closure): Promise
{
    return new Promise(static function (Closure $resolve, Closure $reject) use ($closure) {
        try {
            $result = $closure($resolve, $reject);
            $resolve($result);
        } catch (Throwable $exception) {
            $reject($exception);
        }
    });
}

/**
 * This method is different from onReject, which allows accepting any type of rejected futures object.
 * When await promise is rejected, an error will be thrown instead of returning the rejected value.
 *
 * If the rejected value is a non-Error object, it will be wrapped into a `PromiseRejectException` object,
 * The `getReason` method of this object can obtain the rejected value
 *
 * @param Promise $promise
 *
 * @return mixed
 * @throws Throwable
 */
function await(Promise $promise): mixed
{
    if ($promise->getStatus() === Promise::FULFILLED) {
        $result = $promise->getResult();
        if ($result instanceof Promise) {
            return await($result);
        }
        return $result;
    }

    if ($promise->getStatus() === Promise::REJECTED) {
        if ($promise->getResult() instanceof Throwable) {
            throw $promise->getResult();
        } else {
            throw new RejectException($promise->getResult());
        }
    }

    $owner = \Co\current();

    /**
     * To determine your own control over preparing Fiber, you must be responsible for the subsequent status of Fiber.
     */
    // When the status of the awaited Promise is completed
    $promise
        ->then(static fn (mixed $result) => Scheduler::resume($owner, $result))
        ->except(static function (mixed $result) use ($owner) {
            $result instanceof Throwable
                ? Scheduler::throw($owner, $result)
                : Scheduler::throw($owner, new RejectException($result));
        });

    // Confirm that you have prepared to handle Fiber recovery and take over control of Fiber by suspending it
    $result = $owner->suspend();
    if ($result instanceof Promise) {
        return await($result);
    }
    return $result;
}
