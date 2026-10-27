<?php
declare(strict_types=1);

/**
 * Compatibility boundary for the vendored odataCWP client.
 *
 * This file is intentionally not wired into source.php yet. It constructs and
 * tests the raw requests rgcCache must preserve before the proven transport is
 * replaced.
 */

require_once dirname(__DIR__) . '/vendor/odatacwp/ODataCWP.php';

function rgcCache_odataFail(string $reason, int $status = 502): never
{
    if (class_exists('rgcCache_Error')) {
        throw new rgcCache_Error($reason, $status);
    }
    throw new RuntimeException($reason);
}

function rgcCache_odataConnection(array $config): array
{
    $fm = $config['filemaker'] ?? null;
    if (!is_array($fm)) { rgcCache_odataFail('filemaker_configuration_incomplete', 503); }
    foreach (['file', 'odata_url', 'user', 'password'] as $key) {
        if (!is_string($fm[$key] ?? null) || $fm[$key] === '' || preg_match('/[\x00-\x1f]/', $fm[$key])) {
            rgcCache_odataFail('filemaker_configuration_incomplete', 503);
        }
    }

    $parts = parse_url($fm['odata_url']);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !is_string($parts['host'] ?? null)
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        rgcCache_odataFail('invalid_filemaker_config', 503);
    }
    $path = $parts['path'] ?? '';
    if (!is_string($path) || !preg_match('~^/fmi/odata/v4/([^/]+)/?$~D', $path, $match)) {
        rgcCache_odataFail('invalid_filemaker_config', 503);
    }
    $database = preg_replace('/\.fmp12$/i', '', rawurldecode($match[1]));
    $expected = preg_replace('/\.fmp12$/i', '', $fm['file']);
    if (!is_string($database) || $database === '' || strlen($database) > 191 || preg_match('~[\\\\/\x00-\x1f]~', $database)
        || !is_string($expected) || $database !== $expected) {
        rgcCache_odataFail('filemaker_database_url_mismatch', 503);
    }
    $host = 'https://' . $parts['host'];
    if (isset($parts['port'])) { $host .= ':' . (int)$parts['port']; }
    return ['host'=>$host, 'database'=>$database, 'user'=>$fm['user'], 'password'=>$fm['password']];
}

/** Construct the vendored client from rgcCache's one private configuration. */
function rgcCache_odataClient(array $config, string $table, array $options = []): \ODataCWP\ODataCWP
{
    if ($table === '' || strlen($table) > 191 || preg_match('~[\\\\/\x00-\x1f]~', $table)) {
        rgcCache_odataFail('invalid_table_mapping', 503);
    }
    $connection = rgcCache_odataConnection($config);
    $seconds = $config['limits']['source_seconds'] ?? 10;
    if (!is_int($seconds) || $seconds < 1 || $seconds > 300) {
        rgcCache_odataFail('invalid_limits', 503);
    }
    $clientOptions = array_replace([
        'logType'=>\ODataCWP\ODataCWP::LOG_TYPE_NONE,
        'timeout'=>$seconds,
        'connectTimeout'=>$seconds,
    ], $options);
    return new \ODataCWP\ODataCWP(
        $connection['host'],
        $connection['database'],
        $table,
        $connection['user'],
        $connection['password'],
        $clientOptions
    );
}

function rgcCache_odataSelect(array $mapping): string
{
    $recordId = $mapping['record_id_field'] ?? 'ROWID';
    $fields = $mapping['fields'] ?? [];
    if (!is_string($recordId) || $recordId === '' || !is_array($fields) || !array_is_list($fields)) {
        rgcCache_odataFail('invalid_table_mapping', 503);
    }
    return $fields ? implode(',', $fields) : '*,' . $recordId;
}

function rgcCache_odataRecordDescriptor(array $mapping, string $id): array
{
    if (function_exists('rgcCache_recordId')) { rgcCache_recordId($id); }
    elseif (!preg_match('/^[1-9][0-9]{0,18}$/D', $id)) { rgcCache_odataFail('invalid_record_id', 400); }
    $entity = $mapping['entity'] ?? null;
    $recordId = $mapping['record_id_field'] ?? 'ROWID';
    if (!is_string($entity) || $entity === '' || !is_string($recordId) || $recordId === '') {
        rgcCache_odataFail('invalid_table_mapping', 503);
    }
    return [
        'method'=>'GET',
        'path'=>rawurlencode($entity),
        'query'=>['$select'=>rgcCache_odataSelect($mapping), '$filter'=>'"' . $recordId . '" eq ' . $id, '$top'=>'2'],
        'body'=>null,
        'options'=>['accept'=>'application/json;IEEE754Compatible=true', 'responseType'=>'text'],
    ];
}

function rgcCache_odataPageDescriptor(array $mapping, string $after, int $limit): array
{
    if ($after !== '0') {
        if (function_exists('rgcCache_recordId')) { rgcCache_recordId($after); }
        elseif (!preg_match('/^[1-9][0-9]{0,18}$/D', $after)) { rgcCache_odataFail('invalid_record_id', 400); }
    }
    if ($limit < 1 || $limit > 1000) { rgcCache_odataFail('invalid_limits', 503); }
    $entity = $mapping['entity'] ?? null;
    $recordId = $mapping['record_id_field'] ?? 'ROWID';
    if (!is_string($entity) || $entity === '' || !is_string($recordId) || $recordId === '') {
        rgcCache_odataFail('invalid_table_mapping', 503);
    }
    $quoted = '"' . $recordId . '"';
    return [
        'method'=>'GET',
        'path'=>rawurlencode($entity),
        'query'=>[
            '$select'=>rgcCache_odataSelect($mapping),
            '$filter'=>$quoted . ' gt ' . $after,
            '$orderby'=>$quoted . ' asc',
            '$top'=>(string)$limit,
        ],
        'body'=>null,
        'options'=>['accept'=>'application/json;IEEE754Compatible=true', 'responseType'=>'text'],
    ];
}

function rgcCache_odataCatalogDescriptor(): array
{
    return ['method'=>'GET', 'path'=>'', 'query'=>[], 'body'=>null,
        'options'=>['accept'=>'application/json;IEEE754Compatible=true', 'responseType'=>'text']];
}

function rgcCache_odataMetadataDescriptor(): array
{
    return ['method'=>'GET', 'path'=>'$metadata', 'query'=>[], 'body'=>null,
        'options'=>['accept'=>'application/json', 'responseType'=>'text']];
}

/** Execute a descriptor against an ODataCWP-compatible client and return raw bytes. */
function rgcCache_odataExecute(object $client, array $descriptor, float $deadline, int $maximum): string
{
    if ($deadline <= microtime(true)) { rgcCache_odataFail('request_time_budget_exhausted', 503); }
    if ($maximum < 1) { rgcCache_odataFail('invalid_limits', 503); }
    foreach (['request', 'isError', 'getResponse'] as $method) {
        if (!method_exists($client, $method)) { rgcCache_odataFail('odatacwp_client_unavailable', 503); }
    }
    $options = $descriptor['options'] ?? [];
    if (!is_array($options)) { rgcCache_odataFail('invalid_odata_descriptor', 503); }
    $options['deadline'] = $deadline;
    $options['maxResponseBytes'] = $maximum;
    $result = $client->request(
        $descriptor['method'] ?? 'GET',
        $descriptor['path'] ?? '',
        $descriptor['query'] ?? [],
        $descriptor['body'] ?? null,
        $options
    );
    if (!is_array($result) || $client->isError($result)) {
        $code = is_array($result) && is_string($result['error_code'] ?? null) ? $result['error_code'] : 'source_request_failed';
        $reason = match ($code) {
            'deadline_exceeded', 'request_timeout' => 'request_time_budget_exhausted',
            'response_too_large' => 'source_response_too_large',
            'invalid_json' => 'source_invalid_json',
            'connection_failed' => 'source_connection_failed',
            default => 'source_request_failed',
        };
        rgcCache_odataFail($reason, $reason === 'request_time_budget_exhausted' ? 503 : 502);
    }
    $body = $client->getResponse($result);
    if (!is_string($body)) { rgcCache_odataFail('source_invalid_response', 502); }
    if (strlen($body) > $maximum) { rgcCache_odataFail('source_response_too_large', 502); }
    return $body;
}
