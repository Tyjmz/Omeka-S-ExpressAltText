<?php
namespace ExpressAltText;

use ExpressAltText\Controller\AdminController;

return [
    'controllers' => [
        'factories' => [
            AdminController::class => function ($container) {
                return new AdminController(
                    $container->get('Omeka\ApiManager'),
                    $container->get('Omeka\EntityManager'),
                    $container->get('Omeka\Acl'),
                    $container->get('Omeka\AuthenticationService'),
                    $container->get('Omeka\Settings')
                );
            },
        ],
    ],
    'router' => [
        'routes' => [
            // A dedicated, absolute route avoids relying on the admin child-route merge.
            'express-alt-text-dashboard' => [
                'type' => 'Literal',
                'priority' => 1000,
                'options' => [
                    'route' => '/admin/express-alt-text',
                    'defaults' => [
                        'controller' => AdminController::class,
                        'action' => 'index',
                    ],
                ],
            ],
        ],
    ],
    'navigation' => [
        'AdminModule' => [
            [
                'label' => 'Express Alt Text',
                'route' => 'express-alt-text-dashboard',
                'resource' => AdminController::class,
                'privilege' => 'index',
            ],
        ],
    ],
    'view_manager' => [
        'template_map' => [
            'express-alt-text/controller/admin/index' => __DIR__ . '/../view/express-alt-text/admin/index.phtml',
            'express-alt-text/admin/index' => __DIR__ . '/../view/express-alt-text/admin/index.phtml',
        ],
        'template_path_stack' => [__DIR__ . '/../view'],
    ],
];
