<?php declare(strict_types=1);

namespace Tests;

use Co\System;
use PHPUnit\Framework\TestCase;
use Throwable;

use function Co\async;
use function Co\await;
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
                \Co\sleep(1);
                exit($code);
            });
            $runtime = $task->run();
            return await($runtime->getPromise());
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
            $future->onValue(function ($value) {
                exit($value);
            });
        });
        $runtime = $task->run();
        $runtime->finally(function ($exitCode) use ($code) {
            $this->assertEquals($code, $exitCode, 'Process exit code');
        });
        $runtime->await();
    }
}
