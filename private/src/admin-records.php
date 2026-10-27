<?php
declare(strict_types=1);
require_once __DIR__ . '/reader.php';

function rgcCache_adminRecordInput(string $body): array
{
    $input = rgcCache_adminObject($body, ['table','query','after']);
    if (!is_string($input->table ?? null) || $input->table === '' || strlen($input->table) > 191
        || !is_string($input->query ?? null) || strlen($input->query) > 160
        || !is_string($input->after ?? null)) {
        throw new rgcCache_Error('invalid_record_search', 422);
    }
    try { $after = rgcCache_readerId($input->after, true); }
    catch (InvalidArgumentException $error) { throw new rgcCache_Error('invalid_record_cursor', 422); }
    return ['table'=>$input->table, 'query'=>trim($input->query), 'after'=>$after];
}

// Query values, JSON paths and scope are all bound parameters. Only the prefix
// (validated by dbSettings) and a fixed LIMIT enter SQL as identifiers/literals.
function rgcCache_adminRecordQuery(array $scope, array $input): array
{
    $prefix = $scope['prefix'];
    if (!is_string($prefix) || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,31}$/D', $prefix)) throw new InvalidArgumentException('Invalid table prefix');
    $params = [$scope['installation'], $scope['file'], $scope['table'], $input['after']];
    $where = '';
    if ($input['query'] !== '') {
        $fields = $scope['mapping']['fields'];
        if (!$fields || count($fields) > 512) throw new rgcCache_Error('search_requires_1_to_512_explicit_fields', 422);
        $pattern = '%' . strtr($input['query'], ['='=>'==', '%'=>'=%', '_'=>'=_']) . '%';
        $clauses = [];
        foreach ($fields as $field) {
            $clauses[] = "LOWER(JSON_UNQUOTE(JSON_EXTRACT(payload, ?))) LIKE LOWER(?) ESCAPE '='";
            $params[] = '$.' . json_encode($field, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $params[] = $pattern;
        }
        $where = ' AND (' . implode(' OR ', $clauses) . ')';
    }
    return ["SELECT fm_record_id,primary_key,payload,synced_at FROM {$prefix}records WHERE installation_id=? AND file_name=? AND table_name=? AND fm_record_id>CAST(? AS SIGNED){$where} ORDER BY fm_record_id LIMIT 26", $params];
}

function rgcCache_adminRecords(PDO $pdo, array $config, array $input): array
{
    try { $scope = rgcCache_readerScope($pdo, $config, $input['table']); }
    catch (InvalidArgumentException $error) { throw new rgcCache_Error('table_not_in_active_mapping', 422); }
    [$sql, $params] = rgcCache_adminRecordQuery($scope, $input);
    // Keep full-content scans bounded in time on both supported SQL engines.
    $version = (string)$pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
    $sql = stripos($version, 'MariaDB') !== false
        ? 'SET STATEMENT max_statement_time=3 FOR ' . $sql
        : preg_replace('/^SELECT /', 'SELECT /*+ MAX_EXECUTION_TIME(3000) */ ', $sql);
    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    $records = []; $bytes = 0; $more = false;
    while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
        if (count($records) >= 25) { $more = true; break; }
        $size = strlen($row['payload']);
        if ($size > 1048576) {
            $record = ['id'=>(string)$row['fm_record_id'], 'primary_key'=>null, 'fields'=>(object)[], 'synced_at'=>$row['synced_at'], 'omitted'=>true];
        } else {
            if ($records && $bytes + $size > 2097152) { $more = true; break; }
            $record = rgcCache_readerRow($row);
            // Do not expose obsolete fields left in a legacy cache payload.
            if ($scope['mapping']['fields']) $record['fields'] = array_intersect_key($record['fields'], array_flip($scope['mapping']['fields']));
            $bytes += $size;
        }
        $records[] = $record;
    }
    $statement->closeCursor();
    return ['mapping_id'=>$scope['installation'], 'records'=>$records, 'next_after_id'=>$more ? $records[array_key_last($records)]['id'] : null];
}
