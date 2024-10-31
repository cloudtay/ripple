<?php declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Ripple\Coroutine;
use Ripple\Stream\Exception\Exception;
use Throwable;

use function Co\async;
use function Co\delay;
use function Co\getSuspension;
use function Co\wait;

class CoroutineException extends TestCase
{
    /**
     * @return void
     * @throws Throwable
     */
    public function test_exception(): void
    {
        $i = 0;

        async(static function () {
            $suspension = getSuspension();
            delay(static fn () => Coroutine::throw($suspension, new Exception()), 1);
            Coroutine::suspend($suspension);
        })->except(static function (Throwable $e) use (&$i) {
            $i++;
        });


        async(static function () {
            throw new Exception();
        })->except(static function (Throwable $e) use (&$i) {
            $i++;
        });


        async(static function () {
            $suspension = getSuspension();
            delay(function () use ($suspension) {
                try {
                    Coroutine::resume($suspension, new Exception());
                } catch (Throwable) {
                    $this->fail();
                }
            }, 1);
            Coroutine::suspend($suspension);
            throw new Exception();
        })->except(static function (Throwable $e) use (&$i) {
            $i++;
        });

        wait();

        $this->assertEquals(3, $i);
    }
}
