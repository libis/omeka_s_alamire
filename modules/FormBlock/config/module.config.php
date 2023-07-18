<?php


return [
    'block_layouts' => [
        'invokables' => [
            'formBlock' => FormBlock\Site\BlockLayout\FormBlock::class,
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],
    'controllers' => [
        'invokables' => [
            Controller\Site\FormBlockController::class => Controller\Site\FormBlockController::class,
        ]
    ],
    'router' => [
        'routes' => [
            'site' => [
                'child_routes' => [
                    'feedback' => [
                        'type' => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route' => '/feedback',
                            'defaults' => [
                                '__NAMESPACE__' => 'FormBlock\Controller\Site',
                                'controller' => Controller\Site\FormBlockController::class,
                                'action' => 'form',
                            ],
                        ],
                        'may_terminate' => true
                    ],
                ],
            ],
        ],
    ]        
];
