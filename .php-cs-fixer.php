<?php

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()->in(__DIR__)->name('*.php')->notName('*.blade.php');

$config = new Config();

return $config->setRules([
    '@PSR12'                     => true,
    'native_function_invocation' => [
        'include' => ['@all'],
        'scope'   => 'all',
        'strict'  => true,
    ],
    'native_constant_invocation' => [
        'include' => ['@all'],
        'scope'   => 'all',
        'strict'  => true,
    ],
    'global_namespace_import'    => [
        'import_classes'   => true,
        'import_constants' => true,
        'import_functions' => true,
    ],


])->setFinder($finder);
