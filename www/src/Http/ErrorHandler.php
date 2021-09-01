<?php
namespace App\Http;

use App\Environment;
use App\Exception;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LogLevel;
use Slim\App;
use Slim\Exception\HttpException;
use Throwable;

class ErrorHandler extends \Slim\Handlers\ErrorHandler
{
    protected bool $returnJson = false;

    protected bool $showDetailed = false;

    protected string $loggerLevel = LogLevel::ERROR;

    protected RouterInterface $router;

    protected Environment $environment;

    public function __construct(
        App $app,
        Logger $logger,
        RouterInterface $router,
        Environment $environment
    ) {
        parent::__construct($app->getCallableResolver(), $app->getResponseFactory(), $logger);

        $this->environment = $environment;
        $this->router = $router;
    }

    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails
    ): ResponseInterface {
        if ($exception instanceof Exception) {
            $this->loggerLevel = $exception->getLoggerLevel();
        } elseif ($exception instanceof HttpException) {
            $this->loggerLevel = LogLevel::WARNING;
        }

        $this->showDetailed = (!$this->environment->isProduction() && !in_array($this->loggerLevel,
                [LogLevel::DEBUG, LogLevel::INFO, LogLevel::NOTICE], true));
        $this->returnJson = $this->shouldReturnJson($request);

        return parent::__invoke($request, $exception, $displayErrorDetails, $logErrors, $logErrorDetails);
    }

    protected function shouldReturnJson(ServerRequestInterface $req): bool
    {
        $xhr = $req->getHeaderLine('X-Requested-With') === 'XMLHttpRequest';

        if ($xhr || $this->environment->isCli() || $this->environment->isTesting()) {
            return true;
        }

        if ($req->hasHeader('Accept')) {
            $accept = $req->getHeader('Accept');
            if (in_array('application/json', $accept)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @inheritDoc
     */
    protected function writeToErrorLog(): void
    {
        $context = [
            'file' => $this->exception->getFile(),
            'line' => $this->exception->getLine(),
            'code' => $this->exception->getCode(),
        ];

        if ($this->exception instanceof Exception) {
            $context['context'] = $this->exception->getLoggingContext();
            $context = array_merge($context, $this->exception->getExtraData());
        }

        if ($this->showDetailed) {
            $context['trace'] = array_slice($this->exception->getTrace(), 0, 5);
        }

        $this->logger->log($this->loggerLevel, $this->exception->getMessage(), [
            'file' => $this->exception->getFile(),
            'line' => $this->exception->getLine(),
            'code' => $this->exception->getCode(),
        ]);
    }

    protected function respond(): ResponseInterface
    {
        // Special handling for cURL requests.
        $ua = $this->request->getHeaderLine('User-Agent');

        if (false !== stripos($ua, 'curl')) {
            $response = $this->responseFactory->createResponse($this->statusCode);

            $response->getBody()
                ->write('Error: ' . $this->exception->getMessage() . ' on ' . $this->exception->getFile() . ' L' . $this->exception->getLine());

            return $response;
        }

        if ($this->returnJson) {
            $response = $this->responseFactory->createResponse($this->statusCode);

            return $this->withJson($response, [
                'success' => false,
                'message' => $this->exception->getMessage(),
                'code' => $this->exception->getCode(),
            ]);
        }

        return parent::respond();
    }

    protected function withJson(ResponseInterface $response, $data): ResponseInterface
    {
        $json = (string)json_encode($data, JSON_THROW_ON_ERROR);
        $response->getBody()->write($json);

        return $response->withHeader('Content-Type', 'application/json;charset=utf-8');
    }
}
