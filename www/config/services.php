<?php

use App\Environment;
use Psr\Container\ContainerInterface;

return [
    Slim\Interfaces\RouteCollectorInterface::class => static function (Slim\App $app) {
        return $app->getRouteCollector();
    },

    Slim\Interfaces\RouteParserInterface::class => static function (
        Slim\Interfaces\RouteCollectorInterface $routeCollector
    ) {
        return $routeCollector->getRouteParser();
    },

    // URL Router helper
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
    Redis::class => static function (Environment $environment) {
        $settings = $environment->getRedisSettings();

        $redis = new Redis();
        $redis->connect($settings['host'], $settings['port'], 15);
        $redis->select($settings['db']);

        return $redis;
    },

    Symfony\Contracts\Cache\CacheInterface::class => static function (
        Environment $environment,
        Psr\Log\LoggerInterface $logger,
        ContainerInterface $di
    ) {
        /** @var Symfony\Contracts\Cache\CacheInterface $cacheInterface */
        if ($environment->isTesting()) {
            $cacheInterface = new Symfony\Component\Cache\Adapter\ArrayAdapter();
        } else {
            $tempDir = $environment->getTempDirectory() . DIRECTORY_SEPARATOR . 'cache';
            $cacheInterface = new Symfony\Component\Cache\Adapter\FilesystemAdapter(
                '',
                0,
                $tempDir
            );
        }

        $cacheInterface->setLogger($logger);
        return $cacheInterface;
    },

    Symfony\Component\Cache\Adapter\AdapterInterface::class => DI\get(
        Symfony\Contracts\Cache\CacheInterface::class
    ),
    Psr\Cache\CacheItemPoolInterface::class => DI\get(
        Symfony\Contracts\Cache\CacheInterface::class
    ),

    // DBAL
    Doctrine\DBAL\Connection::class => function (Doctrine\ORM\EntityManager $em) {
        return $em->getConnection();
    },

    // Console
    App\Console\Application::class => function (
        DI\Container $di,
        Azura\SlimCallableEventDispatcher\CallableEventDispatcherInterface $dispatcher
    ) {
        $console = new App\Console\Application('Command Line Interface', '1.0.0', $di);

        // Trigger an event for the core app and all plugins to build their CLI commands.
        $event = new App\Event\BuildConsoleCommands($console);
        $dispatcher->dispatch($event);

        return $console;
    },

    // Doctrine cache
    Doctrine\Common\Cache\Cache::class => static function (
        Environment $environment,
        Psr\Cache\CacheItemPoolInterface $psr6Cache
    ) {
        if ($environment->isCli()) {
            $psr6Cache = new Symfony\Component\Cache\Adapter\ArrayAdapter();
        }

        $proxyCache = new Symfony\Component\Cache\Adapter\ProxyAdapter($psr6Cache, 'doctrine.');
        return Doctrine\Common\Cache\Psr6\DoctrineProvider::wrap($proxyCache);
    },

    // Doctrine Entity Manager
    App\Doctrine\DecoratedEntityManager::class => static function (
        Doctrine\Common\Cache\Cache $doctrineCache,
        Environment $environment
    ) {
        $connectionOptions = array_merge(
            $environment->getDatabaseSettings(),
            [
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
            ]
        );

        try {
            // Fetch and store entity manager.
            $config = Doctrine\ORM\Tools\Setup::createConfiguration(
                Doctrine\Common\Proxy\AbstractProxyFactory::AUTOGENERATE_FILE_NOT_EXISTS,
                $environment->getTempDirectory() . '/proxies',
                $doctrineCache
            );

            $mappingClassesPaths = [$environment->getBaseDirectory() . '/src/Entity'];
            $attributeDriver = new Doctrine\ORM\Mapping\Driver\AttributeDriver(
                $mappingClassesPaths
            );
            $config->setMetadataDriverImpl($attributeDriver);

            // Debug mode:
            // $config->setSQLLogger(new Doctrine\DBAL\Logging\EchoSQLLogger);

            $eventManager = new Doctrine\Common\EventManager;

            return new App\Doctrine\DecoratedEntityManager(
                function () use (
                    $connectionOptions,
                    $config,
                    $eventManager
                ) {
                    return Doctrine\ORM\EntityManager::create($connectionOptions, $config, $eventManager);
                }
            );
        } catch (Exception $e) {
            throw new App\Exception\BootstrapException($e->getMessage());
        }
    },
    Doctrine\ORM\EntityManagerInterface::class => DI\Get(App\Doctrine\DecoratedEntityManager::class),

    // Event Dispatcher
    Azura\SlimCallableEventDispatcher\CallableEventDispatcherInterface::class => static function (
        Slim\App $app
    ) {
        $dispatcher = new Azura\SlimCallableEventDispatcher\SlimCallableEventDispatcher($app->getCallableResolver());

        // Register application default events.
        if (file_exists(__DIR__ . '/events.php')) {
            call_user_func(include(__DIR__ . '/events.php'), $dispatcher);
        }

        return $dispatcher;
    },
    Psr\EventDispatcher\EventDispatcherInterface::class => DI\get(
        Azura\SlimCallableEventDispatcher\CallableEventDispatcherInterface::class
    ),

    // Monolog Logger
    Monolog\Logger::class => static function (Environment $environment) {
        $logger = new Monolog\Logger($environment->getAppName());
        $loggingLevel = Psr\Log\LogLevel::INFO;

        if ($environment->isCli()) {
            $log_stderr = new Monolog\Handler\StreamHandler('php://stderr', $loggingLevel, true);
            $logger->pushHandler($log_stderr);
        }

        $log_file = new Monolog\Handler\RotatingFileHandler(
            $environment->getTempDirectory() . '/app.log',
            5,
            $loggingLevel,
            true
        );
        $logger->pushHandler($log_file);

        return $logger;
    },
    Psr\Log\LoggerInterface::class => DI\get(Monolog\Logger::class),

    // Doctrine annotations reader
    Doctrine\Common\Annotations\Reader::class => function (
        Doctrine\Common\Cache\Cache $doctrine_cache,
        Environment $environment
    ) {
        return new Doctrine\Common\Annotations\CachedReader(
            new Doctrine\Common\Annotations\AnnotationReader,
            $doctrine_cache,
            !$environment->isProduction()
        );
    },

    // Symfony Serializer
    Symfony\Component\Serializer\Serializer::class => static function (
        Doctrine\Common\Annotations\Reader $reader,
        Doctrine\ORM\EntityManagerInterface $em
    ) {
        $classMetaFactory = new Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory(
            new Symfony\Component\Serializer\Mapping\Loader\AnnotationLoader($reader)
        );

        $normalizers = [
            new Symfony\Component\Serializer\Normalizer\JsonSerializableNormalizer(),
            new App\Normalizer\DoctrineEntityNormalizer($em, $classMetaFactory),
            new Symfony\Component\Serializer\Normalizer\ObjectNormalizer($classMetaFactory),
        ];
        $encoders = [
            new Symfony\Component\Serializer\Encoder\JsonEncoder,
        ];

        return new Symfony\Component\Serializer\Serializer($normalizers, $encoders);
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

    // Symfony Lock adapter
    Symfony\Component\Lock\PersistingStoreInterface::class => static function (
        ContainerInterface $di
    ) {
        $redis = $di->get(Redis::class);
        return new Symfony\Component\Lock\Store\RedisStore($redis);
    },
];
