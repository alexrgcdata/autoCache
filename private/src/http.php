<?php
declare(strict_types=1);
require_once __DIR__ . '/sync.php';
require_once __DIR__ . '/import.php';
require_once __DIR__ . '/admin.php';
require_once __DIR__ . '/contact.php';

function rgcCache_config(): array
{
    $path = dirname(__DIR__) . '/config.php';
    if (!is_file($path)) {
        throw new RuntimeException('Configuration unavailable');
    }
    $config = require $path;
    if (!is_array($config)) {
        throw new RuntimeException('Configuration invalid');
    }
    return $config;
}

function rgcCache_response(int $status, array $body, array $headers = []): array
{
    return ['status' => $status, 'body' => $body, 'headers' => $headers];
}

function rgcCache_dispatch(string $method, string $uri, string $authorization, ?array $config, string|callable $body = '', string $delivery = '', string $contentType = 'application/json', ?callable $service = null, string $csrf = '', ?array &$session = null): array
{
    $base = $config['base_path'] ?? '/rgccache';
    if (!is_string($base) || !preg_match('~^(?:/[A-Za-z0-9_-]+)*$~D', $base)) {
        return rgcCache_response(503, ['error' => 'Configuration unavailable']);
    }
    $path = parse_url($uri, PHP_URL_PATH);
    $prefix = $base . '/api/v1/';
    if (!is_string($path) || !str_starts_with($path, $prefix)) {
        return rgcCache_response(404, ['error' => 'Not found']);
    }
    $route = substr($path, strlen($prefix));
    $methods = ['health' => 'GET', 'ready' => 'GET', 'status' => 'GET', 'sync' => 'POST', 'process' => 'POST', 'import/start'=>'POST', 'import/step'=>'POST', 'import/status'=>'GET', 'import/discover'=>'GET',
        'admin/login'=>'POST', 'admin/logout'=>'POST', 'admin/session'=>'GET', 'admin/contact/session'=>'GET', 'admin/contact/send'=>'POST', 'admin/discover'=>'GET', 'admin/mappings'=>'GET',
        'admin/mapping/draft'=>'POST', 'admin/mapping/activate'=>'POST', 'admin/import/start'=>'POST', 'admin/import/step'=>'POST', 'admin/import/status'=>'GET', 'admin/records/query'=>'POST'];
    if (!isset($methods[$route])) {
        return rgcCache_response(404, ['error' => 'Not found']);
    }
    if ($method !== $methods[$route]) {
        return rgcCache_response(405, ['error' => 'Method not allowed'], ['Allow' => $methods[$route]]);
    }
    if ($route === 'health') {
        return rgcCache_response(200, ['application' => 'rgcCache', 'api_version' => 'v1', 'status' => 'ok', 'stage' => 'sync']);
    }
    if (in_array($route, ['admin/contact/session', 'admin/contact/send'], true)) {
        try {
            if ($route === 'admin/contact/send' && strtolower(trim(explode(';', $contentType)[0])) !== 'application/json') {
                throw new rgcCache_Error('application_json_required', 415);
            }
            if ($session === null) { throw new rgcCache_Error('contact_session_unavailable', 503); }
            if ($route === 'admin/contact/session') {
                return rgcCache_response(200, rgcCache_contactSession($session));
            }
            $text = is_callable($body) ? $body() : $body;
            return rgcCache_response(200, rgcCache_contactSend($text, $csrf, $session, $config ?? [], null));
        } catch (rgcCache_Error $error) {
            return rgcCache_response($error->httpStatus, ['error'=>$error->reason]);
        } catch (Throwable $error) {
            return rgcCache_response(503, ['error'=>'contact_service_unavailable']);
        }
    }
    if (str_starts_with($route, 'admin/')) {
        try {
            $adminJson = in_array($route, ['admin/login', 'admin/mapping/draft', 'admin/mapping/activate', 'admin/import/start', 'admin/import/step', 'admin/records/query'], true);
            if ($adminJson && strtolower(trim(explode(';', $contentType)[0])) !== 'application/json') {
                throw new rgcCache_Error('application_json_required', 415);
            }
            $text = $adminJson ? (is_callable($body) ? $body() : $body) : '';
            if ($session === null) { throw new rgcCache_Error('admin_session_unavailable', 503); }
            $query = [];
            $rawQuery = parse_url($uri, PHP_URL_QUERY);
            if (is_string($rawQuery)) { parse_str($rawQuery, $query); }
            return rgcCache_response(200, rgcCache_adminRequest($route, $config, $text, $csrf, $session, $query));
        } catch (rgcCache_Error $error) {
            return rgcCache_response($error->httpStatus, ['error'=>$error->reason]);
        } catch (Throwable $error) {
            return rgcCache_response(503, ['error'=>'admin_service_unavailable']);
        }
    }
    $token = $config['webhook_token'] ?? '';
    if (!is_string($token) || strlen($token) < 32 || preg_match('/\s/', $token)) {
        return rgcCache_response(503, ['error' => 'Configuration unavailable']);
    }
    if (!hash_equals('Bearer ' . $token, $authorization)) {
        return rgcCache_response(401, ['error' => 'Unauthorized'], ['WWW-Authenticate' => 'Bearer']);
    }
    try {
        if (in_array($route,['sync','import/start'],true) && strtolower(trim(explode(';', $contentType)[0])) !== 'application/json') {
            throw new rgcCache_Error('application_json_required', 415);
        }
        $text = in_array($route,['sync','import/start'],true) ? (is_callable($body) ? $body() : $body) : '';
        if (str_starts_with($route,'import/')) {
            $paused=false;
            if ($route==='import/start') { $request=rgcCache_json($text); $paused=$request instanceof stdClass && ($request->source_writes_paused ?? false)===true; }
            return rgcCache_response(200,rgcCache_importRequest(substr($route,7),$config,$paused));
        }
        return $service ? $service($route, $config, $text, $delivery) : rgcCache_syncRequest($route, $config, $text, $delivery);
    } catch (rgcCache_Error $error) {
        return rgcCache_response($error->httpStatus, ['error'=>$error->reason, 'retry_required'=>in_array($route, ['sync','process'], true)], $error->httpStatus === 503 ? ['Retry-After'=>'5'] : []);
    } catch (Throwable $error) {
        return rgcCache_response(503, ['error'=>'configuration_or_database_unavailable', 'retry_required'=>in_array($route, ['sync','process'], true)], ['Retry-After'=>'5']);
    }
}

function rgcCache_run(?array $configured = null, ?callable $service = null): void
{
    ini_set('display_errors', '0');
    try {
        try {
            $config = $configured ?? rgcCache_config();
        } catch (Throwable $error) {
            $config = null;
        }
        $session = null;
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        $requestPath = parse_url($requestUri, PHP_URL_PATH);
        $adminPrefix = is_array($config) && is_string($config['base_path'] ?? null) ? $config['base_path'] . '/api/v1/admin/' : '/rgccache/api/v1/admin/';
        if (is_string($requestPath) && str_starts_with($requestPath, $adminPrefix)) {
            rgcCache_adminStartSession($config ?? []);
            $session =& $_SESSION;
        }
        $result = rgcCache_dispatch(
            $_SERVER['REQUEST_METHOD'] ?? 'GET',
            $requestUri,
            $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '',
            $config,
            static function () use ($config): string {
                $limit = $config['limits']['notification_bytes'] ?? 1048576;
                if (!is_int($limit) || $limit < 1 || $limit > 8388608) { throw new rgcCache_Error('invalid_limits', 503); }
                $input = fopen('php://input', 'rb');
                if (!$input) { throw new rgcCache_Error('request_body_unavailable'); }
                try { $body = stream_get_contents($input, $limit + 1); }
                finally { fclose($input); }
                if ($body === false) { throw new rgcCache_Error('request_body_unavailable'); }
                if (strlen($body) > $limit) { throw new rgcCache_Error('notification_too_large', 413); }
                return $body;
            },
            $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '',
            $_SERVER['CONTENT_TYPE'] ?? '',
            $service,
            $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '',
            $session
        );
    } catch (Throwable $error) {
        $result = rgcCache_response(500, ['error' => 'Request failed']);
    }
    http_response_code($result['status']);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    foreach ($result['headers'] as $name => $value) {
        header($name . ': ' . $value);
    }
    echo json_encode($result['body'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}
