<?php
declare(strict_types=1);

function rgcCache_primaryIdentity(array $mapping, stdClass $data): ?array
{
    $field = $mapping['primary_key_field'] ?? null;
    if ($field === null) { return null; }
    if (!property_exists($data, $field)) { throw new rgcCache_Error('source_primary_key_missing', 502); }
    $raw = $data->{$field};
    if (!is_string($raw) && !is_int($raw) && !is_bool($raw)) {
        throw new rgcCache_Error('invalid_source_primary_key', 502);
    }
    $value = is_bool($raw) ? ($raw ? 'true' : 'false') : (string)$raw;
    if ($value === '') { throw new rgcCache_Error('source_primary_key_empty', 502); }
    if (strlen($value) > 16384) { throw new rgcCache_Error('source_primary_key_too_large', 502); }
    return [
        'field'=>$field,
        'value'=>$value,
        // Field name is part of the canonical identity so a later mapping
        // change cannot silently reinterpret an existing key value.
        'hash'=>hash('sha256', $field . "\0" . $value),
    ];
}

function rgcCache_identityRecordId(PDO $pdo, string $prefix, array $scope, array $primary): ?string
{
    $row = rgcCache_query(
        $pdo,
        "SELECT primary_key_field,primary_key_value,fm_record_id FROM {$prefix}record_identities WHERE installation_id=? AND file_name=? AND table_name=? AND primary_key_hash=?",
        [...$scope, $primary['hash']]
    )->fetch();
    if (!$row) { return null; }
    if (!hash_equals((string)$row['primary_key_field'], $primary['field']) || !hash_equals((string)$row['primary_key_value'], $primary['value'])) {
        throw new rgcCache_Error('primary_key_hash_collision', 503);
    }
    return (string)$row['fm_record_id'];
}

// Must run inside the caller's transaction. Returns the old ROWID whose
// cached row should be removed when an explicitly permitted rebind occurs.
function rgcCache_identityAssign(PDO $pdo, string $prefix, array $scope, string $recordId, array $primary, bool $allowRebind): ?string
{
    $byHash = rgcCache_query(
        $pdo,
        "SELECT primary_key_field,primary_key_value,fm_record_id FROM {$prefix}record_identities WHERE installation_id=? AND file_name=? AND table_name=? AND primary_key_hash=? FOR UPDATE",
        [...$scope, $primary['hash']]
    )->fetch();
    if ($byHash && (!hash_equals((string)$byHash['primary_key_field'], $primary['field']) || !hash_equals((string)$byHash['primary_key_value'], $primary['value']))) {
        throw new rgcCache_Error('primary_key_hash_collision', 503);
    }
    $byLocator = rgcCache_query(
        $pdo,
        "SELECT primary_key_hash FROM {$prefix}record_identities WHERE installation_id=? AND file_name=? AND table_name=? AND fm_record_id=? FOR UPDATE",
        [...$scope, $recordId]
    )->fetch();
    $oldRecordId = $byHash ? (string)$byHash['fm_record_id'] : null;
    if ($oldRecordId !== null && $oldRecordId !== $recordId) {
        if (!$allowRebind || ($byLocator && !hash_equals((string)$byLocator['primary_key_hash'], $primary['hash']))) {
            throw new rgcCache_Error('duplicate_source_primary_key', 409);
        }
        rgcCache_query(
            $pdo,
            "UPDATE {$prefix}record_identities SET fm_record_id=? WHERE installation_id=? AND file_name=? AND table_name=? AND primary_key_hash=?",
            [$recordId, ...$scope, $primary['hash']]
        );
        return $oldRecordId;
    }
    if ($byLocator && !hash_equals((string)$byLocator['primary_key_hash'], $primary['hash'])) {
        // The same FileMaker record changed its business key.
        rgcCache_query(
            $pdo,
            "DELETE FROM {$prefix}record_identities WHERE installation_id=? AND file_name=? AND table_name=? AND fm_record_id=?",
            [...$scope, $recordId]
        );
    }
    rgcCache_query(
        $pdo,
        "INSERT INTO {$prefix}record_identities (installation_id,file_name,table_name,primary_key_hash,primary_key_field,primary_key_value,fm_record_id) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE primary_key_field=VALUES(primary_key_field),primary_key_value=VALUES(primary_key_value),fm_record_id=VALUES(fm_record_id),updated_at=CURRENT_TIMESTAMP(6)",
        [...$scope, $primary['hash'], $primary['field'], $primary['value'], $recordId]
    );
    return null;
}

function rgcCache_identityDeleteByRecordId(PDO $pdo, string $prefix, array $scope, string $recordId): void
{
    rgcCache_query(
        $pdo,
        "DELETE FROM {$prefix}record_identities WHERE installation_id=? AND file_name=? AND table_name=? AND fm_record_id=?",
        [...$scope, $recordId]
    );
}
