<?php
declare(strict_types=1);
require_once __DIR__ . '/database.php';

function rgcCache_mappingId(string $id): string
{
    if (!preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $id)) {
        throw new InvalidArgumentException('Invalid mapping ID');
    }
    return $id;
}

function rgcCache_mappingField(mixed $field): string
{
    if (!is_string($field) || $field === '' || strlen($field) > 191 || preg_match('/[\x00-\x1f,@"]/', $field)) {
        throw new InvalidArgumentException('Invalid mapping field');
    }
    return $field;
}

function rgcCache_mappingNormalize(array $tables): array
{
    if (array_is_list($tables)) {
        $selected = [];
        foreach ($tables as $table) {
            if (!is_string($table) || isset($selected[$table])) { throw new InvalidArgumentException('Invalid or duplicate mapping table'); }
            $selected[$table] = [];
        }
        $tables = $selected;
    }
    if (!$tables) { throw new InvalidArgumentException('Mapping must select at least one table'); }

    $normalized = [];
    foreach ($tables as $table => $mapping) {
        if (!is_string($table) || $table === '' || strlen($table) > 191 || preg_match('/[\x00-\x1f]/', $table)
            || !is_array($mapping) || array_is_list($mapping) && $mapping !== []) {
            throw new InvalidArgumentException('Invalid mapping table');
        }
        $allowed = ['entity', 'record_id_field', 'fields', 'primary_key_field', 'modified_at_field'];
        if (array_diff(array_keys($mapping), $allowed)) { throw new InvalidArgumentException('Unknown mapping option'); }
        $mapping += ['entity'=>$table, 'record_id_field'=>'ROWID', 'fields'=>[]];
        if (!is_string($mapping['entity']) || $mapping['entity'] === '' || strlen($mapping['entity']) > 191 || preg_match('/[\x00-\x1f]/', $mapping['entity'])) {
            throw new InvalidArgumentException('Invalid mapping entity');
        }
        if (!is_array($mapping['fields']) || !array_is_list($mapping['fields'])) { throw new InvalidArgumentException('Invalid mapping fields'); }
        $fields = [];
        foreach ($mapping['fields'] as $field) {
            $field = rgcCache_mappingField($field);
            if (isset($fields[$field])) { throw new InvalidArgumentException('Duplicate mapping field'); }
            $fields[$field] = true;
        }
        $fields = array_keys($fields);
        sort($fields, SORT_STRING);
        $recordId = rgcCache_mappingField($mapping['record_id_field']);
        $entry = ['entity'=>$mapping['entity'], 'record_id_field'=>$recordId, 'fields'=>$fields];
        foreach (['primary_key_field', 'modified_at_field'] as $optional) {
            if (!array_key_exists($optional, $mapping) || $mapping[$optional] === null || $mapping[$optional] === '') { continue; }
            $field = rgcCache_mappingField($mapping[$optional]);
            if ($fields && !in_array($field, $fields, true)) { throw new InvalidArgumentException('Mapped identity field must be selected'); }
            $entry[$optional] = $field;
        }
        if ($fields && !in_array($recordId, $fields, true)) { throw new InvalidArgumentException('Mapped record ID field must be selected'); }
        $normalized[$table] = $entry;
    }
    ksort($normalized, SORT_STRING);
    return $normalized;
}

function rgcCache_mappingDocument(array $tables): array
{
    return ['schema_version'=>1, 'tables'=>rgcCache_mappingNormalize($tables)];
}

function rgcCache_mappingEncode(array $tables): string
{
    return json_encode(rgcCache_mappingDocument($tables), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function rgcCache_mappingHash(string $json): string
{
    return hash('sha256', $json);
}

function rgcCache_mappingConfigHash(array $config, array $tables): string
{
    $file = $config['filemaker']['file'] ?? null;
    $url = $config['filemaker']['odata_url'] ?? null;
    if (!is_string($file) || $file === '' || !is_string($url) || $url === '') {
        throw new InvalidArgumentException('FileMaker configuration is required for mapping activation');
    }
    return hash('sha256', json_encode([$file, $url, rgcCache_mappingNormalize($tables)], JSON_THROW_ON_ERROR));
}

function rgcCache_mappingDecode(string $json): array
{
    $document = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($document) || ($document['schema_version'] ?? null) !== 1 || !is_array($document['tables'] ?? null)
        || array_diff(array_keys($document), ['schema_version', 'tables'])) {
        throw new UnexpectedValueException('Invalid mapping document');
    }
    return rgcCache_mappingDocument($document['tables']);
}

function rgcCache_mappingTable(array $config): string
{
    return rgcCache_dbSettings($config)['table_prefix'] . 'mappings';
}

function rgcCache_mappingLoad(PDO $pdo, array $config, string $id): ?array
{
    $id = rgcCache_mappingId($id);
    $table = rgcCache_mappingTable($config);
    $query = $pdo->prepare("SELECT id,status,mapping_json,mapping_hash,created_at,updated_at,activated_at FROM {$table} WHERE id=?");
    $query->execute([$id]);
    $row = $query->fetch();
    if ($row === false) { return null; }
    if (!in_array($row['status'], ['draft', 'active', 'retired'], true)) { throw new UnexpectedValueException('Invalid mapping status'); }
    $document = rgcCache_mappingDecode($row['mapping_json']);
    $canonical = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!hash_equals($row['mapping_hash'], rgcCache_mappingHash($canonical)) || !hash_equals($row['mapping_json'], $canonical)) {
        throw new UnexpectedValueException('Mapping integrity check failed');
    }
    return [
        'id'=>$row['id'], 'status'=>$row['status'], 'hash'=>$row['mapping_hash'],
        'tables'=>$document['tables'], 'created_at'=>$row['created_at'],
        'updated_at'=>$row['updated_at'], 'activated_at'=>$row['activated_at'],
    ];
}

function rgcCache_mappingList(PDO $pdo, array $config): array
{
    $table = rgcCache_mappingTable($config);
    $ids = $pdo->query("SELECT id FROM {$table} ORDER BY created_at DESC,id DESC")->fetchAll(PDO::FETCH_COLUMN);
    $mappings = [];
    foreach ($ids as $id) {
        $mapping = rgcCache_mappingLoad($pdo, $config, (string)$id);
        if ($mapping !== null) { $mappings[] = $mapping; }
    }
    return $mappings;
}

function rgcCache_mappingSaveDraft(PDO $pdo, array $config, string $id, array $tables): array
{
    $id = rgcCache_mappingId($id);
    $table = rgcCache_mappingTable($config);
    $json = rgcCache_mappingEncode($tables);
    $hash = rgcCache_mappingHash($json);
    $query = $pdo->prepare("SELECT status FROM {$table} WHERE id=? FOR UPDATE");
    $pdo->beginTransaction();
    try {
        $query->execute([$id]);
        $status = $query->fetchColumn();
        if ($status !== false && $status !== 'draft') { throw new RuntimeException('Only draft mappings can be edited'); }
        if ($status === false) {
            $insert = $pdo->prepare("INSERT INTO {$table} (id,status,mapping_json,mapping_hash) VALUES (?,'draft',?,?)");
            $insert->execute([$id, $json, $hash]);
        } else {
            $update = $pdo->prepare("UPDATE {$table} SET mapping_json=?,mapping_hash=?,updated_at=CURRENT_TIMESTAMP(6) WHERE id=?");
            $update->execute([$json, $hash, $id]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $error;
    }
    return rgcCache_mappingLoad($pdo, $config, $id) ?? throw new RuntimeException('Mapping save failed');
}

function rgcCache_mappingActivate(PDO $pdo, array $config, string $id): array
{
    $id = rgcCache_mappingId($id);
    $prefix = rgcCache_dbSettings($config)['table_prefix'];
    $table = $prefix . 'mappings';
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare("SELECT status,mapping_json,mapping_hash FROM {$table} WHERE id=? FOR UPDATE");
        $query->execute([$id]);
        $mapping = $query->fetch();
        if (!$mapping || $mapping['status'] !== 'draft') { throw new RuntimeException('Only a draft mapping can be activated'); }
        $document = rgcCache_mappingDecode($mapping['mapping_json']);
        $canonical = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!hash_equals($mapping['mapping_json'], $canonical) || !hash_equals($mapping['mapping_hash'], rgcCache_mappingHash($canonical))) {
            throw new UnexpectedValueException('Mapping integrity check failed');
        }
        $installation = $pdo->prepare("SELECT config_hash,initialized,importing FROM {$prefix}installations WHERE id=? FOR UPDATE");
        $installation->execute([$id]);
        $state = $installation->fetch();
        if (!$state || !(bool)$state['initialized'] || (bool)$state['importing']) { throw new RuntimeException('Mapping installation import is not complete'); }
        if (!hash_equals($state['config_hash'], rgcCache_mappingConfigHash($config, $document['tables']))) {
            throw new RuntimeException('Mapping installation configuration does not match');
        }
        $pdo->exec("UPDATE {$table} SET status='retired',updated_at=CURRENT_TIMESTAMP(6) WHERE status='active'");
        $activate = $pdo->prepare("UPDATE {$table} SET status='active',activated_at=CURRENT_TIMESTAMP(6),updated_at=CURRENT_TIMESTAMP(6) WHERE id=?");
        $activate->execute([$id]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $error;
    }
    return rgcCache_mappingLoad($pdo, $config, $id) ?? throw new RuntimeException('Mapping activation failed');
}

function rgcCache_mappingActive(PDO $pdo, array $config): ?array
{
    $table = rgcCache_mappingTable($config);
    $ids = $pdo->query("SELECT id FROM {$table} WHERE status='active' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    if (count($ids) > 1) { throw new UnexpectedValueException('Multiple active mappings'); }
    return $ids ? rgcCache_mappingLoad($pdo, $config, $ids[0]) : null;
}

function rgcCache_mappingSeedActive(PDO $pdo, array $config): array
{
    $id = rgcCache_mappingId((string)($config['installation_id'] ?? 'default'));
    $tables = $config['filemaker']['tables'] ?? null;
    if (!is_array($tables)) { throw new InvalidArgumentException('Configured mapping is unavailable'); }
    $document = rgcCache_mappingDocument($tables);
    $json = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $hash = rgcCache_mappingHash($json);
    $expectedConfigHash = rgcCache_mappingConfigHash($config, $document['tables']);
    $prefix = rgcCache_dbSettings($config)['table_prefix'];
    $table = $prefix . 'mappings';

    $pdo->beginTransaction();
    try {
        $installation = $pdo->prepare("SELECT config_hash,initialized,importing FROM {$prefix}installations WHERE id=? FOR UPDATE");
        $installation->execute([$id]);
        $state = $installation->fetch();
        if (!$state || !(bool)$state['initialized'] || (bool)$state['importing']) { throw new RuntimeException('Configured installation is not ready'); }
        if (!hash_equals($state['config_hash'], $expectedConfigHash)) { throw new RuntimeException('Configured mapping does not match initialized installation'); }

        $query = $pdo->prepare("SELECT status,mapping_json,mapping_hash FROM {$table} WHERE id=? FOR UPDATE");
        $query->execute([$id]);
        $existing = $query->fetch();
        if ($existing) {
            if ($existing['status'] !== 'active' || !hash_equals($existing['mapping_json'], $json) || !hash_equals($existing['mapping_hash'], $hash)) {
                throw new RuntimeException('Existing mapping row does not match configured installation');
            }
        } else {
            $active = $pdo->query("SELECT id FROM {$table} WHERE status='active' FOR UPDATE")->fetchAll(PDO::FETCH_COLUMN);
            if ($active) { throw new RuntimeException('Another active mapping already exists'); }
            $insert = $pdo->prepare("INSERT INTO {$table} (id,status,mapping_json,mapping_hash,activated_at) VALUES (?,'active',?,?,CURRENT_TIMESTAMP(6))");
            $insert->execute([$id, $json, $hash]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $error;
    }
    return rgcCache_mappingLoad($pdo, $config, $id) ?? throw new RuntimeException('Mapping seed failed');
}

function rgcCache_mappingApplyActive(PDO $pdo, array $config): array
{
    $active = rgcCache_mappingActive($pdo, $config);
    if ($active === null) {
        throw new RuntimeException('Active database mapping is required');
    }
    $prefix = rgcCache_dbSettings($config)['table_prefix'];
    $query = $pdo->prepare("SELECT config_hash,initialized,importing FROM {$prefix}installations WHERE id=?");
    $query->execute([$active['id']]);
    $state = $query->fetch();
    if (!$state || !(bool)$state['initialized'] || (bool)$state['importing']
        || !hash_equals($state['config_hash'], rgcCache_mappingConfigHash($config, $active['tables']))) {
        throw new RuntimeException('Active mapping installation is unavailable or incompatible');
    }
    $config['installation_id'] = $active['id'];
    $config['filemaker']['tables'] = $active['tables'];
    $config['_mapping_source'] = 'database';
    return $config;
}

function rgcCache_mappingApplyStored(PDO $pdo, array $config, string $id, array $allowedStatuses = ['draft', 'active']): array
{
    $mapping = rgcCache_mappingLoad($pdo, $config, $id);
    if ($mapping === null || !in_array($mapping['status'], $allowedStatuses, true)) {
        throw new RuntimeException('Requested database mapping is unavailable');
    }
    $config['installation_id'] = $mapping['id'];
    $config['filemaker']['tables'] = $mapping['tables'];
    $config['_mapping_source'] = 'database';
    return $config;
}
