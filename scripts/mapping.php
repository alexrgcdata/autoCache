<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/private/src/http.php';
require_once dirname(__DIR__) . '/private/src/mapping.php';

try {
    $command = $argv[1] ?? 'status';
    if (!in_array($command, ['status', 'seed-active'], true)) { throw new RuntimeException('Use status or seed-active'); }
    $config = rgcCache_config();
    $pdo = rgcCache_connect($config);
    $mapping = $command === 'seed-active'
        ? rgcCache_mappingSeedActive($pdo, $config)
        : rgcCache_mappingActive($pdo, $config);
    echo json_encode($mapping === null ? ['status'=>'none'] : [
        'id'=>$mapping['id'], 'status'=>$mapping['status'], 'hash'=>$mapping['hash'],
        'tables'=>$mapping['tables'], 'created_at'=>$mapping['created_at'],
        'updated_at'=>$mapping['updated_at'], 'activated_at'=>$mapping['activated_at'],
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Mapping command failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
