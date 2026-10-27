<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/private/src/http.php';
try {
    $rgcCache_config = rgcCache_syncSettings(rgcCache_config());
    $rgcCache_config = rgcCache_expandTables($rgcCache_config, microtime(true) + $rgcCache_config['limits']['source_seconds']);
    $rgcCache_table = $argv[1] ?? '';
    $rgcCache_id = rgcCache_recordId($argv[2] ?? '');
    if (!isset($rgcCache_config['filemaker']['tables'][$rgcCache_table])) { throw new rgcCache_Error('configured_table_required'); }
    $rgcCache_record = rgcCache_sourceRecord($rgcCache_config, $rgcCache_config['filemaker']['tables'][$rgcCache_table], $rgcCache_id, microtime(true) + $rgcCache_config['limits']['source_seconds']);
    echo json_encode(['found'=>$rgcCache_record !== null, 'record_id'=>$rgcCache_id, 'selected_fields'=>$rgcCache_record ? array_keys(get_object_vars($rgcCache_record)) : []], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), PHP_EOL;
    exit($rgcCache_record === null ? 1 : 0);
} catch (Throwable $rgcCache_error) {
    fwrite(STDERR, ($rgcCache_error instanceof rgcCache_Error ? $rgcCache_error->reason : 'configuration_or_source_unavailable') . PHP_EOL);
    fwrite(STDERR, "Usage: php scripts/source.php Contacts RECORD_ID (read-only; requires FileMaker config).\n");
    exit(1);
}
