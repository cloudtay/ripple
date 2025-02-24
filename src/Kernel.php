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

namespace Ripple;

use Closure;
use Fiber;
use Revolt\EventLoop;
use Revolt\EventLoop\UnsupportedFeatureException;
use Ripple\Coroutine\Context;
use Ripple\Coroutine\Coroutine;
use Ripple\Coroutine\SuspensionProxy;
use Ripple\Process\Process;
use Throwable;

use function call_user_func;
use function Co\async;
use function Co\getContext;
use function Co\wait;
use function define;
use function defined;
use function extension_loaded;
use function fopen;
use function ini_set;
use function getmygid;
use function posix_getpid;

/**
 * @Author cclilshy
 * @Date   2024/8/29 23:28
 */
class Kernel
{
    /*** @var Kernel */
    public static Kernel $instance;

    /*** @var \Ripple\Coroutine\Context */
    private Context $mainContext;

    /*** @var bool */
    private bool $parallel;

    /*** @var bool */
    private bool $processControl;

    /*** @var bool */
    private bool $mainRunning = true;

    public function __construct()
    {
        ini_set('memory_limit', -1);
        ini_set('max_execution_time', 0);

        $this->parallel       = extension_loaded('parallel');
        $this->processControl = extension_loaded('pcntl') && extension_loaded('posix');
        $this->defineConstants();
    }

    /**
     * @return Kernel
     */
    public static function getInstance(): Kernel
    {
        if (!isset(Kernel::$instance)) {
            Kernel::$instance = new self();
        }
        return Kernel::$instance;
    }

    /**
     * @return void
     */
    private function defineConstants(): void
    {
        defined('STDIN') || define('STDIN', fopen('php://stdin', 'r'));
        defined('STDOUT') || define('STDOUT', fopen('php://stdout', 'w'));

        if (!$this->processControl) {
            /**
             * @see https://www.php.net/manual/en/pcntl.constants.php
             */
            defined('WNOHANG') || define('WNOHANG', 1);
            defined('WUNTRACED') || define('WUNTRACED', 2);
            defined('WCONTINUED') || define('WCONTINUED', 8);
            defined('SIG_IGN') || define('SIG_IGN', 1);
            defined('SIG_DFL') || define('SIG_DFL', 0);
            defined('SIG_ERR') || define('SIG_ERR', -1);
            defined('SIGHUP') || define('SIGHUP', 1);
            defined('SIGINT') || define('SIGINT', 2);
            defined('SIGQUIT') || define('SIGQUIT', 3);
            defined('SIGILL') || define('SIGILL', 4);
            defined('SIGTRAP') || define('SIGTRAP', 5);
            defined('SIGABRT') || define('SIGABRT', 6);
            defined('SIGIOT') || define('SIGIOT', 6);
            defined('SIGBUS') || define('SIGBUS', 7);
            defined('SIGFPE') || define('SIGFPE', 8);
            defined('SIGKILL') || define('SIGKILL', 9);
            defined('SIGUSR1') || define('SIGUSR1', 10);
            defined('SIGSEGV') || define('SIGSEGV', 11);
            defined('SIGUSR2') || define('SIGUSR2', 12);
            defined('SIGPIPE') || define('SIGPIPE', 13);
            defined('SIGALRM') || define('SIGALRM', 14);
            defined('SIGTERM') || define('SIGTERM', 15);
            defined('SIGSTKFLT') || define('SIGSTKFLT', 16);
            defined('SIGCLD') || define('SIGCLD', 17);
            defined('SIGCHLD') || define('SIGCHLD', 17);
            defined('SIGCONT') || define('SIGCONT', 18);
            defined('SIGSTOP') || define('SIGSTOP', 19);
            defined('SIGTSTP') || define('SIGTSTP', 20);
            defined('SIGTTIN') || define('SIGTTIN', 21);
            defined('SIGTTOU') || define('SIGTTOU', 22);
            defined('SIGURG') || define('SIGURG', 23);
            defined('SIGXCPU') || define('SIGXCPU', 24);
            defined('SIGXFSZ') || define('SIGXFSZ', 25);
            defined('SIGVTALRM') || define('SIGVTALRM', 26);
            defined('SIGPROF') || define('SIGPROF', 27);
            defined('SIGWINCH') || define('SIGWINCH', 28);
            defined('SIGPOLL') || define('SIGPOLL', 29);
            defined('SIGIO') || define('SIGIO', 29);
            defined('SIGPWR') || define('SIGPWR', 30);
            defined('SIGSYS') || define('SIGSYS', 31);
            defined('SIGBABY') || define('SIGBABY', 31);
            defined('PRIO_PGRP') || define('PRIO_PGRP', 1);
            defined('PRIO_USER') || define('PRIO_USER', 2);
            defined('PRIO_PROCESS') || define('PRIO_PROCESS', 0);
        }
    }

    /**
     * @param Closure(Closure):void $closure
     * @param int|float             $second
     *
     * @return string
     */
    public function repeat(Closure $closure, int|float $second): string
    {
        return EventLoop::repeat($second, function (string $cancelID) use ($closure) {
            call_user_func($closure, fn () => EventLoop::cancel($cancelID));
        });
    }

    /**
     * @param int     $signal
     * @param Closure $closure
     *
     * @return string
     * @throws UnsupportedFeatureException
     */
    public function onSignal(int $signal, Closure $closure): string
    {
        return Process::getInstance()->onSignal($signal, $closure);
    }

    /**
     * @param Closure $closure
     *
     * @return void
     */
    public function defer(Closure $closure): void
    {
        $context = getContext();
        if ($context instanceof SuspensionProxy) {
            EventLoop::queue(static fn () => async($closure));
            return;
        }

        /*** compatibility */
        $context instanceof Context && $context->defer($closure);
    }

    /**
     * @param Closure $closure
     *
     * @return string
     */
    public function forked(Closure $closure): string
    {
        return Process::getInstance()->forked($closure);
    }

    /**
     * @param string $index
     *
     * @return void
     */
    public function cancelForked(string $index): void
    {
        Process::getInstance()->cancelForked($index);
    }

    /**
     * @param Closure|null $result
     *
     * @return void
     */
    public function wait(Closure|null $result = null): void
    {
        if (!isset($this->mainContext)) {
            $this->mainContext = getContext();
        }

        if (!$this->mainRunning) {
            try {
                Coroutine::resume($this->mainContext, $result);
                if (Fiber::getCurrent()) {
                    Fiber::suspend();
                }
            } catch (Throwable) {
                exit(0);
            }
            return;
        }

        if ($result instanceof Closure) {
            $result();
        }

        try {
            $this->mainRunning = false;
            $result = Coroutine::suspend($this->mainContext);
            $this->mainRunning = true;

            if ($result instanceof Closure) {
                try {
                    $result();
                } catch (Throwable) {
                }
            }

            /*** The Event object may be reset during the mainRunning of $result, so mainContext needs to be reacquired.*/
            unset($this->mainContext);
            wait();
        } catch (Throwable) {
        }
    }

    /**
     * @return void
     */
    public function stop(): void
    {
        wait(static fn () => EventLoop::getDriver()->stop());
    }

    /**
     * @return bool
     */
    public function supportParallel(): bool
    {
        return $this->parallel;
    }

    /**
     * @return int
     */
    public function getProcessId(): int
    {
        return $this->supportProcessControl() ? posix_getpid() : getmygid();
    }

    /**
     * @return bool
     */
    public function supportProcessControl(): bool
    {
        return $this->processControl;
    }
}
