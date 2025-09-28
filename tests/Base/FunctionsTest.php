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
use Ripple\Promise\Exception\RejectException;
use Ripple\Time;
use RuntimeException;
use Throwable;

use function Co\async;
use function Co\await;
use function microtime;

class FunctionsTest extends TestCase
{
    #[TestDox("async函数")]
    public function testAsyncFunction(): void
    {
        $result = null;
        $promise = async(function () use (&$result) {
            $result = 'async_result';
            return 'return_value';
        });

        $this->assertEquals(Promise::FULFILLED, $promise->getStatus());
        $this->assertEquals('return_value', $promise->getResult());
        $this->assertEquals('async_result', $result);
    }

    #[TestDox("async函数异常处理")]
    public function testAsyncFunctionWithException(): void
    {
        $promise = async(function () {
            throw new Exception('async exception');
        });

        $this->assertEquals(Promise::REJECTED, $promise->getStatus());
        $this->assertInstanceOf(Exception::class, $promise->getResult());
        $this->assertEquals('async exception', $promise->getResult()->getMessage());
    }

    /**
     * @throws Throwable
     */
    #[TestDox("await函数 - 成功情况")]
    public function testAwaitFunctionSuccess(): void
    {
        $promise = new Promise(function ($resolve) {
            $resolve('success_value');
        });

        $result = await($promise);
        $this->assertEquals('success_value', $result);
    }

    /**
     * @throws Throwable
     */
    #[TestDox("await函数 - 失败情况")]
    public function testAwaitFunctionFailure(): void
    {
        $promise = new Promise(function ($resolve, $reject) {
            $reject('error_value');
        });

        $this->expectException(RejectException::class);
        await($promise);
    }

    /**
     * @throws Throwable
     */
    #[TestDox("await函数 - 异常情况")]
    public function testAwaitFunctionException(): void
    {
        $promise = new Promise(function ($resolve, $reject) {
            $reject(new Exception('test exception'));
        });

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('test exception');
        await($promise);
    }

    /**
     * @throws Throwable
     */
    #[TestDox("await函数 - 嵌套Promise")]
    public function testAwaitFunctionNestedPromise(): void
    {
        $innerPromise = new Promise(function ($resolve) {
            $resolve('inner_value');
        });

        $outerPromise = new Promise(function ($resolve) use ($innerPromise) {
            $resolve($innerPromise);
        });

        $result = await($outerPromise);
        $this->assertEquals('inner_value', $result);
    }

    /**
     * @throws Throwable
     * @throws Throwable
     */
    #[TestDox("await函数 - 已完成的Promise")]
    public function testAwaitFunctionAlreadyFulfilled(): void
    {
        $promise = new Promise(function ($resolve) {
            $resolve('immediate_value');
        });

        $promise->await();

        $result = await($promise);
        $this->assertEquals('immediate_value', $result);
    }

    /**
     * @throws Throwable
     * @throws Throwable
     */
    #[TestDox("await函数 - 已拒绝的Promise")]
    public function testAwaitFunctionAlreadyRejected(): void
    {
        $promise = new Promise(function ($resolve, $reject) {
            $reject('immediate_error');
        });

        try {
            $promise->await();
        } catch (RejectException $e) {
        }

        $this->expectException(RejectException::class);
        await($promise);
    }

    /**
     * @throws Throwable
     * @throws Throwable
     */
    #[TestDox("async和await组合使用")]
    public function testAsyncAwaitCombination(): void
    {
        $promise1 = async(function () {
            return 'first';
        });

        $promise2 = async(function () {
            return 'second';
        });

        $result1 = await($promise1);
        $result2 = await($promise2);

        $this->assertEquals('first', $result1);
        $this->assertEquals('second', $result2);
    }

    /**
     * @throws Throwable
     */
    #[TestDox("async函数返回Promise")]
    public function testAsyncFunctionReturningPromise(): void
    {
        $promise = async(function () {
            return new Promise(function ($resolve) {
                $resolve('promise_result');
            });
        });

        $result = await($promise);
        $this->assertEquals('promise_result', $result);
    }

    /**
     * @throws Throwable
     */
    #[TestDox("async函数返回已完成的Promise")]
    public function testAsyncFunctionReturningFulfilledPromise(): void
    {
        $completedPromise = new Promise(function ($resolve) {
            $resolve('completed');
        });

        $promise = async(function () use ($completedPromise) {
            return $completedPromise;
        });

        $result = await($promise);
        $this->assertEquals('completed', $result);
    }

    /**
     * @throws Throwable
     */
    #[TestDox("async函数返回已拒绝的Promise")]
    public function testAsyncFunctionReturningRejectedPromise(): void
    {
        $rejectedPromise = new Promise(function ($resolve, $reject) {
            $reject('rejected');
        });

        $promise = async(function () use ($rejectedPromise) {
            return $rejectedPromise;
        });

        $this->expectException(RejectException::class);
        await($promise);
    }

    /**
     * @throws Throwable
     */
    #[TestDox("复杂的数据类型")]
    public function testAsyncFunctionWithComplexData(): void
    {
        $complexData = [
            'string' => 'test',
            'integer' => 123,
            'float' => 45.67,
            'boolean' => true,
            'array' => [1, 2, 3],
            'object' => (object) ['key' => 'value'],
            'null' => null
        ];

        $promise = async(function () use ($complexData) {
            return $complexData;
        });

        $result = await($promise);
        $this->assertEquals($complexData, $result);
    }

    /**
     * @throws Throwable
     */
    #[TestDox("异步函数中的异常传播")]
    public function testAsyncFunctionExceptionPropagation(): void
    {
        $promise = async(function () {
            throw new RuntimeException('runtime error');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('runtime error');
        await($promise);
    }

    /**
     * @throws Throwable
     */
    #[TestDox("await函数处理非Promise值")]
    public function testAwaitFunctionWithNonPromise(): void
    {
        $promise = new Promise(function ($resolve) {
            $resolve('direct_value');
        });

        $result = await($promise);
        $this->assertEquals('direct_value', $result);
    }

    /**
     * @throws Throwable
     */
    #[TestDox("并发async函数")]
    public function testConcurrentAsyncFunctions(): void
    {
        $promises = [];

        for ($i = 0; $i < 5; $i++) {
            $promises[] = async(function () use ($i) {
                return "result_$i";
            });
        }

        $results = [];
        foreach ($promises as $promise) {
            $results[] = await($promise);
        }

        $this->assertCount(5, $results);
        for ($i = 0; $i < 5; $i++) {
            $this->assertEquals("result_$i", $results[$i]);
        }
    }

    /**
     * @return void
     * @throws Throwable
     */
    #[TestDox("async函数中的延迟执行")]
    public function testAsyncFunctionWithDelay(): void
    {
        $startTime = microtime(true);

        $promise = async(function () {
            Time::sleep(0.1);
            return 'delayed_result';
        });

        $result = await($promise);
        $endTime = microtime(true);

        $this->assertEquals('delayed_result', $result);
        $this->assertGreaterThan(0.01, $endTime - $startTime);
    }
}
