<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/private/src/http.php';

function rgcCache_adminTest(bool $condition, string $label): void
{
    if (!$condition) { throw new RuntimeException($label); }
    echo 'PASS: ', $label, PHP_EOL;
}

$config = [
    'base_path'=>'/rgccache',
    'admin'=>[
        'username'=>'alex',
        'password_hash'=>password_hash('correct horse battery staple', PASSWORD_DEFAULT),
        'session_name'=>'rgcCacheAdminTest',
    ],
];
$session = [];
$call = static function (string $method, string $route, string $body = '', string $csrf = '') use ($config, &$session): array {
    return rgcCache_dispatch($method, '/rgccache/api/v1/' . $route, '', $config, $body, '', 'application/json', null, $csrf, $session);
};

$result = $call('GET', 'admin/session');
rgcCache_adminTest($result['status'] === 200 && $result['body']['authenticated'] === false, 'anonymous session state');
$result = $call('POST', 'admin/login', '{"username":"alex","password":"wrong"}');
rgcCache_adminTest($result['status'] === 401 && $session === [], 'wrong password rejected without session');
$result = $call('POST', 'admin/login', '{"username":"alex","password":"correct horse battery staple"}');
$csrf = $result['body']['csrf_token'] ?? '';
rgcCache_adminTest($result['status'] === 200 && strlen($csrf) === 64 && rgcCache_adminIdentity($session) !== null, 'valid login creates authenticated session and CSRF token');
$result = $call('GET', 'admin/session');
rgcCache_adminTest($result['body']['authenticated'] === true && $result['body']['username'] === 'alex', 'session state restored');
$result = $call('GET', 'admin/mappings');
rgcCache_adminTest($result['status'] === 503, 'authenticated mapping request reaches configured service boundary');
$result = $call('POST', 'admin/mapping/draft', '{"id":"new-map","tables":{"Contacts":[]}}', 'wrong');
rgcCache_adminTest($result['status'] === 403, 'mapping mutation requires CSRF token before database work');
$result = $call('POST', 'admin/logout', '', 'wrong');
rgcCache_adminTest($result['status'] === 403 && rgcCache_adminIdentity($session) !== null, 'logout rejects wrong CSRF token');
$result = $call('POST', 'admin/logout', '', $csrf);
rgcCache_adminTest($result['status'] === 200 && $session === [], 'logout clears session');
$badSession = [];
$result = rgcCache_dispatch('POST', '/rgccache/api/v1/admin/login', '', ['base_path'=>'/rgccache'], '{}', '', 'application/json', null, '', $badSession);
rgcCache_adminTest($result['status'] === 503, 'missing admin configuration fails closed');
echo 'All administrator authentication checks passed.', PHP_EOL;
