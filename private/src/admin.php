<?php
declare(strict_types=1);
require_once __DIR__ . '/admin-records.php';

function rgcCache_adminSettings(array $config): array
{
    $admin = $config['admin'] ?? null;
    $username = is_array($admin) ? ($admin['username'] ?? null) : null;
    $hash = is_array($admin) ? ($admin['password_hash'] ?? null) : null;
    $sessionName = is_array($admin) ? ($admin['session_name'] ?? 'rgcCacheAdmin') : 'rgcCacheAdmin';
    if (!is_string($username) || !preg_match('/^[A-Za-z0-9_.@-]{1,80}$/D', $username)
        || !is_string($hash) || $hash === '' || password_get_info($hash)['algo'] === null
        || !is_string($sessionName) || !preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,47}$/D', $sessionName)) {
        throw new rgcCache_Error('admin_configuration_unavailable', 503);
    }
    return ['username'=>$username, 'password_hash'=>$hash, 'session_name'=>$sessionName];
}

function rgcCache_adminSessionName(array $config): string
{
    $name = $config['admin']['session_name'] ?? 'rgcCacheAdmin';
    return is_string($name) && preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,47}$/D', $name) ? $name : 'rgcCacheAdmin';
}

function rgcCache_adminStartSession(array $config): void
{
    if (session_status() === PHP_SESSION_ACTIVE) { return; }
    $base = $config['base_path'] ?? '/rgccache';
    $path = is_string($base) && preg_match('~^(?:/[A-Za-z0-9_-]+)*$~D', $base) ? ($base ?: '/') : '/rgccache';
    $forwarded = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    $secure = (($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string)$_SERVER['HTTPS']) !== 'off') || $forwarded === 'https';
    session_name(rgcCache_adminSessionName($config));
    if (!session_start([
        'use_strict_mode'=>1,
        'use_only_cookies'=>1,
        'cookie_lifetime'=>0,
        'cookie_path'=>$path,
        'cookie_secure'=>$secure,
        'cookie_httponly'=>true,
        'cookie_samesite'=>'Strict',
        'cache_limiter'=>'nocache',
    ])) {
        throw new rgcCache_Error('admin_session_unavailable', 503);
    }
}

function rgcCache_adminIdentity(array $session): ?array
{
    $identity = $session['rgcCache_admin'] ?? null;
    if (!is_array($identity) || !is_string($identity['username'] ?? null)
        || !is_string($identity['csrf'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $identity['csrf'])
        || !is_int($identity['authenticated_at'] ?? null)) {
        return null;
    }
    return $identity;
}

function rgcCache_adminRequire(array $session, string $csrf = '', bool $requireCsrf = false): array
{
    $identity = rgcCache_adminIdentity($session);
    if ($identity === null) { throw new rgcCache_Error('admin_authentication_required', 401); }
    if ($requireCsrf && ($csrf === '' || !hash_equals($identity['csrf'], $csrf))) {
        throw new rgcCache_Error('admin_csrf_invalid', 403);
    }
    return $identity;
}

function rgcCache_adminCredentials(string $body): array
{
    if ($body === '' || strlen($body) > 16384) { throw new rgcCache_Error('invalid_admin_login'); }
    $input = rgcCache_json($body);
    if (!$input instanceof stdClass || array_diff(array_keys(get_object_vars($input)), ['username', 'password'])
        || !is_string($input->username ?? null) || !is_string($input->password ?? null)
        || strlen($input->username) > 80 || $input->password === '' || strlen($input->password) > 1024) {
        throw new rgcCache_Error('invalid_admin_login');
    }
    return ['username'=>$input->username, 'password'=>$input->password];
}

function rgcCache_adminObject(string $body, array $allowed): stdClass
{
    if ($body === '' || strlen($body) > 1048576) { throw new rgcCache_Error('invalid_admin_request'); }
    $input = rgcCache_json($body);
    if (!$input instanceof stdClass || array_diff(array_keys(get_object_vars($input)), $allowed)) {
        throw new rgcCache_Error('invalid_admin_request');
    }
    return $input;
}

function rgcCache_adminMappingSummary(array $mapping): array
{
    return [
        'id'=>$mapping['id'], 'status'=>$mapping['status'], 'hash'=>$mapping['hash'],
        'tables'=>$mapping['tables'], 'created_at'=>$mapping['created_at'],
        'updated_at'=>$mapping['updated_at'], 'activated_at'=>$mapping['activated_at'],
    ];
}

function rgcCache_adminRequest(string $route, array $config, string $body, string $csrf, array &$session, array $query = []): array
{
    $settings = rgcCache_adminSettings($config);
    if ($route === 'admin/session') {
        $identity = rgcCache_adminIdentity($session);
        return ['authenticated'=>$identity !== null] + ($identity === null ? [] : [
            'username'=>$identity['username'], 'csrf_token'=>$identity['csrf'],
        ]);
    }
    if ($route === 'admin/login') {
        $credentials = rgcCache_adminCredentials($body);
        if (!hash_equals($settings['username'], $credentials['username'])
            || !password_verify($credentials['password'], $settings['password_hash'])) {
            throw new rgcCache_Error('invalid_admin_credentials', 401);
        }
        if (session_status() === PHP_SESSION_ACTIVE && !session_regenerate_id(true)) {
            throw new rgcCache_Error('admin_session_unavailable', 503);
        }
        $session = ['rgcCache_admin'=>[
            'username'=>$settings['username'],
            'csrf'=>bin2hex(random_bytes(32)),
            'authenticated_at'=>time(),
        ]];
        $identity = $session['rgcCache_admin'];
        return [
            'authenticated'=>true,
            'username'=>$identity['username'],
            'csrf_token'=>$identity['csrf'],
            'password_rehash_recommended'=>password_needs_rehash($settings['password_hash'], PASSWORD_DEFAULT),
        ];
    }
    if ($route === 'admin/logout') {
        rgcCache_adminRequire($session, $csrf, true);
        $session = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            $parameters = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'=>time() - 42000,
                'path'=>$parameters['path'],
                'domain'=>$parameters['domain'],
                'secure'=>$parameters['secure'],
                'httponly'=>$parameters['httponly'],
                'samesite'=>$parameters['samesite'] ?? 'Strict',
            ]);
            session_destroy();
        }
        return ['authenticated'=>false];
    }
    rgcCache_adminRequire($session, $csrf, !in_array($route, ['admin/discover', 'admin/mappings', 'admin/import/status'], true));
    if ($route === 'admin/discover') {
        $config = rgcCache_syncSettings($config);
        return rgcCache_sourceMetadata($config, microtime(true) + $config['limits']['request_seconds']);
    }
    $recordInput = $route === 'admin/records/query' ? rgcCache_adminRecordInput($body) : null;
    $pdo = rgcCache_connect($config);
    if ($recordInput !== null) return rgcCache_adminRecords($pdo, $config, $recordInput);
    if ($route === 'admin/mappings') {
        return ['mappings'=>array_map('rgcCache_adminMappingSummary', rgcCache_mappingList($pdo, $config))];
    }
    if ($route === 'admin/mapping/draft') {
        $input = rgcCache_adminObject($body, ['id', 'tables']);
        if (!is_string($input->id ?? null) || !is_object($input->tables ?? null) && !is_array($input->tables ?? null)) {
            throw new rgcCache_Error('invalid_admin_request');
        }
        $tables = json_decode(json_encode($input->tables, JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR);
        try { $mapping = rgcCache_mappingSaveDraft($pdo, $config, $input->id, $tables); }
        catch (InvalidArgumentException|UnexpectedValueException $error) { throw new rgcCache_Error('invalid_mapping'); }
        catch (RuntimeException $error) { throw new rgcCache_Error('mapping_not_editable', 409); }
        return ['mapping'=>rgcCache_adminMappingSummary($mapping)];
    }
    if ($route === 'admin/mapping/activate') {
        $input = rgcCache_adminObject($body, ['id']);
        if (!is_string($input->id ?? null)) { throw new rgcCache_Error('invalid_admin_request'); }
        try { $mapping = rgcCache_mappingActivate($pdo, $config, $input->id); }
        catch (InvalidArgumentException $error) { throw new rgcCache_Error('invalid_mapping'); }
        catch (UnexpectedValueException $error) { throw new rgcCache_Error('mapping_integrity_failed', 409); }
        catch (RuntimeException $error) { throw new rgcCache_Error('mapping_not_ready', 409); }
        return ['mapping'=>rgcCache_adminMappingSummary($mapping)];
    }
    if ($route === 'admin/import/status') {
        $id = $query['id'] ?? null;
        if (!is_string($id)) { throw new rgcCache_Error('invalid_admin_request'); }
        $scoped = $config;
        $scoped['installation_id'] = rgcCache_mappingId($id);
        return rgcCache_importRequest('status', $scoped, false, $pdo);
    }
    if (in_array($route, ['admin/import/start', 'admin/import/step'], true)) {
        $allowed = $route === 'admin/import/start' ? ['id', 'source_writes_paused'] : ['id'];
        $input = rgcCache_adminObject($body, $allowed);
        if (!is_string($input->id ?? null) || ($route === 'admin/import/start' && ($input->source_writes_paused ?? null) !== true)) {
            throw new rgcCache_Error('invalid_admin_request');
        }
        $scoped = $config;
        $scoped['installation_id'] = rgcCache_mappingId($input->id);
        return rgcCache_importRequest($route === 'admin/import/start' ? 'start' : 'step', $scoped, $route === 'admin/import/start', $pdo);
    }
    throw new rgcCache_Error('admin_route_not_found', 404);
}
