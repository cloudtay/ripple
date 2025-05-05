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

namespace Ripple\Coroutine;

use Ripple\Types\Undefined;

use function Co\getContext;
use function array_merge;
use function is_array;

class ContextData
{
    /**
     * @var array
     */
    protected static array $context = [];

    /**
     * @param array|string $key
     * @param mixed        $value
     *
     * @return void
     */
    public static function setValue(array|string $key, mixed $value = null): void
    {
        $hash = Context::getHash();
        if (is_array($key)) {
            ContextData::$context[$hash] = array_merge(ContextData::$context[$hash] ?? [], $key);
            return;
        }

        ContextData::$context[$hash][$key] = $value;
    }

    /**
     * @param string|null $key
     *
     * @return mixed
     */
    public static function getValue(string|null $key = null): mixed
    {
        $hash = Context::getHash();
        if (!$key) {
            return ContextData::$context[$hash] ?? new Undefined();
        }
        return ContextData::$context[$hash][$key] ?? new Undefined();
    }

    /**
     * @param string $key
     *
     * @return void
     */
    public static function removeValue(string $key): void
    {
        $hash = Context::getHash();
        unset(ContextData::$context[$hash][$key]);
    }

    /**
     * @param Context $targetContext
     *
     * @return void
     */
    public static function extend(Context $targetContext): void
    {
        $currentContext = getContext();
        if ($currentContext === $targetContext) {
            return;
        }

        $currentHash = Context::getHash($currentContext);
        $targetHash  = Context::getHash($targetContext);
        ContextData::$context[$currentHash] = (ContextData::$context[$targetHash] ?? []);
    }

    /**
     * @return void
     */
    public static function clear(): void
    {
        unset(ContextData::$context[Context::getHash()]);
    }
}
