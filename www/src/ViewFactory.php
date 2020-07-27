<?php
namespace App;

use App\Http\ServerRequest;
use Doctrine\Inflector\InflectorFactory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;

class ViewFactory
{
    protected ContainerInterface $di;

    protected Settings $settings;

    protected EventDispatcher $dispatcher;

    protected Assets $assets;

    public function __construct(
        ContainerInterface $di,
        Settings $settings,
        EventDispatcher $dispatcher,
        Assets $assets
    ) {
        $this->di = $di;
        $this->settings = $settings;
        $this->dispatcher = $dispatcher;
        $this->assets = $assets;
    }

    public function create(?ServerRequestInterface $request = null): View
    {
        $view = new View($this->settings[Settings::VIEWS_DIR], 'phtml');

        // Add non-request-dependent content.
        $view->addData([
            'settings' => $this->settings,
            'assets' => $this->assets,
        ]);

        // Add request-dependent content.
        if (null !== $request) {
            $view->addData([
                'request' => $request,
                'router' => $request->getAttribute(ServerRequest::ATTR_ROUTER),
                'flash' => $request->getAttribute(ServerRequest::ATTR_SESSION_FLASH),
            ]);
        }

        $view->registerFunction('service', function ($service) {
            return $this->di->get($service);
        });

        $view->registerFunction('escapeJs', function ($string) {
            return json_encode($string, JSON_THROW_ON_ERROR, 512);
        });

        $view->registerFunction('mailto', function ($address, $link_text = null) {
            $address = substr(chunk_split(bin2hex(" $address"), 2, ';&#x'), 3, -3);
            $link_text = $link_text ?? $address;
            return '<a href="mailto:' . $address . '">' . $link_text . '</a>';
        });

        $view->registerFunction('pluralize', function ($word, $num = 0) {
            if ((int)$num === 1) {
                return $word;
            }

            $inflector = InflectorFactory::create()->build();
            return $inflector->pluralize($word);
        });

        $this->dispatcher->dispatch(new Event\BuildView($view));

        return $view;

    }
}