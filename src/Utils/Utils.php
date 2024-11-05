<?php declare(strict_types=1);

namespace Ripple\Utils;

use function md5;
use function sys_get_temp_dir;

class Utils
{
    /**
     * @param string $seed
     * @param string $ext
     *
     * @return string
     */
    public static function tempPath(string $seed, string $ext = 'rip'): string
    {
        return sys_get_temp_dir() . '/' . md5($seed) . '.' . $ext;
    }
}
