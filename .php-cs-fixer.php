<?php

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in(__DIR__)
    ->name('*.php')
    ->ignoreVCSIgnored(true);

$config = new Config();

$rules = [
    '@PER-CS2.0'                  => true,
    'trailing_comma_in_multiline' => ['elements' => ['arguments', 'array_destructuring', 'arrays']], // For PHP 7.4 compatibility
    // Disabled: conflicts with this project's phpcs ruleset (.phpcs.xml),
    // which requires an empty method/class body's closing brace on its own
    // line — @PER-CS2.0 collapses it to `{}` on one line, and the two tools
    // fighting over the same lines makes CI fail no matter which ran last.
    'single_line_empty_body' => false,
];

return $config
    ->setRules($rules)
    ->setFinder($finder)
    ->setUsingCache(false)
    ->setUnsupportedPhpVersionAllowed(true);