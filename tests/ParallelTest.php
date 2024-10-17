<?php declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Ripple\Kernel;
use Ripple\Parallel\Context;
use Throwable;

use function Co\thread;
use function Co\wait;
use function mt_rand;

#[RunClassInSeparateProcess]
class ParallelTest extends TestCase
{
    /**
     * @return void
     * @throws Throwable
     */
    public function test_process(): void
    {
        if (!Kernel::getInstance()->supportParallel()) {
            $this->markTestSkipped('Not support parallel');
        }

        $code   = mt_rand(0, 255);
        $future = thread(static function (Context $context) {
            return $context->argv[0];
        })->run($code);
        $future->onValue(function (int $value) use ($code) {
            $this->assertEquals($code, $value);
        });
        wait();
    }
}
