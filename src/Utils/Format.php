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

use function chr;
use function intval;
use function ord;
use function pow;
use function strlen;

class Format
{
    /**
     * @Author cclilshy
     * @Date   2024/8/27 21:57
     *
     * @param string $string
     *
     * @return int
     */
    public static function string2int(string $string): int
    {
        $len = strlen($string);
        $sum = 0;
        for ($i = 0; $i < $len; $i++) {
            $sum += (ord($string[$i]) - 96) * pow(26, $len - $i - 1);
        }
        return $sum;
    }

    /**
     * @Author cclilshy
     * @Date   2024/8/27 21:57
     *
     * @param int $int
     *
     * @return string
     */
    public static function int2string(int $int): string
    {
        $string = '';
        while ($int > 0) {
            $string = chr(($int - 1) % 26 + 97) . $string;
            $int    = intval(($int - 1) / 26);
        }
        return $string;
    }
}
