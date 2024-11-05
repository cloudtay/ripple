<?php declare(strict_types=1);

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
