<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests');

$config = new PhpCsFixer\Config();
return $config->setRules([
    '@PSR12' => true,
    'array_syntax' => ['syntax' => 'short'],
    'no_unused_imports' => true,
    'ordered_imports' => ['sort_algorithm' => 'alpha'],
    'single_quote' => true,
    'trailing_comma_in_multiline' => ['elements' => ['arrays']],
    'braces' => [
        'position_after_functions_and_oop_constructs' => 'same',
    ],
])
->setFinder($finder);
