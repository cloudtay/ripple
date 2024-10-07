<?php declare(strict_types=1);
/*
 * Copyright (c) 2023-2024.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * 特此免费授予任何获得本软件及相关文档文件（“软件”）副本的人，不受限制地处理
 * 本软件，包括但不限于使用、复制、修改、合并、出版、发行、再许可和/或销售
 * 软件副本的权利，并允许向其提供本软件的人做出上述行为，但须符合以下条件：
 *
 * 上述版权声明和本许可声明应包含在本软件的所有副本或主要部分中。
 *
 * 本软件按“原样”提供，不提供任何形式的保证，无论是明示或暗示的，
 * 包括但不限于适销性、特定目的的适用性和非侵权性的保证。在任何情况下，
 * 无论是合同诉讼、侵权行为还是其他方面，作者或版权持有人均不对
 * 由于软件或软件的使用或其他交易而引起的任何索赔、损害或其他责任承担责任。
 */

use Co\Coroutine;
use Psc\Core\Coroutine\Promise;
use Psc\Core\Parallel\Thread;
use Revolt\EventLoop\Suspension;
use Revolt\EventLoop\UnsupportedFeatureException;
use Symfony\Component\DependencyInjection\Container;

if (!\function_exists('await')) {
    /**
     * @Author cclilshy
     * @Date   2024/9/29 12:15
     * @param Promise $promise
     * @return mixed
     * @throws Throwable
     */
    function await(Promise $promise): mixed
    {
        return \Co\await($promise);
    }
}

if (!\function_exists('async')) {
    /**
     * The location of the exception thrown in the async closure may be the calling context/suspension recovery location,
     * so exceptions must be managed carefully.
     *
     * @Author cclilshy
     * @Date   2024/9/29 12:15
     * @param Closure $closure
     * @return Promise
     */
    function async(Closure $closure): Promise
    {
        return \Co\async($closure);
    }
}

if (!\function_exists('promise')) {
    /**
     * @Author cclilshy
     * @Date   2024/9/29 12:15
     * @param Closure $closure
     * @return Promise
     */
    function promise(Closure $closure): Promise
    {
        return \Co\promise($closure);
    }
}

if (!\function_exists('sleep')) {
    /**
     * @Author cclilshy
     * @Date   2024/9/29 12:15
     * @param int|float $second
     * @return int
     */
    function sleep(int|float $second): int
    {
        return \Co\sleep($second);
    }
}

if (!\function_exists('delay')) {
    /**
     * @Author cclilshy
     * @Date   2024/9/29 12:15
     * @param Closure   $closure
     * @param int|float $second
     * @return string
     */
    function delay(Closure $closure, int|float $second): string
    {
        return \Co\delay($closure, $second);
    }
}

if (!\function_exists('repeat')) {
    /**
     * @Author cclilshy
     * @Date   2024/9/29 12:15
     * @param Closure(Closure):void $closure
     * @param int|float             $second
     * @return string
     */
    function repeat(Closure $closure, int|float $second): string
    {
        return \Co\repeat($closure, $second);
    }
}

if (!\function_exists('queue')) {
    /**
     * @Author cclilshy
     * @Date   2024/9/29 12:15
     * @param Closure $closure
     * @return void
     */
    function queue(Closure $closure): void
    {
        \Co\queue($closure);
    }
}

if (!\function_exists('defer')) {
    /**
     * @Author cclilshy
     * @Date   2024/9/29 12:15
     * @param Closure $closure
     * @return void
     */
    function defer(Closure $closure): void
    {
        \Co\defer($closure);
    }
}

if (!\function_exists('thread')) {
    /**
     * @Author cclilshy
     * @Date   2024/9/29 12:15
     * @param Closure $closure
     * @return Thread
     * @throws RuntimeException
     */
    function thread(Closure $closure): Thread
    {
        return \Co\thread($closure);
    }
}

if (!\function_exists('cancel')) {
    /**
     * @Author cclilshy
     * @Date   2024/9/29 12:15
     * @param string $id
     * @return void
     */
    function cancel(string $id): void
    {
        \Co\cancel($id);
    }
}

if (!\function_exists('cancelForked')) {
    /**
     * @param string $index
     * @return void
     */
    function cancelForked(string $index): void
    {
        \Co\cancelForked($index);
    }
}

if (!\function_exists('cancelAll')) {
    /**
     * @return void
     */
    function cancelAll(): void
    {
        \Co\cancelAll();
    }
}

if (!\function_exists('onSignal')) {
    /**
     * @param int     $signal
     * @param Closure $closure
     *
     * @return string
     * @throws UnsupportedFeatureException
     */
    function onSignal(int $signal, Closure $closure): string
    {
        return \Co\onSignal($signal, $closure);
    }
}

if (!\function_exists('forked')) {
    /**
     * @param Closure $closure
     *
     * @return string
     */
    function forked(Closure $closure): string
    {
        return \Co\forked($closure);
    }
}

if (!\function_exists('wait')) {
    /**
     * @param Closure|null $closure
     *
     * @return void
     */
    function wait(Closure|null $closure = null): void
    {
        \Co\wait($closure);
    }
}

if (!\function_exists('stop')) {
    /**
     * @return void
     */
    function stop(): void
    {
        \Co\stop();
    }
}

if (!\function_exists('string2int')) {
    /**
     * @Author cclilshy
     * @Date   2024/8/27 21:57
     *
     * @param string $string
     *
     * @return int
     */
    function string2int(string $string): int
    {
        $len = \strlen($string);
        $sum = 0;
        for ($i = 0; $i < $len; $i++) {
            $sum += (\ord($string[$i]) - 96) * \pow(26, $len - $i - 1);
        }
        return $sum;
    }
}

if (!\function_exists('int2string')) {
    /**
     * @Author cclilshy
     * @Date   2024/8/27 21:57
     *
     * @param int $int
     *
     * @return string
     */
    function int2string(int $int): string
    {
        $string = '';
        while ($int > 0) {
            $string = \chr(($int - 1) % 26 + 97) . $string;
            $int    = \intval(($int - 1) / 26);
        }
        return $string;
    }
}

if (!\function_exists('container')) {
    /**
     * @Author cclilshy
     * @Date   2024/9/30 10:56
     * @return Container
     */
    function container(): Container
    {
        return \Co\container();
    }
}

if (!\function_exists('getSuspension')) {
    /**
     * @Author cclilshy
     * @Date   2024/10/7 17:15
     * @return Suspension
     */
    function getSuspension(): Suspension
    {
        return Coroutine::Coroutine()->getSuspension();
    }
}
