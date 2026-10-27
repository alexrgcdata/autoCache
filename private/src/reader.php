<?php
declare(strict_types=1);
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/mapping.php';

// Website-side reads only. No OData client, webhook token, sync call or cache writes.
function rgcCache_readerScope(PDO $pdo, array $config, string $table): array
{
    $config = rgcCache_mappingApplyActive($pdo, $config);
    $db = rgcCache_dbSettings($config);
    $installation = $config['installation_id'] ?? 'default';
    $file = $config['filemaker']['file'] ?? '';
    if (!is_string($installation) || !preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $installation)
        || !is_string($file) || $file === '' || strlen($file) > 191 || preg_match('/[\x00-\x1f]/', $file)
        || $table === '' || strlen($table) > 191 || preg_match('/[\x00-\x1f]/', $table)
        || !array_key_exists($table, $config['filemaker']['tables'])) {
        throw new InvalidArgumentException('Invalid cache reader scope');
    }
    return ['prefix'=>$db['table_prefix'], 'installation'=>$installation, 'file'=>$file, 'table'=>$table, 'mapping'=>$config['filemaker']['tables'][$table]];
}

function rgcCache_readerId(string $id, bool $allowZero = false): string
{
    if ($allowZero && $id === '0') { return $id; }
    if (!preg_match('/^[1-9][0-9]{0,18}$/D', $id)
        || (strlen($id) === 19 && strcmp($id, '9223372036854775807') > 0)) {
        throw new InvalidArgumentException('Invalid cache record ID');
    }
    return $id;
}

function rgcCache_readerRow(array $row): array
{
    $fields = json_decode($row['payload'], false, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    if (!$fields instanceof stdClass) { throw new UnexpectedValueException('Cached payload must be an object'); }
    return [
        'id'=>(string)$row['fm_record_id'],
        'primary_key'=>$row['primary_key'] ?? null,
        'fields'=>get_object_vars($fields),
        'synced_at'=>$row['synced_at'],
    ];
}

function rgcCache_cachedRecord(PDO $pdo, array $config, string $table, string $recordId): ?array
{
    $scope = rgcCache_readerScope($pdo, $config, $table);
    $id = rgcCache_readerId($recordId);
    $query = $pdo->prepare("SELECT fm_record_id,primary_key,payload,synced_at FROM {$scope['prefix']}records WHERE installation_id=? AND file_name=? AND table_name=? AND fm_record_id=CAST(? AS SIGNED)");
    $query->execute([$scope['installation'], $scope['file'], $scope['table'], $id]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : rgcCache_readerRow($row);
}

function rgcCache_cachedRecordByPrimaryKey(PDO $pdo, array $config, string $table, string $primaryKey): ?array
{
    $scope = rgcCache_readerScope($pdo, $config, $table);
    $field = $scope['mapping']['primary_key_field'] ?? null;
    if (!is_string($field) || $field === '' || $primaryKey === '' || strlen($primaryKey) > 16384) {
        throw new InvalidArgumentException('Table has no usable primary-key mapping');
    }
    $hash = hash('sha256', $field . "\0" . $primaryKey);
    $query = $pdo->prepare(
        "SELECT r.fm_record_id,r.primary_key,r.payload,r.synced_at,i.primary_key_field,i.primary_key_value
         FROM {$scope['prefix']}record_identities i
         INNER JOIN {$scope['prefix']}records r
           ON r.installation_id=i.installation_id AND r.file_name=i.file_name AND r.table_name=i.table_name AND r.fm_record_id=i.fm_record_id
         WHERE i.installation_id=? AND i.file_name=? AND i.table_name=? AND i.primary_key_hash=?"
    );
    $query->execute([$scope['installation'], $scope['file'], $scope['table'], $hash]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if ($row === false) { return null; }
    if (!hash_equals((string)$row['primary_key_field'], $field) || !hash_equals((string)$row['primary_key_value'], $primaryKey)) {
        throw new UnexpectedValueException('Primary-key hash collision');
    }
    return rgcCache_readerRow($row);
}

// The cursor is an exact internal ID, not an offset or a record count.
function rgcCache_cachedPage(PDO $pdo, array $config, string $table, string $afterId = '0', int $pageSize = 5): array
{
    $scope = rgcCache_readerScope($pdo, $config, $table);
    $afterId = rgcCache_readerId($afterId, true);
    if ($pageSize < 1 || $pageSize > 20) { throw new InvalidArgumentException('Page size must be between 1 and 20'); }
    $query = $pdo->prepare("SELECT fm_record_id,primary_key,payload,synced_at FROM {$scope['prefix']}records WHERE installation_id=:installation AND file_name=:file AND table_name=:table AND fm_record_id>CAST(:after AS SIGNED) ORDER BY fm_record_id ASC LIMIT :take");
    $query->bindValue(':installation', $scope['installation'], PDO::PARAM_STR);
    $query->bindValue(':file', $scope['file'], PDO::PARAM_STR);
    $query->bindValue(':table', $scope['table'], PDO::PARAM_STR);
    $query->bindValue(':after', $afterId, PDO::PARAM_STR);
    $query->bindValue(':take', $pageSize + 1, PDO::PARAM_INT);
    $query->execute();
    $records = [];
    $hasMore = false;
    while (($row = $query->fetch(PDO::FETCH_ASSOC)) !== false) {
        if (count($records) === $pageSize) { $hasMore = true; break; }
        $records[] = rgcCache_readerRow($row);
    }
    $query->closeCursor();
    return ['records'=>$records, 'next_after_id'=>$hasMore ? $records[array_key_last($records)]['id'] : null];
}

// Diagnostic snapshot only: no live source check and no assertion that notifications were complete.
function rgcCache_readerStatus(PDO $pdo, array $config, string $table): array
{
    $scope = rgcCache_readerScope($pdo, $config, $table);
    $query = $pdo->prepare("SELECT initialized,importing FROM {$scope['prefix']}installations WHERE id=?");
    $query->execute([$scope['installation']]);
    $state = $query->fetch(PDO::FETCH_ASSOC);
    $query = $pdo->prepare("SELECT COUNT(*) FROM {$scope['prefix']}inbox WHERE installation_id=? AND status='pending'");
    $query->execute([$scope['installation']]);
    return [
        'initialized'=>(bool)($state['initialized'] ?? false),
        'importing'=>(bool)($state['importing'] ?? false),
        'pending_batches'=>(int)$query->fetchColumn(),
    ];
}
