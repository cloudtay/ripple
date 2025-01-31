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

use Ripple\Parallel\Parallel;

use function Co\wait;

include 'vendor/autoload.php';

$parallel = Parallel::getInstance();
$function = function ($input) {
    \sleep(1);
    return $input;
};

$futures = [];
for ($i = 0; $i < 100; $i++) {
    $futures[] = $future =  $parallel->run($function, ['name']);
}

foreach ($futures as $future) {
    echo \microtime(true) , ':', $future->value() , \PHP_EOL;
}

wait();
