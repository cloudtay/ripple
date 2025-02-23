<?php declare(strict_types=1);

namespace Tests\Coroutine;

use PHPUnit\Framework\TestCase;
use Ripple\Coroutine\Context;
use Throwable;
use Exception;

use function Co\async;
use function Co\wait;
use function microtime;

class CoreTest extends TestCase
{
    /**
     * @return void
     */
    public function testAsyncExecution(): void
    {
        $result  = null;
        $promise = async(function () {
            return "async result";
        });

        $promise->then(function ($value) use (&$result) {
            $result = $value;
        });

        wait();
        $this->assertEquals("async result", $result);
    }

    /**
     * @return void
     * @throws Throwable
     */
    public function testErrorPropagation(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Test error");

        async(function () {
            throw new Exception("Test error");
        })->await();
    }

    /**
     * @return void
     * @throws Throwable
     */
    public function testSleep(): void
    {
        $startTime = microtime(true);

        async(function () {
            \Co\sleep(0.1);
        })->await();

        $duration = microtime(true) - $startTime;
        $this->assertGreaterThanOrEqual(0.1, $duration);
    }

    /**
     * @return void
     */
    public function testContextIsolation(): void
    {
        $context1 = null;
        $context2 = null;

        async(function () use (&$context1) {
            Context::setValue('key', 'value1');
            $context1 = Context::getValue('key');
        });

        async(function () use (&$context2) {
            Context::setValue('key', 'value2');
            $context2 = Context::getValue('key');
        });

        wait();

        $this->assertEquals('value1', $context1);
        $this->assertEquals('value2', $context2);
        $this->assertNotEquals($context1, $context2);
    }

    /**
     * @return void
     * @throws Throwable
     */
    public function testPromiseRejection(): void
    {
        $this->expectException(Throwable::class);

        async(function () {
            return async(function ($resolve, $reject) {
                $reject(new Exception("Rejected"));
            });
        })->await();
    }

    /**
     * @return void
     */
    public function testConcurrentExecution(): void
    {
        $results  = [];
        $promises = [];

        // 创建多个并发协程
        for ($i = 0; $i < 5; $i++) {
            $promises[] = async(function () use ($i) {
                \Co\sleep(0.1);
                return "result_" . $i;
            })->then(function ($result) use (&$results) {
                $results[] = $result;
            });
        }

        // 等待所有协程完成
        foreach ($promises as $promise) {
            $promise->await();
        }

        $this->assertCount(5, $results);
        $this->assertEquals([
            "result_0",
            "result_1",
            "result_2",
            "result_3",
            "result_4"
        ], $results);
    }

    /**
     * @return void
     * @throws Throwable
     */
    public function testCoroutineTermination(): void
    {
        $terminated = false;

        $suspension = \Co\go(function () {
            while (true) {
                \Co\sleep(0.1);
            }
        });

        async(function () use ($suspension, &$terminated) {
            \Co\sleep(0.2);
            $suspension->terminate();
            $terminated = true;
        })->await();

        $this->assertTrue($terminated);
    }

    /**
     * @return void
     */
    public function testNestedCoroutines(): void
    {
        $result = null;

        async(function () use (&$result) {
            return async(function () {
                \Co\sleep(0.1);
                return "inner result";
            })->await();
        })->then(function ($value) use (&$result) {
            $result = $value;
        });

        wait();

        $this->assertEquals("inner result", $result);
    }
}
