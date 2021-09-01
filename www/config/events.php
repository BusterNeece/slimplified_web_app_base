<?php

use App\Console\Command;
use App\Event;
use App\Middleware;
use Slim\Interfaces\ErrorHandlerInterface;

return function (Azura\SlimCallableEventDispatcher\CallableEventDispatcherInterface $dispatcher) {
    $dispatcher->addListener(Event\BuildConsoleCommands::class, function (Event\BuildConsoleCommands $event) {
        $console = $event->getConsole();
        $di = $console->getContainer();

        $console->command('cache:clear', Command\ClearCacheCommand::class)
            ->setDescription('Clear all application caches.');

        // Doctrine ORM/DBAL
        Doctrine\ORM\Tools\Console\ConsoleRunner::addCommands($console);

        // Add Doctrine Migrations
        /** @var Doctrine\ORM\EntityManagerInterface $em */
        $em = $di->get(Doctrine\ORM\EntityManagerInterface::class);

        $helper_set = $console->getHelperSet();
        $doctrine_helpers = Doctrine\ORM\Tools\Console\ConsoleRunner::createHelperSet($em);
        $helper_set->set($doctrine_helpers->get('db'), 'db');
        $helper_set->set($doctrine_helpers->get('em'), 'em');

        $migrateConfig = new Doctrine\Migrations\Configuration\Migration\ConfigurationArray([
            'migrations_paths' => [
                'App\Entity\Migration' => dirname(__DIR__) . '/src/Entity/Migration',
            ],
            'table_storage' => [
                'table_name' => 'app_migrations',
            ],
        ]);

        $migrateFactory = Doctrine\Migrations\DependencyFactory::fromEntityManager(
            $migrateConfig,
            new Doctrine\Migrations\Configuration\EntityManager\ExistingEntityManager($em)
        );
        Doctrine\Migrations\Tools\Console\ConsoleRunner::addCommands($console, $migrateFactory);

        call_user_func(include(__DIR__ . '/cli.php'), $console);
    });

    $dispatcher->addListener(Event\BuildRoutes::class, function (Event\BuildRoutes $event) {
        $app = $event->getApp();

        call_user_func(include(__DIR__ . '/routes.php'), $app);

        // Request injection middlewares.
        $app->add(Middleware\EnableView::class);

        $app->add(Middleware\InjectRouter::class);

        // System middleware for routing and body parsing.
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();

        // Redirects and updates that should happen before system middleware.
        $app->add(new Middleware\RemoveSlashes);
        $app->add(new Middleware\ApplyXForwardedProto);

        // Error handling, which should always be near the "last" element.
        $environment = $app->getContainer()->get(App\Environment::class);
        $errorMiddleware = $app->addErrorMiddleware(!$environment->isProduction(), true, true);
        $errorMiddleware->setDefaultErrorHandler(ErrorHandlerInterface::class);

        // Use PSR-7 compatible sessions.
        $app->add(Middleware\InjectSession::class);
    });
};