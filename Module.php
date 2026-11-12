<?php
namespace ExpressAltText;

use ExpressAltText\Controller\AdminController;
use Laminas\Mvc\MvcEvent;
use Omeka\Module\AbstractModule;

class Module extends AbstractModule
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function onBootstrap(MvcEvent $event)
    {
        parent::onBootstrap($event);

        $services = $this->getServiceLocator();
        $acl = $services->get('Omeka\Acl');
        // Omeka's dispatch authorization checks controller ACL permissions.
        // Grant access only to global administrators; all other roles remain denied.
        $acl->allow('global_admin', AdminController::class, 'index');

        // Run before Omeka's controller authorization listener. A restricted
        // request to this module receives a real HTTP 403 instead of an
        // uncaught PermissionDeniedException rendered as HTTP 500.
        $event->getApplication()->getEventManager()->attach(
            MvcEvent::EVENT_DISPATCH,
            function (MvcEvent $dispatchEvent) use ($services) {
                $routeMatch = $dispatchEvent->getRouteMatch();
                if (!$routeMatch || $routeMatch->getMatchedRouteName() !== 'express-alt-text-dashboard') {
                    return;
                }

                $identity = $services->get('Omeka\AuthenticationService')->getIdentity();
                if ($identity && method_exists($identity, 'getRole')
                    && $identity->getRole() === 'global_admin') {
                    return;
                }

                $response = $dispatchEvent->getResponse();
                $response->setStatusCode(403);
                $response->getHeaders()->addHeaderLine('Content-Type', 'text/html; charset=utf-8');
                $response->getHeaders()->addHeaderLine('Cache-Control', 'no-store');
                $response->setContent('<!doctype html><html lang="en"><head><meta charset="utf-8">'
                    . '<meta name="viewport" content="width=device-width,initial-scale=1">'
                    . '<title>Access denied — ExpressAltText</title></head><body><main>'
                    . '<h1>Access denied</h1><p>Your account does not have permission to use '
                    . 'ExpressAltText. Please contact a site administrator if you need access.</p>'
                    . '</main></body></html>');
                $dispatchEvent->setResponse($response);
                $dispatchEvent->stopPropagation(true);
                return $response;
            },
            2000
        );
    }
}
