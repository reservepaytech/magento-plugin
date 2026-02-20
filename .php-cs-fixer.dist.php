<?php

$finder = PhpCsFixer\Finder::create()
  ->in(__DIR__)
  ->exclude('vendor')
  ->name('*.php')
  ->notName('bootstrap.php');

return (new PhpCsFixer\Config())
  ->setRules([
    '@PSR12' => true,
    'array_indentation' => true,
  ])
  ->setIndent('  ')
  ->setLineEnding("\n")
  ->setFinder($finder);
