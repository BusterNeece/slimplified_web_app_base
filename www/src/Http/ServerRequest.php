<?php
namespace App\Http;

use App\Exception;
use App\RateLimit;
use App\Session;
use App\View;
use Mezzio\Session\SessionInterface;

final class ServerRequest extends \Slim\Http\ServerRequest
{
    public const ATTR_VIEW = 'app_view';
    public const ATTR_SESSION = 'app_session';
    public const ATTR_SESSION_CSRF = 'app_session_csrf';
    public const ATTR_SESSION_FLASH = 'app_session_flash';
    public const ATTR_ROUTER = 'app_router';
    public const ATTR_RATE_LIMIT = 'app_rate_limit';

    public function getView(): View
    {
        return $this->getAttributeOfClass(self::ATTR_VIEW, View::class);
    }

    public function getSession(): SessionInterface
    {
        return $this->getAttributeOfClass(self::ATTR_SESSION, SessionInterface::class);
    }

    public function getCsrf(): Session\Csrf
    {
        return $this->getAttributeOfClass(self::ATTR_SESSION_CSRF, Session\Csrf::class);
    }

    public function getFlash(): Session\Flash
    {
        return $this->getAttributeOfClass(self::ATTR_SESSION_FLASH, Session\Flash::class);
    }

    public function getRouter(): RouterInterface
    {
        return $this->getAttributeOfClass(self::ATTR_ROUTER, RouterInterface::class);
    }

    public function getRateLimit(): RateLimit
    {
        return $this->getAttributeOfClass(self::ATTR_RATE_LIMIT, RateLimit::class);
    }

    /**
     * @param string $attr
     * @param string $class_name
     *
     * @return mixed
     * @throws Exception\InvalidRequestAttribute
     */
    private function getAttributeOfClass(string $attr, string $class_name): mixed
    {
        $object = $this->serverRequest->getAttribute($attr);

        if (empty($object)) {
            throw new Exception\InvalidRequestAttribute(sprintf(
                'Attribute "%s" is required and is empty in this request',
                $attr
            ));
        }

        if (!($object instanceof $class_name)) {
            throw new Exception\InvalidRequestAttribute(sprintf(
                'Attribute "%s" must be of type "%s".',
                $attr,
                $class_name
            ));
        }

        return $object;
    }

    /**
     * Get the remote user's IP address as indicated by HTTP headers.
     * @return string|null
     */
    public function getIp(): ?string
    {
        $params = $this->serverRequest->getServerParams();

        return $params['HTTP_CLIENT_IP']
            ?? $params['HTTP_X_FORWARDED_FOR']
            ?? $params['HTTP_X_FORWARDED']
            ?? $params['HTTP_FORWARDED_FOR']
            ?? $params['HTTP_FORWARDED']
            ?? $params['REMOTE_ADDR']
            ?? null;
    }
}
