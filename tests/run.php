<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/private/src/http.php';

function rgcCache_test(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException($description);
    }
    echo 'PASS: ', $description, PHP_EOL;
}

$rgcCache_config = ['base_path' => '/rgccache', 'webhook_token' => str_repeat('a', 64)];
$rgcCache_auth = 'Bearer ' . $rgcCache_config['webhook_token'];
rgcCache_test(rgcCache_dispatch('GET', '/rgccache/api/v1/health', '', null)['status'] === 200, 'health works before configuration');
foreach (['/api/v1/health', '/rgcgo/api/v1/health', '/rgccache/api/v2/health', '/rgccache/api/v1/health/extra', '/private/config.php'] as $rgcCache_path) {
    rgcCache_test(rgcCache_dispatch('GET', $rgcCache_path, '', $rgcCache_config)['status'] === 404, 'reject route ' . $rgcCache_path);
}
rgcCache_test(rgcCache_dispatch('POST', '/rgccache/api/v1/health', '', $rgcCache_config)['headers']['Allow'] === 'GET', 'method guard');
rgcCache_test(rgcCache_dispatch('POST', '/rgccache/api/v1/sync', '', null)['status'] === 503, 'unconfigured sync fails closed');
rgcCache_test(rgcCache_dispatch('POST', '/rgccache/api/v1/sync', 'Bearer wrong', $rgcCache_config)['status'] === 401, 'protected sync requires correct token');
rgcCache_test(rgcCache_dispatch('POST', '/rgccache/api/v1/sync', $rgcCache_auth, $rgcCache_config)['status'] === 503, 'incomplete config never acknowledges sync');
rgcCache_test(rgcCache_dispatch('GET', '/rgccache/api/v1/ready', $rgcCache_auth, $rgcCache_config)['status'] === 503, 'incomplete config never claims readiness');
rgcCache_test(rgcCache_dispatch('GET', '/custom/api/v1/health', '', ['base_path' => '/custom'])['status'] === 200, 'configurable mount path');
$rgcCache_read = false;
rgcCache_dispatch('POST', '/rgccache/api/v1/sync', 'wrong', $rgcCache_config, function () use (&$rgcCache_read): string { $rgcCache_read = true; return '{}'; });
rgcCache_test(!$rgcCache_read, 'authentication precedes request body loading');
rgcCache_test(rgcCache_dispatch('POST', '/rgccache/api/v1/sync', $rgcCache_auth, $rgcCache_config, '{}', 'test', 'text/plain')['status'] === 415, 'reject non-JSON sync');
$rgcCache_sourceUrl = rgcCache_sourceUrl(['filemaker'=>['odata_url'=>'https://example.invalid/fmi/odata/v4/CWP%20Snippets']], ['entity'=>'rgcCache_Test','record_id_field'=>'cRecordID','fields'=>['cRecordID','Title','Notes','Amount']], '1');
parse_str(parse_url($rgcCache_sourceUrl, PHP_URL_QUERY), $rgcCache_sourceQuery);
rgcCache_test($rgcCache_sourceQuery['$select'] === 'cRecordID,Title,Notes,Amount' && $rgcCache_sourceQuery['$filter'] === '"cRecordID" eq 1', 'live FileMaker-compatible select syntax preserves quoted filter');
echo 'All routing and source URL checks passed.', PHP_EOL;
