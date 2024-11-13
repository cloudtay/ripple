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

namespace Ripple\Utils;

use function md5;
use function sys_get_temp_dir;

class Path
{
    /**
     * @param string $seed
     * @param string $ext
     *
     * @return string
     */
    public static function temp(string $seed, string $ext = 'rip'): string
    {
        return sys_get_temp_dir() . '/' . md5($seed) . '.' . $ext;
    }
}
