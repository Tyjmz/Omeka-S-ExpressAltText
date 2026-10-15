<?php
namespace ExpressAltText;
return [
 'controllers' => ['factories' => [
   Controller\AdminController::class => function ($services) {
     return new Controller\AdminController(
       $services->get('Omeka\EntityManager'),
       $services->get('Omeka\Acl'),
       $services->get('Omeka\AuthenticationService')
     );
   },
 ]],
 'router' => ['routes' => ['admin' => ['child_routes' => [
   'express-alt-text' => ['type' => 'Literal', 'options' => [
     'route' => '/express-alt-text',
     'defaults' => ['controller' => Controller\AdminController::class, 'action' => 'index'],
   ]],
 ]]]],
 'navigation' => ['AdminModule' => [[
   'label' => 'Express Alt Text', 'route' => 'admin/express-alt-text',
   'resource' => Controller\AdminController::class,
 ]]],
 'view_manager' => ['template_path_stack' => [__DIR__ . '/../view']],
];
