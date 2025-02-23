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

namespace Ripple\Parallel;

use parallel\Runtime;
use Closure;

use function Co\wait;
use function call_user_func_array;
use function extension_loaded;

if (!extension_loaded('parallel')) {
    return;
}

class Thread
{
    /**
     * @param Closure $function
     * @param array   $argv
     */
    public function __construct(private readonly Closure $function, private readonly array $argv = [])
    {
    }

    /**
     * @param \parallel\Runtime $runtime
     *
     * @return \Ripple\Parallel\Future
     */
    public function __invoke(Runtime $runtime): Future
    {
        $function = $this->function;
        $future   = $runtime->run(static function (...$argv) use ($function) {
            try {
                $result = call_user_func_array($function, $argv);
                wait();
                return $result;
            } finally {
                $channel = Parallel::openScalarChannel();
                $channel->send(1);
                $channel->close();
            }
        }, $this->argv);
        return new Future($future, $runtime);
    }
}
