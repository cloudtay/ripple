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

namespace Ripple\Coroutine\Exception;

use Closure;
use RuntimeException;

/**
 * 协程中运行子进程时使用 suspension->suspend 会跳出协程从而导致协程上下文逃逸,
 * 会发生资源泄漏等不可预料的问题,因此在协程中使用 Process::fork 时,
 * ripple会通过 EscapeException 的方式向上抛出异常最终在 mainSuspension 中执行交换 EventDriver
 */
class EscapeException extends RuntimeException
{
    /**
     * @param Closure $lastWords
     */
    public function __construct(public readonly Closure $lastWords)
    {
        parent::__construct('Escape from coroutine');
    }
}
