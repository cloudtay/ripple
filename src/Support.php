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

/**
 * @Author cclilshy
 * @Date   2024/8/16 09:35
 */
abstract class Support
{
    /*** @var Support */
    protected static Support $instance;

    /**
     * @return static
     */
    public static function getInstance(): static
    {
        if (!isset(static::$instance)) {
            static::$instance = new static();
        }
        return static::$instance;
    }

    /**
     * @return bool
     */
    public static function hasInstance(): bool
    {
        return isset(static::$instance);
    }
}
