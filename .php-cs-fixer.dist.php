<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
    ->in(__DIR__ . '/tests')
;

$config = new PhpCsFixer\Config();
return $config->setRules([
    'ordered_imports' => ['sort_algorithm' => 'alpha'],
    'no_whitespace_in_blank_line' => true,
])
    ->setFinder($finder)
;
