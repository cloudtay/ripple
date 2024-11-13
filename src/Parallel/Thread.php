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

use Closure;
use parallel\Runtime;

class Thread
{
    /*** @var Runtime */
    private readonly Runtime $runtime;

    /*** @var Context */
    private Context $context;

    /**
     * @param Closure $handler
     * @param string  $name
     */
    public function __construct(
        private readonly Closure $handler,
        public readonly string   $name,
    ) {
        $this->runtime = new Runtime(Parallel::$autoload);
        $this->context = new Context();
    }

    /**
     * @return void
     */
    public function close(): void
    {
        $this->runtime->close();
    }

    /**
     * @return void
     */
    public function kill(): void
    {
        $this->runtime->kill();
    }

    /**
     * @param mixed ...$argv
     *
     * @return Future
     */
    public function __invoke(mixed ...$argv): Future
    {
        $this->context->argv = $argv;
        $this->context->name = $this->name;

        return new Future($this->runtime->run(static function (Closure $handler, Context $context) {
            $counterChannel = \parallel\Channel::open('counter');
            try {
                return $handler($context);
            } finally {
                $counterChannel->send(1);
            }
        }, [
            $this->handler,
            $this->context,
        ]));
    }

    /**
     * @param  ...$argv
     *
     * @return Future
     */
    public function run(...$argv): Future
    {
        return Parallel::getInstance()->run($this, ... $argv);
    }
}
