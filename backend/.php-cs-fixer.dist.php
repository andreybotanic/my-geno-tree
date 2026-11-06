<?php

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->notPath([
        'src/Kernel.php',
    ])
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        'class_definition' => ['single_line' => false],
        'binary_operator_spaces' => [
            'operators' => [
                '=>' => 'align_single_space_minimal',
            ],
        ],
        'concat_space' => [
            'spacing' => 'one',
        ],
        'cast_spaces' => [
            'space' => 'none',
        ],
    ])
    ->setFinder($finder)
;
