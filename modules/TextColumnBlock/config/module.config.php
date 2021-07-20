<?php
return [
    'block_layouts' => [
        'invokables' => [
            'textColumnBlock' => TextColumnBlock\Site\BlockLayout\TextColumnBlock::class,
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ]
];
