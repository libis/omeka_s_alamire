<?php
return [
    'block_layouts' => [
        'invokables' => [
            'PageBrowse' => PageBrowse\Site\BlockLayout\PageBrowse::class,
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ]
];
