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

namespace Co;

use Ripple\Process\Process;

/**
 * @Author cclilshy
 * @Date   2024/8/16 09:35
 */
class System
{
    /**
     * @return Process
     */
    public static function Process(): Process
    {
        return Process::getInstance();
    }
}
