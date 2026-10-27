<?php
declare(strict_types=1);
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/source.php';
require_once __DIR__ . '/mapping.php';
require_once __DIR__ . '/identity.php';

final class rgcCache_Error extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $httpStatus = 400)
    {
        parent::__construct($reason);
    }
}

function rgcCache_syncSettings(array $config): array
{
    rgcCache_dbSettings($config);
    $config['installation_id'] ??= 'default';
    if (!is_string($config['installation_id']) || !preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $config['installation_id'])) {
        throw new rgcCache_Error('invalid_installation_config', 503);
    }
    $fm = $config['filemaker'] ?? [];
    foreach (['file', 'odata_url', 'user', 'password'] as $key) {
        if (!is_string($fm[$key] ?? null) || $fm[$key] === '' || preg_match('/[\x00-\x1f]/', $fm[$key])) {
            throw new rgcCache_Error('filemaker_configuration_incomplete', 503);
        }
    }
    $url = parse_url($fm['odata_url']);
    if (!$url || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || str_contains($fm['user'], ':') || strlen($fm['file']) > 191) {
        throw new rgcCache_Error('invalid_filemaker_config', 503);
    }
    $fm['tables'] ??= [];
    if (!is_array($fm['tables'])) { throw new rgcCache_Error('invalid_table_mapping', 503); }
    if (array_is_list($fm['tables'])) {
        $tables = [];
        foreach ($fm['tables'] as $table) {
            if (!is_string($table)) { throw new rgcCache_Error('invalid_table_mapping', 503); }
            $tables[$table] = [];
        }
        $fm['tables'] = $tables;
    }
    foreach ($fm['tables'] as $table => &$mapping) {
        if (!is_array($mapping)) { throw new rgcCache_Error('invalid_table_mapping', 503); }
        $mapping += ['entity'=>$table, 'record_id_field'=>'ROWID', 'fields'=>[]];
    }
    unset($mapping);
    foreach ($fm['tables'] as $table => $mapping) {
        if (!is_string($table) || $table === '' || strlen($table) > 191 || preg_match('/[\x00-\x1f]/', $table) || !is_array($mapping) || !is_string($mapping['entity'] ?? null) || $mapping['entity'] === '' || preg_match('/[\x00-\x1f]/', $mapping['entity'])) {
            throw new rgcCache_Error('invalid_table_mapping', 503);
        }
        if (!is_array($mapping['fields'] ?? null) || !array_is_list($mapping['fields'])) { throw new rgcCache_Error('invalid_field_mapping', 503); }
        foreach ($mapping['fields'] as $field) {
            try { rgcCache_mappingField($field); }
            catch (InvalidArgumentException $error) { throw new rgcCache_Error('invalid_field_mapping', 503); }
        }
        foreach (['record_id_field', 'primary_key_field', 'modified_at_field'] as $key) {
            $field = $mapping[$key] ?? null;
            try { if ($key === 'record_id_field' || $field !== null) { rgcCache_mappingField($field); } }
            catch (InvalidArgumentException $error) { throw new rgcCache_Error('mapped_identity_field_must_be_selected', 503); }
            if (($key === 'record_id_field' || $field !== null) && $mapping['fields'] && !in_array($field, $mapping['fields'], true)) { throw new rgcCache_Error('mapped_identity_field_must_be_selected', 503); }
        }
    }
    $config['filemaker'] = $fm;
    foreach (['notification_bytes'=>1048576, 'record_bytes'=>4194304, 'records_per_request'=>25, 'request_seconds'=>20, 'source_seconds'=>10, 'batches_per_request'=>3, 'resolved_bytes'=>8388608, 'pending_batches'=>1000, 'completed_inbox_keep'=>5000, 'import_page_size'=>100] as $key => $default) {
        $value = $config['limits'][$key] ?? $default;
        $max = match ($key) { 'request_seconds'=>25, 'source_seconds'=>20, 'batches_per_request'=>10, 'records_per_request'=>100, 'import_page_size'=>1000, 'pending_batches'=>10000, 'completed_inbox_keep'=>10000, default=>8388608 };
        if (!is_int($value) || $value < 1 || $value > $max) { throw new rgcCache_Error('invalid_limits', 503); }
        $config['limits'][$key] = $value;
    }
    return $config;
}

function rgcCache_recordId(mixed $value): string
{
    if ((!is_string($value) && !is_int($value)) || !preg_match('/^[1-9][0-9]{0,18}$/D', (string)$value) || (strlen((string)$value) === 19 && strcmp((string)$value, '9223372036854775807') > 0)) {
        throw new rgcCache_Error('invalid_record_id');
    }
    return (string)$value;
}

// Decode objects distinctly from arrays, reject duplicate keys, and optionally
// retain ALL numeric tokens as strings for exact FileMaker decimal values.
function rgcCache_json(string $text, bool $numberStrings = false): mixed
{
    try { json_decode($text, false, 64, JSON_THROW_ON_ERROR); }
    catch (JsonException $error) { throw new rgcCache_Error('invalid_json'); }
    $matched = preg_match_all('/"[^"\\\\]*+(?:\\\\.[^"\\\\]*+)*+"|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?|true|false|null|[{}\[\]:,]/s', $text, $matches);
    if ($matched === false) { throw new rgcCache_Error('json_token_limit'); }
    $tokens = $matches[0];
    $index = 0;
    $parse = function () use (&$parse, &$index, $tokens, $numberStrings): mixed {
        $token = $tokens[$index++];
        if ($token === '{' || $token === '[') {
            $object = $token === '{';
            $end = $object ? '}' : ']';
            $value = $object ? new stdClass() : [];
            if ($tokens[$index] === $end) { $index++; return $value; }
            while (true) {
                if ($object) {
                    $key = json_decode($tokens[$index++], true, 64, JSON_THROW_ON_ERROR);
                    $index++; // colon; syntax already validated by json_decode
                    if (property_exists($value, $key)) { throw new rgcCache_Error('duplicate_json_key'); }
                    $value->{$key} = $parse();
                } else { $value[] = $parse(); }
                if ($tokens[$index++] === $end) { break; }
            }
            return $value;
        }
        if ($numberStrings && preg_match('/^-?[0-9]/', $token)) { return $token; }
        return json_decode($token, false, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    };
    return $parse();
}

function rgcCache_parsePayload(string $body, array $config): array
{
    if (strlen($body) > $config['limits']['notification_bytes']) { throw new rgcCache_Error('notification_too_large', 413); }
    $raw = rgcCache_json($body);
    $file = $config['filemaker']['file'];
    if (!$raw instanceof stdClass || count(get_object_vars($raw)) !== 1 || !isset($raw->{$file}) || !$raw->{$file} instanceof stdClass || !get_object_vars($raw->{$file})) { throw new rgcCache_Error('file_not_allowed_or_empty'); }
    $records = [];
    $count = 0;
    foreach ($raw->{$file} as $table => $rows) {
        if (!is_array($rows) || !$rows) { throw new rgcCache_Error('transaction_table_empty_or_invalid'); }
        $mapped = array_key_exists($table, $config['filemaker']['tables']);
        foreach ($rows as $row) {
            if (!is_array($row) || count($row) !== 3 || !in_array($row[0], ['New','Modified','Deleted'], true)) { throw new rgcCache_Error('invalid_transaction_row'); }
            if (++$count > $config['limits']['records_per_request']) { throw new rgcCache_Error('too_many_records', 413); }
            $id = rgcCache_recordId($row[1]);
            if ($mapped) { $records[$table . '\0' . $id] = ['table'=>$table, 'id'=>$id, 'operation'=>$row[0]]; }
        }
    }
    return array_values($records);
}

function rgcCache_query(PDO $pdo, string $sql, array $values = []): PDOStatement
{
    $statement = $pdo->prepare($sql);
    $statement->execute($values);
    return $statement;
}

function rgcCache_lockName(array $config): string
{
    return 'rgcCache:' . substr(hash('sha256', $config['database']['name'] . ':' . $config['database']['table_prefix'] . ':' . $config['installation_id']), 0, 54);
}

function rgcCache_packetLimit(PDO $pdo): int
{
    // Leave room for SQL text, identity columns and protocol overhead.
    return max(0, min(16777215, (int)$pdo->query('SELECT @@max_allowed_packet')->fetchColumn() - 65536));
}

function rgcCache_pruneCompletedInbox(PDO $pdo, string $prefix, string $installation, int $keep): void
{
    // Completed payloads are no longer needed: delivery_id + body_hash provide
    // duplicate/conflict detection. This also scrubs rows completed by older code.
    rgcCache_query($pdo, "UPDATE {$prefix}inbox SET payload='' WHERE installation_id=? AND status='done' AND payload<>''", [$installation]);
    $offset = $keep - 1;
    $cutoff = rgcCache_query(
        $pdo,
        "SELECT id FROM {$prefix}inbox WHERE installation_id=? AND status='done' ORDER BY id DESC LIMIT 1 OFFSET {$offset}",
        [$installation]
    )->fetchColumn();
    if ($cutoff !== false) {
        rgcCache_query($pdo, "DELETE FROM {$prefix}inbox WHERE installation_id=? AND status='done' AND id < ?", [$installation, $cutoff]);
    }
}

function rgcCache_processBatch(PDO $pdo, array $config, array $batch, float $deadline, ?callable $fetch = null): void
{
    $prefix = $config['database']['table_prefix'];
    $installation = $config['installation_id'];
    rgcCache_query($pdo, "UPDATE {$prefix}inbox SET attempts=attempts+1 WHERE id=?", [$batch['id']]);
    try {
        $resolved = [];
        $size = 0;
        $missing = false;
        $packetLimit = rgcCache_packetLimit($pdo);
        foreach (rgcCache_parsePayload($batch['payload'], $config) as $record) {
            if (microtime(true) >= $deadline) { throw new rgcCache_Error('request_time_budget_exhausted', 503); }
            $record['data'] = null;
            $record['primary'] = null;
            $record['allow_primary_rebind'] = false;
            if ($record['operation'] !== 'Deleted') {
                $mapping = $config['filemaker']['tables'][$record['table']];
                $data = rgcCache_sourceRecord($config, $mapping, $record['id'], $deadline, $fetch);
                if ($data === null) { $missing = true; }
                else {
                    $record['data'] = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if (strlen($record['data']) > min($config['limits']['record_bytes'], $packetLimit)) { throw new rgcCache_Error('record_exceeds_database_or_config_limit', 503); }
                    $record['primary'] = rgcCache_primaryIdentity($mapping, $data);
                    $record['primary_key'] = $record['primary']['value'] ?? null;
                    $modified = isset($mapping['modified_at_field']) ? ($data->{$mapping['modified_at_field']} ?? null) : null;
                    if ($modified !== null && !is_scalar($modified)) { throw new rgcCache_Error('invalid_source_identity', 502); }
                    $record['source_modified_at'] = $modified === null ? null : (string)$modified;
                    if (strlen($record['source_modified_at'] ?? '') > 16384) { throw new rgcCache_Error('source_identity_too_large', 502); }
                    if ($record['primary'] !== null) {
                        $scope = [$installation, $config['filemaker']['file'], $record['table']];
                        $oldId = rgcCache_identityRecordId($pdo, $prefix, $scope, $record['primary']);
                        if ($oldId !== null && $oldId !== $record['id']) {
                            // Rebind only when FileMaker proves the old locator is gone.
                            // If it still exists, two source rows share one configured key.
                            if (rgcCache_sourceRecord($config, $mapping, $oldId, $deadline, $fetch) !== null) {
                                throw new rgcCache_Error('duplicate_source_primary_key', 409);
                            }
                            $record['allow_primary_rebind'] = true;
                        }
                    }
                    $size += strlen($record['data']);
                    if ($size > $config['limits']['resolved_bytes']) { throw new rgcCache_Error('resolved_batch_too_large', 503); }
                }
            }
            $resolved[] = $record;
        }
        if (microtime(true) >= $deadline) { throw new rgcCache_Error('request_time_budget_exhausted', 503); }
        $pdo->beginTransaction();
        foreach ($resolved as $record) {
            $scope = [$installation, $config['filemaker']['file'], $record['table']];
            $identity = [...$scope, $record['id']];
            if ($record['operation'] === 'Deleted') {
                rgcCache_query($pdo, "DELETE FROM {$prefix}records WHERE installation_id=? AND file_name=? AND table_name=? AND fm_record_id=?", $identity);
                rgcCache_identityDeleteByRecordId($pdo, $prefix, $scope, $record['id']);
            } elseif ($record['data'] !== null) {
                if ($record['primary'] !== null) {
                    $oldId = rgcCache_identityAssign($pdo, $prefix, $scope, $record['id'], $record['primary'], $record['allow_primary_rebind']);
                    if ($oldId !== null && $oldId !== $record['id']) {
                        rgcCache_query($pdo, "DELETE FROM {$prefix}records WHERE installation_id=? AND file_name=? AND table_name=? AND fm_record_id=?", [...$scope, $oldId]);
                    }
                }
                rgcCache_query($pdo, "INSERT INTO {$prefix}records (installation_id,file_name,table_name,fm_record_id,primary_key,source_modified_at,payload) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE primary_key=VALUES(primary_key),source_modified_at=VALUES(source_modified_at),payload=VALUES(payload),synced_at=CURRENT_TIMESTAMP(6)", [...$identity, $record['primary_key'], $record['source_modified_at'], $record['data']]);
            }
        }
        rgcCache_query($pdo, "UPDATE {$prefix}inbox SET status='done',payload='',completed_at=CURRENT_TIMESTAMP(6),last_error=? WHERE id=?", [$missing ? 'source_record_missing_cache_unchanged' : null, $batch['id']]);
        rgcCache_pruneCompletedInbox($pdo, $prefix, $installation, $config['limits']['completed_inbox_keep']);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $reason = $error instanceof rgcCache_Error ? $error->reason : 'source_or_database_operation_failed';
        rgcCache_query($pdo, "UPDATE {$prefix}inbox SET last_error=? WHERE id=?", [$reason, $batch['id']]);
        throw new rgcCache_Error($reason, 503);
    }
}

function rgcCache_syncRequest(string $route, array $config, string $body = '', string $delivery = '', ?PDO $pdo = null, ?callable $fetch = null): array
{
    $config = rgcCache_syncSettings($config);
    $pdo ??= rgcCache_connect($config);
    $config = rgcCache_syncSettings(rgcCache_mappingApplyActive($pdo, $config));
    $deadline = microtime(true) + $config['limits']['request_seconds'];
    $hash = rgcCache_configHash($config);
    $config = rgcCache_expandTables($config, $deadline, $fetch);
    if ($route === 'sync') {
        if (!preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $delivery)) { throw new rgcCache_Error('valid_idempotency_key_required'); }
        rgcCache_parsePayload($body, $config);
    }
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=5');
    $prefix = $config['database']['table_prefix'];
    $installation = $config['installation_id'];
    $lock = rgcCache_lockName($config);
    if ((int)rgcCache_query($pdo, 'SELECT GET_LOCK(?,0)', [$lock])->fetchColumn() !== 1) { throw new rgcCache_Error('sync_busy_retry_same_delivery', 503); }
    try {
        $state = rgcCache_query($pdo, "SELECT * FROM {$prefix}installations WHERE id=?", [$installation])->fetch();
        if (!$state) {
            if ($route !== 'ready' && $route !== 'status') { rgcCache_query($pdo, "INSERT INTO {$prefix}installations (id,config_hash) VALUES (?,?)", [$installation, $hash]); }
            $state = ['config_hash'=>$hash, 'initialized'=>0, 'importing'=>0];
        }
        if (!hash_equals($state['config_hash'], $hash)) { throw new rgcCache_Error('mapping_changed_use_new_installation_and_reimport', 409); }
        $pending = (int)rgcCache_query($pdo, "SELECT COUNT(*) FROM {$prefix}inbox WHERE installation_id=? AND status='pending'", [$installation])->fetchColumn();
        if ($route === 'ready' || $route === 'status') {
            $ready = (bool)$state['initialized'] && !$state['importing'] && $pending === 0;
            return rgcCache_response($route === 'ready' && !$ready ? 503 : 200, ['status'=>$ready ? 'ready' : 'not_ready', 'sync_configured'=>true, 'mapping_source'=>$config['_mapping_source'], 'mapping_id'=>$installation, 'initialized'=>(bool)$state['initialized'], 'importing'=>(bool)$state['importing'], 'pending_batches'=>$pending, 'effective_record_bytes'=>min($config['limits']['record_bytes'],rgcCache_packetLimit($pdo)), 'last_delivery'=>rgcCache_query($pdo, "SELECT delivery_id,status,attempts,last_error,completed_at FROM {$prefix}inbox WHERE installation_id=? ORDER BY id DESC LIMIT 1", [$installation])->fetch() ?: null]);
        }
        if ($state['importing']) { throw new rgcCache_Error('import_in_progress_retry_same_delivery', 503); }
        if ($route === 'sync') {
            $existing = rgcCache_query($pdo, "SELECT * FROM {$prefix}inbox WHERE installation_id=? AND delivery_id=?", [$installation, $delivery])->fetch();
            if ($existing && !hash_equals($existing['body_hash'], hash('sha256', $body))) { throw new rgcCache_Error('idempotency_key_body_conflict', 409); }
            if (!$existing) {
                if ($pending >= $config['limits']['pending_batches']) { throw new rgcCache_Error('pending_queue_full', 503); }
                if (strlen($body) > rgcCache_packetLimit($pdo)) { throw new rgcCache_Error('notification_exceeds_database_packet_limit', 413); }
                rgcCache_query($pdo, "INSERT INTO {$prefix}inbox (installation_id,delivery_id,body_hash,payload) VALUES (?,?,?,?)", [$installation,$delivery,hash('sha256',$body),$body]);
            } elseif ($existing['status'] === 'done') {
                return rgcCache_response(200, ['state'=>'done','delivery_id'=>$delivery,'duplicate'=>true,'warning'=>$existing['last_error']]);
            }
        }
        $processed = 0;
        for ($i = 0; $i < $config['limits']['batches_per_request'] && microtime(true) < $deadline; $i++) {
            $batch = rgcCache_query($pdo, "SELECT * FROM {$prefix}inbox WHERE installation_id=? AND status='pending' ORDER BY id LIMIT 1", [$installation])->fetch();
            if (!$batch) { break; }
            rgcCache_processBatch($pdo, $config, $batch, $deadline, $fetch);
            $processed++;
            if ($route === 'sync' && $batch['delivery_id'] === $delivery) { break; }
        }
        if ($route === 'sync') {
            $row = rgcCache_query($pdo, "SELECT status,last_error FROM {$prefix}inbox WHERE installation_id=? AND delivery_id=?", [$installation,$delivery])->fetch();
            return rgcCache_response($row['status'] === 'done' ? 200 : 503, ['state'=>$row['status'], 'delivery_id'=>$delivery, 'warning'=>$row['last_error'], 'retry_required'=>$row['status'] !== 'done']);
        }
        $pending = (int)rgcCache_query($pdo, "SELECT COUNT(*) FROM {$prefix}inbox WHERE installation_id=? AND status='pending'", [$installation])->fetchColumn();
        return rgcCache_response(200, ['processed_batches'=>$processed, 'pending_batches'=>$pending]);
    } finally {
        rgcCache_query($pdo, 'SELECT RELEASE_LOCK(?)', [$lock]);
    }
}
