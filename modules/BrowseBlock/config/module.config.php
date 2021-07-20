<?php
return [
    'block_layouts' => [
        'invokables' => [
            'BrowseBlock' => BrowseBlock\Site\BlockLayout\BrowseBlock::class,
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ]
];
