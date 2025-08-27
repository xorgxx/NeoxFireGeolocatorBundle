<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests')
    ->name('*.php')
    ->ignoreVCS(true)
    ->exclude(['vendor', 'var', 'node_modules']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@PHP81Migration' => true,
        '@PHPUnit84Migration:risky' => true,
        'declare_strict_types' => false,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'array_syntax' => ['syntax' => 'short'],
        'binary_operator_spaces' => ['default' => 'align_single_space_minimal'],
        'concat_space' => ['spacing' => 'one'],
        'phpdoc_align' => ['align' => 'vertical'],
        'phpdoc_order' => true,
        'yoda_style' => false,
        'native_function_invocation' => false,
    ])
    ->setFinder($finder);
