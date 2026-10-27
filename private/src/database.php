<?php
declare(strict_types=1);

function rgcCache_dbSettings(array $config): array
{
    $db = $config['database'] ?? [];
    foreach (['host', 'name', 'user'] as $key) {
        if (!isset($db[$key]) || !is_string($db[$key]) || $db[$key] === '' || preg_match('/[;\x00-\x1f]/', $db[$key])) {
            throw new RuntimeException('Invalid database ' . $key);
        }
    }
    if (!isset($db['password']) || !is_string($db['password'])) {
        throw new RuntimeException('Database password must be a string');
    }
    if (!is_int($db['port'] ?? null) || $db['port'] < 1 || $db['port'] > 65535) {
        throw new RuntimeException('Invalid database port');
    }
    if (!is_string($db['table_prefix'] ?? null) || !preg_match('/^[A-Za-z][A-Za-z0-9_]{0,31}$/D', $db['table_prefix'])) {
        throw new RuntimeException('Invalid table prefix');
    }
    if (($db['charset'] ?? '') !== 'utf8mb4') {
        throw new RuntimeException('Database charset must be utf8mb4');
    }
    return $db;
}

function rgcCache_connect(array $config): PDO
{
    $db = rgcCache_dbSettings($config);
    $pdo = new PDO('mysql:host=' . $db['host'] . ';port=' . $db['port'] . ';dbname=' . $db['name'] . ';charset=utf8mb4', $db['user'], $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 5,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
    ]);
    // Per-connection settings only; no changes to other website connections.
    $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
    $pdo->exec("SET SESSION time_zone = '+00:00'");
    return $pdo;
}

function rgcCache_schemaSql(string $prefix): array
{
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,31}$/D', $prefix)) {
        throw new RuntimeException('Invalid table prefix');
    }
    $paths = glob(dirname(__DIR__) . '/migrations/*.sql');
    if ($paths === false || !$paths) {
        throw new RuntimeException('Installation SQL missing');
    }
    sort($paths, SORT_STRING);
    $sql = '';
    foreach ($paths as $path) {
        $migration = file_get_contents($path);
        if ($migration === false) { throw new RuntimeException('Installation SQL missing'); }
        $sql .= "\n" . $migration;
    }
    $sql = preg_replace('/^--.*$/m', '', $sql);
    return array_values(array_filter(array_map('trim', explode(';', str_replace('rgcCache_', $prefix, $sql)))));
}

// Required columns for the prototype; extra columns are left untouched.
function rgcCache_schemaColumns(): array
{
    return [
        'installations' => ['id'=>'varchar', 'schema_version'=>'int', 'config_hash'=>'char', 'initialized'=>'tinyint', 'importing'=>'tinyint', 'created_at'=>'datetime'],
        'inbox' => ['id'=>'bigint', 'installation_id'=>'varchar', 'delivery_id'=>'varchar', 'body_hash'=>'char', 'payload'=>'mediumtext', 'status'=>'varchar', 'attempts'=>'int', 'last_error'=>'text', 'received_at'=>'datetime', 'completed_at'=>'datetime'],
        'records' => ['installation_id'=>'varchar', 'file_name'=>'varchar', 'table_name'=>'varchar', 'fm_record_id'=>'bigint', 'primary_key'=>'text', 'source_modified_at'=>'text', 'payload'=>'mediumtext', 'synced_at'=>'datetime'],
        'import_progress' => ['installation_id'=>'varchar', 'table_name'=>'varchar', 'after_id'=>'bigint', 'done'=>'tinyint', 'updated_at'=>'datetime'],
        'mappings' => ['id'=>'varchar', 'status'=>'varchar', 'mapping_json'=>'mediumtext', 'mapping_hash'=>'char', 'created_at'=>'datetime', 'updated_at'=>'datetime', 'activated_at'=>'datetime'],
        'record_identities' => ['installation_id'=>'varchar', 'file_name'=>'varchar', 'table_name'=>'varchar', 'primary_key_hash'=>'char', 'primary_key_field'=>'varchar', 'primary_key_value'=>'text', 'fm_record_id'=>'bigint', 'created_at'=>'datetime', 'updated_at'=>'datetime'],
    ];
}

function rgcCache_schemaReport(PDO $pdo, string $prefix): array
{
    rgcCache_schemaSql($prefix); // Validate prefix and availability of installer.
    $missing = [];
    $issues = [];
    $keys = [
        'installations'=>['PRIMARY'=>['id']],
        'inbox'=>['PRIMARY'=>['id'], 'delivery_unique'=>['installation_id','delivery_id']],
        'records'=>['PRIMARY'=>['installation_id','file_name','table_name','fm_record_id']],
        'import_progress'=>['PRIMARY'=>['installation_id','table_name']],
        'mappings'=>['PRIMARY'=>['id']],
        'record_identities'=>['PRIMARY'=>['installation_id','file_name','table_name','primary_key_hash'], 'identity_locator_unique'=>['installation_id','file_name','table_name','fm_record_id']],
    ];
    foreach (rgcCache_schemaColumns() as $suffix => $columns) {
        $table = $prefix . $suffix;
        $query = $pdo->prepare('SELECT ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $query->execute([$table]);
        $info = $query->fetch();
        if (!$info) { $missing[] = $table; continue; }
        if ($info['ENGINE'] !== 'InnoDB' || $info['TABLE_COLLATION'] !== 'utf8mb4_bin') { $issues[] = $table . ': expected InnoDB/utf8mb4_bin'; }
        $query = $pdo->prepare('SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $query->execute([$table]);
        $actual = $query->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($columns as $column => $type) {
            if (($actual[$column] ?? null) !== $type) { $issues[] = $table . '.' . $column . ': missing or incompatible type'; }
        }
        $query = $pdo->prepare('SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME, SEQ_IN_INDEX');
        $query->execute([$table]);
        $indexes = [];
        foreach ($query as $index) { if ((int)$index['NON_UNIQUE'] === 0) { $indexes[$index['INDEX_NAME']][] = $index['COLUMN_NAME']; } }
        foreach ($keys[$suffix] as $key => $fields) { if (($indexes[$key] ?? []) !== $fields) { $issues[] = $table . ': incompatible ' . $key; } }
    }
    return ['missing_tables'=>$missing, 'issues'=>$issues, 'tables_ready'=>!$missing && !$issues];
}

function rgcCache_install(PDO $pdo, string $prefix): array
{
    $before = rgcCache_schemaReport($pdo, $prefix);
    if ($before['issues']) {
        throw new RuntimeException('Existing prefixed tables are incompatible; inspect them or choose a different prefix. No tables changed.');
    }
    // DDL commits independently on MySQL. Rerun safely if a creation is interrupted.
    foreach (rgcCache_schemaSql($prefix) as $statement) { $pdo->exec($statement); }
    $after = rgcCache_schemaReport($pdo, $prefix);
    if (!$after['tables_ready']) { throw new RuntimeException('Table verification failed'); }
    return ['created_tables'=>$before['missing_tables']] + $after;
}

function rgcCache_databaseReport(PDO $pdo, string $prefix): array
{
    $server = $pdo->query('SELECT VERSION() AS server_version, @@max_allowed_packet AS max_allowed_packet, @@session.sql_mode AS sql_mode')->fetch();
    return $server + rgcCache_schemaReport($pdo, $prefix);
}
