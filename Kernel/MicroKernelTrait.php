<?php

namespace UxGood\MiniFramework\Kernel;

use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\RouteCollectionBuilder;

use UxGood\MiniFramework\Controller\ControllerResolver;
use UxGood\MiniFramework\Controller\ControllerNameParser;

#use Symfony\Component\HttpKernel\DependencyInjection\AddAnnotatedClassesToCachePass;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\HttpKernel;
//use Symfony\Component\HttpKernel\Controller\ControllerResolver;
use Symfony\Component\HttpKernel\Controller\ContainerControllerResolver;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
/*
use Symfony\Component\HttpKernel\Controller\ArgumentResolver\RequestAttributeValueResolver;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver\RequestValueResolver;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver\SessionValueResolver;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver\ServiceValueResolver;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver\DefaultValueResolver;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver\VariadicValueResolver;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadataFactory;
 */
use Symfony\Component\HttpKernel\EventListener\RouterListener;
use Symfony\Component\HttpKernel\EventListener\ResponseListener;
use Symfony\Component\HttpKernel\EventListener\StreamedResponseListener;
//use Symfony\Component\HttpKernel\EventListener\LocaleListener;
use Symfony\Component\HttpKernel\EventListener\ExceptionListener;
use Symfony\Component\HttpKernel\EventListener\ValidateRequestListener;
use Symfony\Component\HttpKernel\EventListener\DebugHandlersListener;
use Symfony\Component\HttpKernel\EventListener\SessionListener;
use Symfony\Component\EventDispatcher\EventDispatcher;
//use Symfony\Component\HttpFoundation;
use Symfony\Component\HttpFoundation\RequestStack;
//use Symfony\Component\Routing;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Matcher\UrlMatcher;

use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\NativeSessionStorage;
use Symfony\Component\HttpFoundation\Session\Storage\MetadataBag;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBag;
use Symfony\Component\HttpFoundation\Session\Attribute\AttributeBag;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\NativeFileSessionHandler;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\StrictSessionHandler;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;

trait MicroKernelTrait
{
    abstract protected function configureRoutes(RouteCollectionBuilder $routes);

    abstract protected function configureContainer(ContainerBuilder $container);
    
    public function registerBundles()
    {
        //$contents = require $this->getProjectDir().'/config/bundles.php';
        foreach ($contents ?? [] as $class => $envs) {
            if ($envs[$this->environment] ?? $envs['all'] ?? false) {
                yield new $class();
            }
        }
    }
 
    public function registerContainerConfiguration(LoaderInterface $loader)
    {
    
    }
    public function loadRoutes()
    {
        $routes = new RouteCollectionBuilder(null);
        $this->configureRoutes($routes);
        return $routes->build();
    }

    protected function buildContainer()
    {
        $container = $this->getContainerBuilder();
        $container->addObjectResource($this);
        $this->prepareContainer($container);
        $routes = $this->loadRoutes();
        $container->register('kernel')->setSynthetic(true);
        $container->register('router.request_context', RequestContext::class);
        $container->register('router.url_matcher', UrlMatcher::class)
            ->setArguments(array($routes, new Reference('router.request_context')))
        ;
        $container->setAlias('router', new Alias('router.url_matcher'))
            ->setPublic(true)
        ;
        $container->register('controller_name_converter', ControllerNameParser::class)
            ->setArguments(array(new Reference('kernel')));
        $container->register('request_stack', RequestStack::class)->setPublic(true);
        //$container->register('controller_resolver', ControllerResolver::class);
        //$container->register('controller_resolver', ContainerControllerResolver::class)
        $container->register('controller_resolver', ControllerResolver::class)
            ->setArguments(array(
                new Reference('service_container'),
                new Reference('controller_name_converter')
            ))
        ;


        /*
        $container->register('argument_metadata_factory', ArgumentMetadataFactory::class);
        $container->register('argument_resolver.request_attribute', RequestAttributeValueResolver::class);
        $container->register('argument_resolver.request', RequestValueResolver::class);
        $container->register('argument_resolver.session', SessionValueResolver::class);
        $container->register('argument_resolver.service', ServiceValueResolver::class);
        $container->register('argument_resolver.default', DefaultValueResolver::class);
        $container->register('argument_resolver.variadic', VariadicValueResolver::class);
        */

        $container->register('argument_resolver', ArgumentResolver::class)
            /*
            ->setArguments(array(
                new Reference('argument_metadata_factory')
            ))
             */
        ;
        $container->register('listener.router', RouterListener::class)
            ->setArguments(array(new Reference('router.url_matcher'), new Reference('request_stack')))
        ;
        $container->register('listener.response', ResponseListener::class)
            ->setArguments(array('UTF-8'))
        ;
        $container->register('listener.streamed_response', StreamedResponseListener::class);
        $container->register('listener.http_exception', ExceptionListener::class)
            ->setArguments(array(null, null, $this->debug, $this->getCharset()))
        ;
        $container->register('listener.debug_handlers', DebugHandlersListener::class)
            ->setArguments(array(null, null, null, -1, true, null, true))
            ;


        $container->register('session', Session::class);
        $container->register('listener.session', SessionListener::class)
            ->setArguments(array(new ServiceLocatorArgument(array(
                'session' => new Reference('session', ContainerInterface::IGNORE_ON_INVALID_REFERENCE),
                'initialized_session' => new Reference('session', ContainerInterface::IGNORE_ON_UNINITIALIZED_REFERENCE)
            ))))
        ;
        $container->register('listener.validate_request', ValidateRequestListener::class);
        $container->register('event_dispatcher', EventDispatcher::class)
            ->addMethodCall('addSubscriber', array(new Reference('listener.router')))
            ->addMethodCall('addSubscriber', array(new Reference('listener.response')))
            ->addMethodCall('addSubscriber', array(new Reference('listener.streamed_response')))
            ->addMethodCall('addSubscriber', array(new Reference('listener.http_exception')))
            ->addMethodCall('addSubscriber', array(new Reference('listener.session')))
            ->addMethodCall('addSubscriber', array(new Reference('listener.debug_handlers')))
        ;

        $container->register('http_kernel', HttpKernel::class)
            ->setArguments(array(
                new Reference('event_dispatcher'),
                new Reference('controller_resolver'),
                new Reference('request_stack'),
                new Reference('argument_resolver'),
            ))
            ->setPublic(true)
        ;

        $this->configureContainer($container);

        return $container;
    }

    protected function initializeContainer()
    {
        $this->container = $this->buildContainer();
        $this->container->compile();
        $this->container->set('kernel', $this);
    }
}
