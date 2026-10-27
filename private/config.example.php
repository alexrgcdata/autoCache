<?php
declare(strict_types=1);
// Copy to config.php. Keep this folder OUTSIDE the website document root.
return [
    'base_path'=>'/autocache',
    'installation_id'=>'default',
    'webhook_token'=>'', // Generate with: php scripts/token.php
    'admin'=>[
        'username'=>'',
        'password_hash'=>'', // Generate with: php scripts/admin-password.php
        'session_name'=>'autoCacheAdmin',
    ],
    'database'=>[
        'host'=>'127.0.0.1', 'port'=>3306, 'name'=>'', 'user'=>'', 'password'=>'',
        'table_prefix'=>'rgcCache_', 'charset'=>'utf8mb4',
    ],
    'filemaker'=>[
        'file'=>'', // Exact FileMaker filename, without .fmp12
        'odata_url'=>'', // https://YOUR-SERVER/fmi/odata/v4/YOUR-ENCODED-FILENAME
        'user'=>'', 'password'=>'',
        // Bootstrap/discovery input only. Runtime authorization comes exclusively
        // from the active rgcCache_mappings row and fails closed when absent.
        // [] discovers all tables only through the protected discovery operation.
        // ['Contacts'=>['fields'=>['ROWID','Name']]] can seed the first mapping.
        'tables'=>[],
    ],
    'limits'=>[
        'notification_bytes'=>1048576, 'record_bytes'=>4194304,
        'records_per_request'=>25, 'request_seconds'=>20, 'source_seconds'=>10,
        'batches_per_request'=>3, 'resolved_bytes'=>8388608, 'pending_batches'=>1000,
        'completed_inbox_keep'=>5000, 'import_page_size'=>100,
    ],
];
