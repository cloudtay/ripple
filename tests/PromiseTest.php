<?php declare(strict_types=1);

namespace Tests;

use Closure;
use Exception;
use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Ripple\Coroutine\Exception\PromiseAggregateError;
use Ripple\Coroutine\Futures;
use Ripple\Coroutine\Promise;

use function Co\async;
use function Co\wait;
use function in_array;

#[RunClassInSeparateProcess]
class PromiseTest extends TestCase
{
    /**
     * @return void
     */
    public function testAnySuccess()
    {
        $promise1 = async(function (Closure $resolve) {
            \Co\sleep(0.1);
            $resolve("Result from promise 1");
        });

        $promise2 = async(function (Closure $resolve) {
            \Co\sleep(0.2);
            $resolve("Result from promise 2");
        });

        $promise3 = async(function (Closure $resolve, Closure $reject) {
            \Co\sleep(0.1);
            $reject(new Exception("Error from promise 3"));
        });

        $resultPromise = Promise::any([$promise1, $promise2, $promise3]);

        $resultPromise->then(function (mixed $result) use ($promise1, $promise2) {
            $this->assertTrue(in_array($result, ["Result from promise 1", "Result from promise 2"]));
        })->except(function (mixed $error) {
            $this->fail("The promise should not have been rejected.");
        });

        wait();

        $this->assertTrue(true);
    }

    /**
     * @return void
     */
    public function testAnyAllRejected()
    {
        $promise1 = async(function (Closure $resolve, Closure $reject) {
            \Co\sleep(0.1);
            $reject(new Exception("Error from promise 1"));
        });

        $promise2 = async(function (Closure $resolve, Closure $reject) {
            \Co\sleep(0.1);
            $reject(new Exception("Error from promise 2"));
        });

        $promise3 = async(function (Closure $resolve, Closure $reject) {
            \Co\sleep(0.1);
            $reject(new Exception("Error from promise 3"));
        });

        $resultPromise = Promise::any([$promise1, $promise2, $promise3]);

        $resultPromise->then(function (mixed $result) {
            $this->fail("The promise should have been rejected.");
        })->except(function (mixed $error) {
            $this->assertInstanceOf(PromiseAggregateError::class, $error);
            $this->assertCount(3, $error->getErrors());
        });

        wait();

        $this->assertTrue(true);
    }

    /**
     * @return void
     */
    public function testAnyMixedPromises()
    {
        $promise1 = async(function (Closure $resolve, Closure $reject) {
            \Co\sleep(0.1);
            $reject(new Exception("Error from promise 1"));
        });

        $promise2 = async(function (Closure $resolve) {
            \Co\sleep(0.2);
            $resolve("Result from promise 2");
        });

        $promise3 = async(function (Closure $resolve, Closure $reject) {
            \Co\sleep(0.1);
            $reject(new Exception("Error from promise 3"));
        });

        $resultPromise = Promise::any([$promise1, $promise2, $promise3]);

        $resultPromise->then(function (mixed $result) {
            $this->assertEquals("Result from promise 2", $result);
        })->except(function (mixed $error) {
            $this->fail("The promise should not have been rejected.");
        });

        wait();

        $this->assertTrue(true);
    }

    /**
     * @return void
     */
    public function testAllSuccess()
    {
        $promise1 = async(function (Closure $resolve) {
            \Co\sleep(0.1);
            $resolve("Result 1");
        });

        $promise2 = async(function (Closure $resolve) {
            \Co\sleep(0.2);
            $resolve("Result 2");
        });

        $resultPromise = Promise::all([$promise1, $promise2]);

        $resultPromise->then(function (mixed $results) {
            $this->assertEquals(["Result 1", "Result 2"], $results);
        })->except(function (mixed $error) {
            $this->fail("The promise should not have been rejected.");
        });

        wait();

        $this->assertTrue(true);
    }

    /**
     * @return void
     */
    public function testAllWithRejection()
    {
        $promise1 = async(function (Closure $resolve) {
            \Co\sleep(0.1);
            $resolve("Result 1");
        });

        $promise2 = async(function (Closure $resolve, Closure $reject) {
            \Co\sleep(0.2);
            $reject(new Exception("Error from promise 2"));
        });

        $resultPromise = Promise::all([$promise1, $promise2]);

        $resultPromise->then(function (mixed $results) {
            $this->fail("The promise should have been rejected.");
        })->except(function (mixed $error) {
            $this->assertInstanceOf(Exception::class, $error);
            $this->assertEquals("Error from promise 2", $error->getMessage());
        });

        wait();

        $this->assertTrue(true);
    }

    /**
     * @return void
     */
    public function testAllSettled()
    {
        $promise1 = async(function (Closure $resolve) {
            \Co\sleep(0.1);
            $resolve("Result 1");
        });

        $promise2 = async(function (Closure $resolve, Closure $reject) {
            \Co\sleep(0.2);
            $reject(new Exception("Error from promise 2"));
        });

        $resultPromise = Promise::allSettled([$promise1, $promise2]);

        $resultPromise->then(function (mixed $results) {
            $this->assertCount(2, $results);
            $this->assertEquals("Result 1", $results[0]->getResult());
            $this->assertEquals("Error from promise 2", $results[1]->getResult()->getMessage());
        });

        wait();

        $this->assertTrue(true);
    }

    /**
     * @return void
     */
    public function testRace()
    {
        $promise1 = async(function (Closure $resolve) {
            \Co\sleep(0.1);
            $resolve("Result from promise 1");
        });

        $promise2 = async(function (Closure $resolve) {
            \Co\sleep(0.2);
            $resolve("Result from promise 2");
        });

        $resultPromise = Promise::race([$promise1, $promise2]);

        $resultPromise->then(function (mixed $result) {
            $this->assertEquals("Result from promise 1", $result);
        })->except(function (mixed $error) {
            $this->fail("The promise should not have been rejected.");
        });

        wait();
    }

    /**
     * @return void
     */
    public function testFutures()
    {
        $promise1 = async(function (Closure $resolve) {
            \Co\sleep(0.1);
            $resolve("Result 1");
        });

        $promise2 = async(function (Closure $resolve) {
            \Co\sleep(0.2);
            $resolve("Result 2");
        });

        $futures = Promise::futures([$promise1, $promise2]);

        wait();

        $this->assertInstanceOf(Futures::class, $futures);
    }
}
