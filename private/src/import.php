<?php
declare(strict_types=1);
require_once __DIR__ . '/sync.php';

function rgcCache_importStatus(PDO $pdo, array $config): array
{
    $prefix=$config['database']['table_prefix'];
    $id=$config['installation_id'];
    $state=rgcCache_query($pdo,"SELECT initialized,importing FROM {$prefix}installations WHERE id=?",[$id])->fetch();
    $progress=rgcCache_query($pdo,"SELECT table_name,after_id,done FROM {$prefix}import_progress WHERE installation_id=? ORDER BY table_name",[$id])->fetchAll();
    return ['state'=>!$state ? 'not_started' : ($state['importing'] ? 'importing' : ($state['initialized'] ? 'done' : 'not_started')), 'tables'=>$progress];
}

function rgcCache_importRequest(string $action, array $config, bool $writesPaused = false, ?PDO $pdo = null, ?callable $fetch = null): array
{
    $config=rgcCache_syncSettings($config);
    $pdo ??= rgcCache_connect($config);
    $deadline=microtime(true)+$config['limits']['request_seconds'];
    if ($action==='discover') {
        $expanded=rgcCache_expandTables($config,$deadline,$fetch);
        return ['tables'=>$expanded['filemaker']['tables'],'all_tables_mode'=>!$config['filemaker']['tables']];
    }
    if (!in_array($action,['start','step','status'],true)) { throw new rgcCache_Error('invalid_import_action'); }
    $config=rgcCache_syncSettings(rgcCache_mappingApplyStored($pdo,$config,(string)$config['installation_id']));
    $hash=rgcCache_configHash($config);
    if ($action==='start' && !$writesPaused) { throw new rgcCache_Error('confirm_source_writes_paused'); }
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=5');
    $prefix=$config['database']['table_prefix'];
    $id=$config['installation_id'];
    $lock=rgcCache_lockName($config);
    if ((int)rgcCache_query($pdo,'SELECT GET_LOCK(?,0)',[$lock])->fetchColumn()!==1) { throw new rgcCache_Error('sync_busy_retry_same_delivery',503); }
    try {
        $state=rgcCache_query($pdo,"SELECT * FROM {$prefix}installations WHERE id=?",[$id])->fetch();
        if ($state && !hash_equals($state['config_hash'],$hash)) { throw new rgcCache_Error('mapping_changed_use_new_installation_and_reimport',409); }
        if ($action==='status') { return rgcCache_importStatus($pdo,$config); }
        if ($state && $state['initialized']) { return rgcCache_importStatus($pdo,$config); }
        if ($action==='start') {
            if ($state && $state['importing']) { return rgcCache_importStatus($pdo,$config); }
            if ((int)rgcCache_query($pdo,"SELECT COUNT(*) FROM {$prefix}records WHERE installation_id=?",[$id])->fetchColumn()
                || (int)rgcCache_query($pdo,"SELECT COUNT(*) FROM {$prefix}record_identities WHERE installation_id=?",[$id])->fetchColumn()
                || (int)rgcCache_query($pdo,"SELECT COUNT(*) FROM {$prefix}inbox WHERE installation_id=?",[$id])->fetchColumn()) {
                throw new rgcCache_Error('initial_import_requires_fresh_installation_id',409);
            }
            $config=rgcCache_expandTables($config,$deadline,$fetch);
            $pdo->beginTransaction();
            if (!$state) { rgcCache_query($pdo,"INSERT INTO {$prefix}installations (id,config_hash) VALUES (?,?)",[$id,$hash]); }
            foreach ($config['filemaker']['tables'] as $table=>$mapping) {
                rgcCache_query($pdo,"INSERT INTO {$prefix}import_progress (installation_id,table_name) VALUES (?,?)",[$id,$table]);
            }
            rgcCache_query($pdo,"UPDATE {$prefix}installations SET importing=1,initialized=0 WHERE id=?",[$id]);
            $pdo->commit();
            return rgcCache_importStatus($pdo,$config);
        }
        if (!$state || !$state['importing']) { throw new rgcCache_Error('import_not_started',409); }
        $config=rgcCache_expandTables($config,$deadline,$fetch);
        $snapshot=rgcCache_query($pdo,"SELECT table_name FROM {$prefix}import_progress WHERE installation_id=?",[$id])->fetchAll(PDO::FETCH_COLUMN);
        $current=array_keys($config['filemaker']['tables']); sort($snapshot); sort($current);
        if ($snapshot!==$current) { throw new rgcCache_Error('source_table_list_changed_during_import',409); }
        $progress=rgcCache_query($pdo,"SELECT * FROM {$prefix}import_progress WHERE installation_id=? AND done=0 ORDER BY table_name LIMIT 1",[$id])->fetch();
        if (!$progress) { throw new rgcCache_Error('invalid_import_progress',503); }
        $mapping=$config['filemaker']['tables'][$progress['table_name']];
        $rows=rgcCache_sourcePage($config,$mapping,(string)$progress['after_id'],$deadline,$fetch);
        $prepared=[]; $total=0;
        $maximum=min($config['limits']['record_bytes'],rgcCache_packetLimit($pdo));
        foreach ($rows as $row) {
            $payload=json_encode($row['data'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            if (strlen($payload)>$maximum) { throw new rgcCache_Error('record_exceeds_database_or_config_limit',503); }
            $total+=strlen($payload);
            if ($total>$config['limits']['resolved_bytes']) { throw new rgcCache_Error('import_page_too_large_reduce_page_size',503); }
            $primary=rgcCache_primaryIdentity($mapping,$row['data']);
            $modified=isset($mapping['modified_at_field']) ? ($row['data']->{$mapping['modified_at_field']} ?? null) : null;
            if ($modified!==null && (!is_scalar($modified) || strlen((string)$modified)>16384)) { throw new rgcCache_Error('invalid_source_identity',502); }
            $prepared[]=[
                'scope'=>[$id,$config['filemaker']['file'],$progress['table_name']],
                'record_id'=>$row['id'],
                'primary'=>$primary,
                'modified'=>$modified===null ? null : (string)$modified,
                'payload'=>$payload,
            ];
        }
        if (microtime(true)>=$deadline) { throw new rgcCache_Error('request_time_budget_exhausted',503); }
        $pdo->beginTransaction();
        foreach ($prepared as $record) {
            if ($record['primary'] !== null) {
                rgcCache_identityAssign($pdo,$prefix,$record['scope'],$record['record_id'],$record['primary'],false);
            }
            rgcCache_query(
                $pdo,
                "INSERT INTO {$prefix}records (installation_id,file_name,table_name,fm_record_id,primary_key,source_modified_at,payload) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE primary_key=VALUES(primary_key),source_modified_at=VALUES(source_modified_at),payload=VALUES(payload),synced_at=CURRENT_TIMESTAMP(6)",
                [...$record['scope'],$record['record_id'],$record['primary']['value']??null,$record['modified'],$record['payload']]
            );
        }
        $after=$rows ? $rows[array_key_last($rows)]['id'] : (string)$progress['after_id'];
        // Only an empty page ends a table: servers may return less than $top.
        rgcCache_query($pdo,"UPDATE {$prefix}import_progress SET after_id=?,done=?,updated_at=CURRENT_TIMESTAMP(6) WHERE installation_id=? AND table_name=?",[$after,$rows ? 0 : 1,$id,$progress['table_name']]);
        $remaining=(int)rgcCache_query($pdo,"SELECT COUNT(*) FROM {$prefix}import_progress WHERE installation_id=? AND done=0",[$id])->fetchColumn();
        if (!$remaining) { rgcCache_query($pdo,"UPDATE {$prefix}installations SET initialized=1,importing=0 WHERE id=?",[$id]); }
        $pdo->commit();
        return ['page_records'=>count($rows),'table'=>$progress['table_name']]+rgcCache_importStatus($pdo,$config);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $error;
    } finally { rgcCache_query($pdo,'SELECT RELEASE_LOCK(?)',[$lock]); }
}
