<?php declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

use function Co\async;
use function Co\cancelAll;
use function Co\channel;
use function Co\wait;
use function is_string;
use function str_contains;

class CoroutineTest extends TestCase
{
    /**
     * @return void
     * @throws Throwable
     */
    public function test_coroutineStability(): void
    {
        $concurrentCoroutines = 200;
        $channel = channel('coroutine');

        $coroutines           = [];
        for ($i = 0; $i < $concurrentCoroutines; $i++) {
            $coroutines[] = async(function () use ($channel, $i) {
                \Co\sleep(0.1);
                try {
                    $result = $this->simulateWork($i);
                    $channel->send($result);
                } catch (Throwable $exception) {
                    $channel->send($exception->getMessage());
                }
            });
        }

        foreach ($coroutines as $coroutine) {
            $coroutine->await();
        }

        $successCount = 0;
        $failureCount = 0;
        for ($i = 0; $i < $concurrentCoroutines; $i++) {
            $result = $channel->receive(false);
            if (is_string($result) && str_contains($result, 'Error')) {
                $failureCount++;
            } else {
                $successCount++;
            }
        }

        $this->assertEquals($concurrentCoroutines, $successCount, "Expected all coroutines to complete successfully.");
        $this->assertEquals(0, $failureCount, "Expected no coroutines to fail.");

        cancelAll();
        wait();
    }

    /**
     * @param int $index
     *
     * @return int
     * @throws Throwable
     */
    private function simulateWork(int $index): int
    {
        if ($index % 100 === 0) {
            throw new RuntimeException("Simulated error in coroutine $index");
        }
        return $index * 2;
    }
}
