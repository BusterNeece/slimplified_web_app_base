<?php
use App\Event;
use App\Console\Command;
use App\Settings;
use App\Middleware;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Tools\Console\ConsoleRunner;
use Doctrine\Migrations\Tools\Console\Helper\ConfigurationHelper;
use Doctrine\ORM\EntityManager;
use Slim\Interfaces\ErrorHandlerInterface;

return function (\App\EventDispatcher $dispatcher)
{
    $dispatcher->addListener(Event\BuildConsoleCommands::class, function(Event\BuildConsoleCommands $event) {
        $console = $event->getConsole();
        $di = $console->getContainer();

        /** @var Settings $settings */
        $settings = $di->get(Settings::class);

        if ($settings->enableRedis()) {
            $console->command('cache:clear', Command\ClearCacheCommand::class)
                ->setDescription('Clear all application caches.');
        }

        if ($settings->enableDatabase()) {
            // Doctrine ORM/DBAL
            Doctrine\ORM\Tools\Console\ConsoleRunner::addCommands($console);

            // Add Doctrine Migrations
            $defaults = [
                'table_name' => 'app_migrations',
                'directory' => $settings[Settings::BASE_DIR] . '/src/Entity/Migration',
                'namespace' => 'App\Entity\Migration',
            ];

            $user_options = $settings[Settings::DOCTRINE_OPTIONS]['migrations'] ?? [];
            $options = array_merge($defaults, $user_options);

            /** @var EntityManager $em */
            $em = $di->get(EntityManager::class);
            $connection = $em->getConnection();

            $helper_set = $console->getHelperSet();
            $doctrine_helpers = Doctrine\ORM\Tools\Console\ConsoleRunner::createHelperSet($em);

            $helper_set->set($doctrine_helpers->get('db'), 'db');
            $helper_set->set($doctrine_helpers->get('em'), 'em');

            $migrate_config = new Configuration($connection);
            $migrate_config->setMigrationsTableName($options['table_name']);
            $migrate_config->setMigrationsDirectory($options['directory']);
            $migrate_config->setMigrationsNamespace($options['namespace']);

            $migrate_config_helper = new ConfigurationHelper($connection,
                $migrate_config);
            $helper_set->set($migrate_config_helper, 'configuration');

            ConsoleRunner::addCommands($console);
        }

        if (file_exists(__DIR__ . '/cli.php')) {
            call_user_func(include(__DIR__ . '/cli.php'), $console);
        }
    });

    $dispatcher->addListener(Event\BuildRoutes::class, function(Event\BuildRoutes $event) {
        $app = $event->getApp();

        // Load app-specific route configuration.
        $container = $app->getContainer();

        /** @var Settings $settings */
        $settings = $container->get(Settings::class);

        if (file_exists($settings[Settings::CONFIG_DIR] . '/routes.php')) {
            call_user_func(include($settings[Settings::CONFIG_DIR] . '/routes.php'), $app);
        }

        // Request injection middlewares.
        $app->add(Middleware\InjectRouter::class);
        $app->add(Middleware\InjectRateLimit::class);

        // System middleware for routing and body parsing.
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();

        // Redirects and updates that should happen before system middleware.
        $app->add(new Middleware\RemoveSlashes);
        $app->add(new Middleware\ApplyXForwardedProto);

        // Error handling, which should always be near the "last" element.
        $errorMiddleware = $app->addErrorMiddleware(!$settings->isProduction(), true, true);
        $errorMiddleware->setDefaultErrorHandler(ErrorHandlerInterface::class);

        // Use PSR-7 compatible sessions.
        $app->add(Middleware\InjectSession::class);
    });
};