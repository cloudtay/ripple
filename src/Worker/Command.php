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

namespace Ripple\Worker;

use function serialize;
use function unserialize;

/**
 * @Author cclilshy
 * @Date   2024/8/16 12:00
 */
class Command
{
    /**
     * @param string $name
     * @param array  $arguments
     */
    public function __construct(public readonly string $name, public readonly array $arguments = [])
    {
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/16 11:59
     *
     * @param string $name
     * @param array  $arguments
     *
     * @return Command
     */
    public static function make(string $name, array $arguments = []): Command
    {
        return new Command($name, $arguments);
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/16 11:59
     *
     * @param string $command
     *
     * @return Command
     */
    public static function fromString(string $command): Command
    {
        return unserialize($command);
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/16 11:59
     * @return string
     */
    public function __toString(): string
    {
        return serialize($this);
    }
}
