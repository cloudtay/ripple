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

namespace Base;

use Exception;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Ripple\Promise;
use Ripple\Promise\Exception\AggregateError;
use Ripple\Promise\Exception\RejectException;
use Throwable;

use function Co\go;
use function Co\wait;

class PromiseTest extends TestCase
{
    #[TestDox("Promise基本创建和状态")]
    public function testPromiseCreation(): void
    {
        $promise = new Promise(function ($resolve) {
            $resolve('success');
        });

        $this->assertEquals(Promise::FULFILLED, $promise->getStatus());
        $this->assertEquals('success', $promise->getResult());
    }

    #[TestDox("Promise拒绝状态")]
    public function testPromiseRejection(): void
    {
        $promise = new Promise(function ($resolve, $reject) {
            $reject('error');
        });

        $this->assertEquals(Promise::REJECTED, $promise->getStatus());
        $this->assertEquals('error', $promise->getResult());
    }

    #[TestDox("Promise异常处理")]
    public function testPromiseExceptionHandling(): void
    {
        $promise = new Promise(function () {
            throw new Exception('test exception');
        });

        $this->assertEquals(Promise::REJECTED, $promise->getStatus());
        $this->assertInstanceOf(Exception::class, $promise->getResult());
    }

    #[TestDox("then方法")]
    public function testThenMethod(): void
    {
        $result = null;
        $promise = new Promise(function ($resolve) {
            $resolve('initial');
        });

        $promise->then(function ($value) use (&$result) {
            $result = $value . '_processed';
        });

        $this->assertEquals('initial_processed', $result);
    }

    #[TestDox("except方法")]
    public function testExceptMethod(): void
    {
        $error = null;
        $promise = new Promise(function ($resolve, $reject) {
            $reject('test error');
        });

        $promise->except(function ($reason) use (&$error) {
            $error = $reason;
        });

        $this->assertEquals('test error', $error);
    }

    #[TestDox("finally方法")]
    public function testFinallyMethod(): void
    {
        $finallyCalled = false;
        $promise = new Promise(function ($resolve) {
            $resolve('success');
        });

        $promise->finally(function () use (&$finallyCalled) {
            $finallyCalled = true;
        });

        $this->assertTrue($finallyCalled);
    }

    /**
     * @throws Throwable
     */
    #[TestDox("Promise::all方法")]
    public function testPromiseAll(): void
    {
        $promises = [
            new Promise(function ($resolve) {
                $resolve('first');
            }),
            new Promise(function ($resolve) {
                $resolve('second');
            })
        ];

        $allPromise = Promise::all($promises);
        $results = $allPromise->await();

        $this->assertEquals(['first', 'second'], $results);
    }

    /**
     * @throws Throwable
     */
    #[TestDox("Promise::all方法失败情况")]
    public function testPromiseAllWithRejection(): void
    {
        $promises = [
            new Promise(function ($resolve) {
                $resolve('success');
            }),
            new Promise(function ($resolve, $reject) {
                $reject('error');
            })
        ];

        $allPromise = Promise::all($promises);

        $this->expectException(RejectException::class);
        $allPromise->await();
    }

    /**
     * @throws Throwable
     */
    #[TestDox("Promise::allSettled方法")]
    public function testPromiseAllSettled(): void
    {
        $promises = [
            new Promise(function ($resolve) {
                $resolve('success');
            }),
            new Promise(function ($resolve, $reject) {
                $reject('error');
            })
        ];

        $allSettledPromise = Promise::allSettled($promises);
        $results = $allSettledPromise->await();

        $this->assertCount(2, $results);
        $this->assertEquals(Promise::FULFILLED, $results[0]->getStatus());
        $this->assertEquals(Promise::REJECTED, $results[1]->getStatus());
    }

    #[TestDox("Promise::race方法")]
    public function testPromiseRace(): void
    {
        go(function () use (&$result) {
            $promises = [
                new Promise(function ($resolve) {
                    \Co\sleep(1);
                    $resolve('slow');
                }),
                new Promise(function ($resolve) {
                    $resolve('fast');
                })
            ];

            $racePromise = Promise::race($promises);
            $result = $racePromise->await();
        });

        wait();

        $this->assertEquals('fast', $result);
    }

    /**
     * @throws Throwable
     */
    #[TestDox("Promise::any方法")]
    public function testPromiseAny(): void
    {
        $promises = [
            new Promise(function ($resolve, $reject) {
                $reject('error1');
            }),
            new Promise(function ($resolve) {
                $resolve('success');
            })
        ];

        $anyPromise = Promise::any($promises);
        $result = $anyPromise->await();

        $this->assertEquals('success', $result);
    }

    /**
     * @throws Throwable
     */
    #[TestDox("Promise::any方法全部失败")]
    public function testPromiseAnyAllRejected(): void
    {
        $promises = [
            new Promise(function ($resolve, $reject) {
                $reject('error1');
            }),
            new Promise(function ($resolve, $reject) {
                $reject('error2');
            })
        ];

        $anyPromise = Promise::any($promises);

        $this->expectException(AggregateError::class);
        $anyPromise->await();
    }

    /**
     * @throws Throwable
     */
    #[TestDox("Promise嵌套")]
    public function testNestedPromise(): void
    {
        $innerPromise = new Promise(function ($resolve) {
            $resolve('inner');
        });

        $outerPromise = new Promise(function ($resolve) use ($innerPromise) {
            $resolve($innerPromise);
        });

        $result = $outerPromise->await();
        $this->assertEquals('inner', $result);
    }

    #[TestDox("状态常量")]
    public function testPromiseConstants(): void
    {
        $this->assertEquals('pending', Promise::PENDING);
        $this->assertEquals('fulfilled', Promise::FULFILLED);
        $this->assertEquals('rejected', Promise::REJECTED);
    }

    #[TestDox("多次resolve/reject调用")]
    public function testMultipleResolveReject(): void
    {
        $promise = new Promise(function ($resolve, $reject) {
            $resolve('first');
            $resolve('second');
            $reject('error');
        });

        $this->assertEquals(Promise::FULFILLED, $promise->getStatus());
        $this->assertEquals('first', $promise->getResult());
    }

    #[TestDox("catch方法（已废弃但应该工作）")]
    public function testCatchMethod(): void
    {
        $error = null;
        $promise = new Promise(function ($resolve, $reject) {
            $reject('test error');
        });

        $promise->catch(function ($reason) use (&$error) {
            $error = $reason;
        });

        $this->assertEquals('test error', $error);
    }

    #[TestDox("otherwise方法")]
    public function testOtherwiseMethod(): void
    {
        $error = null;
        $promise = new Promise(function ($resolve, $reject) {
            $reject('test error');
        });

        $promise->otherwise(function ($reason) use (&$error) {
            $error = $reason;
        });

        $this->assertEquals('test error', $error);
    }
}
