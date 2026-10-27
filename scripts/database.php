<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/private/src/http.php';
require_once dirname(__DIR__) . '/private/src/database.php';
try {
    $rgcCache_command = $argv[1] ?? 'check';
    if (!in_array($rgcCache_command, ['check', 'install', 'sql'], true)) { throw new RuntimeException('Use check, install or sql'); }
    $rgcCache_config = rgcCache_config();
    $rgcCache_db = rgcCache_dbSettings($rgcCache_config);
    if ($rgcCache_command === 'sql') {
        echo implode(";\n\n", rgcCache_schemaSql($rgcCache_db['table_prefix'])), ";\n";
        exit;
    }
    $rgcCache_pdo = rgcCache_connect($rgcCache_config);
    $rgcCache_report = $rgcCache_command === 'install'
        ? rgcCache_install($rgcCache_pdo, $rgcCache_db['table_prefix'])
        : rgcCache_databaseReport($rgcCache_pdo, $rgcCache_db['table_prefix']);
    echo json_encode($rgcCache_report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
    echo 'This verifies tables only. Live FileMaker connectivity and initial import are separate checks.', PHP_EOL;
    exit($rgcCache_report['tables_ready'] ? 0 : 1);
} catch (Throwable $rgcCache_error) {
    // PDO messages can contain connection details. Do not print credentials or SQL values.
    $rgcCache_code = $rgcCache_error instanceof PDOException ? (string)$rgcCache_error->getCode() : 'configuration/schema';
    fwrite(STDERR, 'Database command failed (' . $rgcCache_code . '). Check private/config.php, database permissions and prefixed table compatibility. No credentials logged.' . PHP_EOL);
    exit(1);
}
