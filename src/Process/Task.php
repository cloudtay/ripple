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

namespace Ripple\Process;

use Closure;

use function call_user_func;

/**
 * @Author cclilshy
 * @Date   2024/8/16 09:36
 */
class Task
{
    /**
     * @param Closure $closure
     */
    public function __construct(
        public readonly Closure $closure,
    ) {
    }

    /**
     * @param ...$argv
     *
     * @return Runtime|false
     */
    public function run(...$argv): Runtime|false
    {
        return call_user_func($this->closure, ...$argv);
    }
}
