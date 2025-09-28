<?php declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()->in(__DIR__)
    ->name('*.php')
    ->notName('*.blade.php')
    ->notPath('storage/framework/views')
    ->notPath('public');

$config = new Config();

$config->setFinder($finder);
$config->setRiskyAllowed(true);

return $config->setRules([
    '@PSR12' => true,
    'native_function_invocation' => [
        'include' => ['@all'],
        'scope' => 'all',
        'strict' => false
    ],
    'native_constant_invocation' => [
        'include' => ['@all'],
        'scope' => 'all',
        'strict' => false
    ],
    'global_namespace_import' => [
        'import_classes' => true,
        'import_constants' => true,
        'import_functions' => true
    ],
    'declare_strict_types' => true,
    'linebreak_after_opening_tag' => false,
    'blank_line_after_opening_tag' => false,
    'no_unused_imports' => true,
]);
