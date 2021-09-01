<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Environment;
use App\Http\ServerRequest;
use App\Session\Csrf;
use App\Session\Flash;
use Mezzio\Session\Cache\CacheSessionPersistence;
use Mezzio\Session\LazySession;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\ProxyAdapter;

/**
 * Inject the session object into the request.
 */
class InjectSession implements MiddlewareInterface
{
    protected CacheItemPoolInterface $cachePool;

    public function __construct(
        CacheItemPoolInterface $cachePool,
        protected Environment $environment
    ) {
        if ($environment->isCli()) {
            $cachePool = new ArrayAdapter();
        }

        $this->cachePool = new ProxyAdapter($cachePool, 'session.');
    }

    /**
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $isHttpsUrl = ('https' === $request->getUri()->getScheme());

        $sessionPersistence = new CacheSessionPersistence(
            cache: $this->cachePool,
            cookieName: 'app_session',
            cookiePath: '/',
            cacheLimiter: 'nocache',
            cacheExpire: 43200,
            lastModified: time(),
            persistent: true,
            cookieSecure: $isHttpsUrl,
            cookieHttpOnly: true
        );

        $session = new LazySession($sessionPersistence, $request);

        $csrf = new Csrf($session, $this->environment);
        Csrf::setInstance($csrf);

        $flash = new Flash($session);
        Flash::setInstance($flash);

        $request = $request->withAttribute(ServerRequest::ATTR_SESSION, $session)
            ->withAttribute(ServerRequest::ATTR_SESSION_CSRF, $csrf)
            ->withAttribute(ServerRequest::ATTR_SESSION_FLASH, $flash);

        $response = $handler->handle($request);
        return $sessionPersistence->persistSession($session, $response);
    }
}
