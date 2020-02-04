<?php

return [
    // URL Router helper
    App\Http\Router::class => function (\Slim\App $app, App\Settings $settings) {
        $route_parser = $app->getRouteCollector()->getRouteParser();
        return new App\Http\Router($settings, $route_parser);
    },
    App\Http\RouterInterface::class => DI\Get(App\Http\Router::class),

    // Error handler
    App\Http\ErrorHandler::class => DI\autowire(),
    Slim\Interfaces\ErrorHandlerInterface::class => DI\Get(App\Http\ErrorHandler::class),

    // HTTP client
    GuzzleHttp\Client::class => function (Psr\Log\LoggerInterface $logger) {
        $stack = GuzzleHttp\HandlerStack::create();

        $stack->unshift(function (callable $handler) {
            return function (Psr\Http\Message\RequestInterface $request, array $options) use ($handler) {
                $options[GuzzleHttp\RequestOptions::VERIFY] = Composer\CaBundle\CaBundle::getSystemCaRootBundlePath();
                return $handler($request, $options);
            };
        }, 'ssl_verify');

        $stack->push(GuzzleHttp\Middleware::log(
            $logger,
            new GuzzleHttp\MessageFormatter('HTTP client {method} call to {uri} produced response {code}'),
            Monolog\Logger::DEBUG
        ));

        return new GuzzleHttp\Client([
            'handler' => $stack,
            GuzzleHttp\RequestOptions::HTTP_ERRORS => false,
            GuzzleHttp\RequestOptions::TIMEOUT => 3.0,
        ]);
    },

    // Cache
    Psr\Cache\CacheItemPoolInterface::class => function (App\Settings $settings, Psr\Container\ContainerInterface $di) {
        // Never use the Redis cache for CLI commands, as the CLI commands are where
        // the Redis cache gets flushed, so this will lead to a race condition that can't
        // be solved within the application.
        return $settings->enableRedis() && !$settings->isCli()
            ? new Cache\Adapter\Redis\RedisCachePool($di->get(Redis::class))
            : new Cache\Adapter\PHPArray\ArrayCachePool;
    },
    Psr\SimpleCache\CacheInterface::class => DI\get(Psr\Cache\CacheItemPoolInterface::class),

    // Configuration management
    App\Config::class => function (App\Settings $settings) {
        return new App\Config($settings[App\Settings::CONFIG_DIR]);
    },

    // DBAL
    Doctrine\DBAL\Connection::class => function (Doctrine\ORM\EntityManager $em) {
        return $em->getConnection();
    },
    'db' => DI\Get(Doctrine\DBAL\Connection::class),

    // Console
    App\Console\Application::class => function (DI\Container $di, App\EventDispatcher $dispatcher) {
        $console = new App\Console\Application('Command Line Interface', '1.0.0', $di);

        // Trigger an event for the core app and all plugins to build their CLI commands.
        $event = new App\Event\BuildConsoleCommands($console);
        $dispatcher->dispatch($event);

        return $console;
    },

    // Doctrine cache
    Doctrine\Common\Cache\Cache::class => function (Psr\Cache\CacheItemPoolInterface $cachePool) {
        return new Cache\Bridge\Doctrine\DoctrineCacheBridge(new Cache\Prefixed\PrefixedCachePool($cachePool, 'doctrine|'));
    },

    // Doctrine Entity Manager
    Doctrine\ORM\EntityManager::class => function (
        Doctrine\Common\Cache\Cache $doctrine_cache,
        Doctrine\Common\Annotations\Reader $reader,
        App\Settings $settings
    ) {
        $defaults = [
            'cache' => $doctrine_cache,
            'autoGenerateProxies' => !$settings->isProduction(),
            'proxyNamespace' => 'AppProxy',
            'proxyPath' => $settings->getTempDirectory() . '/proxies',
            'modelPath' => $settings->getBaseDirectory() . '/src/Entity',
            'useSimpleAnnotations' => false,
            'conn' => [
                'host' => $_ENV['MYSQL_HOST'] ?? 'mariadb',
                'port' => $_ENV['MYSQL_PORT'] ?? 3306,
                'dbname' => $_ENV['MYSQL_DATABASE'],
                'user' => $_ENV['MYSQL_USER'],
                'password' => $_ENV['MYSQL_PASSWORD'],
                'driver' => 'pdo_mysql',
                'charset' => 'utf8mb4',
                'defaultTableOptions' => [
                    'charset' => 'utf8mb4',
                    'collate' => 'utf8mb4_general_ci',
                ],
                'driverOptions' => [
                    // PDO::MYSQL_ATTR_INIT_COMMAND = 1002;
                    1002 => 'SET NAMES utf8mb4 COLLATE utf8mb4_general_ci',
                ],
                'platform' => new Doctrine\DBAL\Platforms\MariaDb1027Platform(),
            ],
        ];

        if (!$settings[App\Settings::IS_DOCKER]) {
            $defaults['conn']['host'] = $_ENV['db_host'] ?? 'localhost';
            $defaults['conn']['port'] = $_ENV['db_port'] ?? '3306';
            $defaults['conn']['dbname'] = $_ENV['db_name'] ?? 'app';
            $defaults['conn']['user'] = $_ENV['db_username'] ?? 'app';
            $defaults['conn']['password'] = $_ENV['db_password'];
        }

        $app_options = $settings[App\Settings::DOCTRINE_OPTIONS] ?? [];
        $options = array_merge($defaults, $app_options);

        try {
            // Fetch and store entity manager.
            $config = new Doctrine\ORM\Configuration;

            if ($options['useSimpleAnnotations']) {
                $metadata_driver = $config->newDefaultAnnotationDriver((array)$options['modelPath'],
                    $options['useSimpleAnnotations']);
            } else {
                $metadata_driver = new Doctrine\ORM\Mapping\Driver\AnnotationDriver(
                    $reader,
                    (array)$options['modelPath']
                );
            }
            $config->setMetadataDriverImpl($metadata_driver);

            $config->setMetadataCacheImpl($options['cache']);
            $config->setQueryCacheImpl($options['cache']);
            $config->setResultCacheImpl($options['cache']);

            $config->setProxyDir($options['proxyPath']);
            $config->setProxyNamespace($options['proxyNamespace']);
            $config->setAutoGenerateProxyClasses(Doctrine\Common\Proxy\AbstractProxyFactory::AUTOGENERATE_FILE_NOT_EXISTS);

            if (isset($options['conn']['debug']) && $options['conn']['debug']) {
                $config->setSQLLogger(new Doctrine\DBAL\Logging\EchoSQLLogger);
            }

            $config->addCustomNumericFunction('RAND', App\Doctrine\Functions\Rand::class);

            return Doctrine\ORM\EntityManager::create($options['conn'], $config, new Doctrine\Common\EventManager);
        } catch (Exception $e) {
            throw new App\Exception\BootstrapException($e->getMessage());
        }
    },
    'em' => DI\Get(Doctrine\ORM\EntityManager::class),

    // Event Dispatcher
    App\EventDispatcher::class => function (Slim\App $app) {
        $dispatcher = new App\EventDispatcher($app->getCallableResolver());

        // Register application default events.
        if (file_exists(__DIR__ . '/events.php')) {
            call_user_func(include(__DIR__ . '/events.php'), $dispatcher);
        }

        return $dispatcher;
    },

    // Monolog Logger
    Monolog\Logger::class => function (App\Settings $settings) {
        $logger = new Monolog\Logger($settings[App\Settings::APP_NAME] ?? 'app');
        $logging_level = $settings->isProduction() ? Psr\Log\LogLevel::INFO : Psr\Log\LogLevel::DEBUG;

        if ($settings[App\Settings::IS_DOCKER] || $settings[App\Settings::IS_CLI]) {
            $log_stderr = new Monolog\Handler\StreamHandler('php://stderr', $logging_level, true);
            $logger->pushHandler($log_stderr);
        }

        $log_file = new Monolog\Handler\StreamHandler($settings[App\Settings::TEMP_DIR] . '/app.log',
            $logging_level, true);
        $logger->pushHandler($log_file);

        return $logger;
    },
    Psr\Log\LoggerInterface::class => DI\get(Monolog\Logger::class),

    // Middleware
    App\Middleware\InjectRateLimit::class => DI\autowire(),
    App\Middleware\InjectRouter::class => DI\autowire(),
    App\Middleware\InjectSession::class => DI\autowire(),
    App\Middleware\EnableView::class => DI\autowire(),

    // Session save handler middleware
    Mezzio\Session\SessionPersistenceInterface::class => function (Cache\Adapter\Redis\RedisCachePool $redisPool) {
        return new Mezzio\Session\Cache\CacheSessionPersistence(
            new Cache\Prefixed\PrefixedCachePool($redisPool, 'session|'),
            'app_session',
            '/',
            'nocache',
            43200,
            time()
        );
    },

    // Rate limiter
    App\RateLimit::class => DI\autowire(),

    // Redis cache
    Redis::class => function (App\Settings $settings) {
        $redis_host = $settings[App\Settings::IS_DOCKER] ? 'redis' : 'localhost';

        $redis = new Redis();
        $redis->connect($redis_host, 6379, 15);
        $redis->select(1);

        return $redis;
    },

    // View (Plates Templates)
    App\View::class => function (
        Psr\Container\ContainerInterface $di,
        App\Settings $settings,
        App\Http\RouterInterface $router,
        App\EventDispatcher $dispatcher
    ) {
        $view = new App\View($settings[App\Settings::VIEWS_DIR], 'phtml');

        $view->registerFunction('service', function ($service) use ($di) {
            return $di->get($service);
        });

        $view->registerFunction('escapeJs', function ($string) {
            return json_encode($string, JSON_THROW_ON_ERROR, 512);
        });

        $view->addData([
            'settings' => $settings,
            'router' => $router,
        ]);

        $dispatcher->dispatch(new App\Event\BuildView($view));

        return $view;
    },

    // Doctrine annotations reader
    Doctrine\Common\Annotations\Reader::class => function (Doctrine\Common\Cache\Cache $doctrine_cache, App\Settings $settings) {
        return new Doctrine\Common\Annotations\CachedReader(
            new \Doctrine\Common\Annotations\AnnotationReader,
            $doctrine_cache,
            !$settings->isProduction()
        );
    },

    // Symfony Serializer
    Symfony\Component\Serializer\Serializer::class => function (
        Doctrine\Common\Annotations\Reader $annotation_reader,
        Doctrine\ORM\EntityManager $em
    ) {
        $meta_factory = new Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory(
            new Symfony\Component\Serializer\Mapping\Loader\AnnotationLoader($annotation_reader)
        );

        $normalizers = [
            new Symfony\Component\Serializer\Normalizer\JsonSerializableNormalizer(),
            new App\Normalizer\DoctrineEntityNormalizer($em, $annotation_reader, $meta_factory),
            new Symfony\Component\Serializer\Normalizer\ObjectNormalizer($meta_factory),
        ];
        return new Symfony\Component\Serializer\Serializer($normalizers);
    },

    // Symfony Validator
    Symfony\Component\Validator\ConstraintValidatorFactoryInterface::class => DI\autowire(App\Validator\ConstraintValidatorFactory::class),

    Symfony\Component\Validator\Validator\ValidatorInterface::class => function (
        Doctrine\Common\Annotations\Reader $annotation_reader,
        Symfony\Component\Validator\ConstraintValidatorFactoryInterface $cvf
    ) {
        $builder = new Symfony\Component\Validator\ValidatorBuilder();
        $builder->setConstraintValidatorFactory($cvf);
        $builder->enableAnnotationMapping($annotation_reader);
        return $builder->getValidator();
    },
];
