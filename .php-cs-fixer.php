<?php

declare(strict_types=1);

$dirs = array_values(array_filter(
    [__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/bin', __DIR__ . '/config', __DIR__ . '/public'],
    'is_dir',
));

$finder = (new PhpCsFixer\Finder())
    ->in($dirs)
    ->name('*.php')
    ->append([__FILE__]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'declare_strict_types' => true,
        'strict_param' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha', 'imports_order' => ['class', 'function', 'const']],
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters', 'match']],
        'native_function_invocation' => false,
        'final_class' => false,
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache');
