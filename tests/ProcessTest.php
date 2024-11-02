<?php declare(strict_types=1);

namespace Tests;

use Co\System;
use PHPUnit\Framework\TestCase;
use Ripple\Process\Exception\ProcessException;
use Throwable;

use function Co\async;
use function Co\thread;
use function mt_rand;
use function sleep;

class ProcessTest extends TestCase
{
    /**
     * @return void
     * @throws Throwable
     */
    public function test_process(): void
    {
        $code = mt_rand(0, 255);
        $task = System::Process()->task(function () use ($code) {
            \Co\sleep(1);
            exit($code);
        });

        $runtime  = $task->run();
        $exitCode = $runtime->await();
        $this->assertEquals($code, $exitCode, 'Process exit code');
    }

    /**
     * @return void
     * @throws Throwable
     */
    public function test_coroutine(): void
    {
        $code     = mt_rand(0, 255);
        $async    = async(function () use ($code) {
            $task    = System::Process()->task(function () use ($code) {
                \Co\sleep(0.1);
                exit($code);
            });
            $runtime = $task->run();
            return $runtime->getPromise()->await();
        });
        $exitCode = $async->await();
        $this->assertEquals($code, $exitCode, 'Process exit code');
    }

    /**
     * @return void
     * @throws Throwable
     */
    public function test_parallel(): void
    {
        $code    = mt_rand(0, 255);
        $task    = System::Process()->task(function () use ($code) {
            $thread = thread(static function ($context) {
                sleep($context->argv[0]);
                return $context->argv[1];
            });
            $future = $thread->run(1, $code);
            $future->onValue(function ($value = null) {
                exit($value);
            });
        });
        $runtime = $task->run();
        $runtime->then(function ($exitCode) use ($code) {
            $this->assertEquals($code, $exitCode, 'Process exit code');
        });

        $runtime->except(function (ProcessException $exception) {
            $this->fail($exception->getMessage());
        });
        $runtime->await();
    }
}
