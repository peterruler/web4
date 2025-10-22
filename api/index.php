<?php

declare(strict_types=1);

use JsonServer\Config;
use JsonServer\JsonServer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

require __DIR__ . '/../vendor/autoload.php';

$isDebug = filter_var(
    $_ENV['API_DEBUG']
        ?? $_ENV['APP_DEBUG']
        ?? getenv('API_DEBUG')
        ?? getenv('APP_DEBUG')
        ?? false,
    FILTER_VALIDATE_BOOLEAN
);

error_reporting($isDebug ? E_ALL : E_ALL & ~E_DEPRECATED);
ini_set('display_errors', $isDebug ? '1' : '0');
ini_set('display_startup_errors', $isDebug ? '1' : '0');

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowCredentials = filter_var(
    $_ENV['API_ALLOW_CREDENTIALS']
        ?? getenv('API_ALLOW_CREDENTIALS')
        ?? false,
    FILTER_VALIDATE_BOOLEAN
);

if ($requestOrigin !== '') {
    header('Access-Control-Allow-Origin: ' . $requestOrigin);
    header('Vary: Origin');
} else {
    header('Access-Control-Allow-Origin: *');
}

header('Access-Control-Allow-Headers: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
// header('Access-Control-Max-Age: 600');
if ($allowCredentials) {
    header('Access-Control-Allow-Credentials: true');
}

if ($requestMethod === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$request = Request::createFromGlobals();
$method = $request->getMethod();

$path = trim($request->getPathInfo(), '/');

if ($path === '') {
    $payload = [
        'service' => 'php-json-server',
        'status' => 'ready',
        'docs' => 'https://packagist.org/packages/zlob/php-json-server',
        'routes' => [
            'GET /api/Project',
            'GET /api/Project/{id}',
            'GET /api/Issue',
            'GET /api/Issue?projectId={uuid}',
            'POST /api/{Resource}',
            'PUT|PATCH /api/{Resource}/{id}',
            'DELETE /api/{Resource}/{id}',
        ],
    ];

    $response = new JsonResponse($payload, Response::HTTP_OK);
    $response->send();
    return;
}

$serverMethod = $method === Request::METHOD_HEAD ? Request::METHOD_GET : $method;

Config::set('pathToDb', '/../../../php-json-server/db/db.json');

$server = new JsonServer();

$contentType = strtolower($request->headers->get('Content-Type', ''));
$body = $request->request->all();

if ($contentType !== '' && strpos($contentType, 'application/json') !== false) {
    $raw = (string) $request->getContent();
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            $response = new JsonResponse(
                [
                    'error' => 'Invalid JSON payload',
                    'detail' => json_last_error_msg(),
                ],
                Response::HTTP_BAD_REQUEST
            );
            $response->send();
            return;
        }
        if (is_array($decoded)) {
            $body = $decoded;
        }
    }
}

$queryParams = $request->query->all();

if ($serverMethod === Request::METHOD_GET && $queryParams !== []) {
    (static function (array $filters): void {
        $this->data = $filters;
    })->call($server, $queryParams);
}

$input = $serverMethod === Request::METHOD_GET
    ? $queryParams
    : array_replace_recursive($queryParams, $body);

try {
    $response = $server->handleRequest($serverMethod, $path, $input);
} catch (\BadFunctionCallException $exception) {
    $payload = [
        'error' => 'Unsupported request',
    ];
    if ($isDebug) {
        $payload['detail'] = $exception->getMessage();
    }
    $response = new JsonResponse($payload, Response::HTTP_BAD_REQUEST);
} catch (\Throwable $exception) {
    $payload = [
        'error' => 'Unexpected server error',
    ];
    if ($isDebug) {
        $payload['detail'] = $exception->getMessage();
    }
    $response = new JsonResponse($payload, Response::HTTP_INTERNAL_SERVER_ERROR);
}

$exposedHeaders = [
    'Access-Control-Allow-Origin' => $requestOrigin !== '' ? $requestOrigin : '*',
    'Access-Control-Allow-Headers' => '*',
    'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
];
if ($requestOrigin !== '') {
    $exposedHeaders['Vary'] = 'Origin';
}
if ($allowCredentials) {
    $exposedHeaders['Access-Control-Allow-Credentials'] = 'true';
}

foreach ($exposedHeaders as $header => $value) {
    $response->headers->set($header, $value);
}

if ($method === Request::METHOD_HEAD) {
    $response->setContent('');
}

$response->send();
