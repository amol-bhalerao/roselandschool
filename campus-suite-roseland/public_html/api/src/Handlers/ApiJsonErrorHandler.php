<?php

declare(strict_types=1);

namespace BlogApi\Handlers;

use PDOException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpException;
use Throwable;

/**
 * Always JSON — this API is consumed only by JSON clients (SPAs).
 */
final class ApiJsonErrorHandler
{
    public function __construct(private ResponseFactoryInterface $responseFactory)
    {
    }

    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails
    ): ResponseInterface {
        $status = 500;
        if ($exception instanceof HttpException) {
            $status = $exception->getStatusCode();
            if ($status < 400 || $status > 599) {
                $status = 500;
            }
        }

        $publicMessage = $status < 500
            ? $exception->getMessage()
            : ($displayErrorDetails ? $exception->getMessage() : 'Internal server error');

        if (
            !$displayErrorDetails
            && $status >= 500
            && ($exception instanceof PDOException || str_contains($exception->getMessage(), 'SQLSTATE'))
        ) {
            $publicMessage = 'Database unavailable — start MySQL, check api/.env (DB_HOST, DB_USER, DB_PASS), then run php api/scripts/seed-database.php. GET /api/v1/health/db for details.';
        }

        $payload = ['error' => $publicMessage];
        if ($displayErrorDetails) {
            $payload['detail'] = $exception->getMessage();
            $payload['exception'] = $exception::class;
        }

        $response = $this->responseFactory->createResponse($status);
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
