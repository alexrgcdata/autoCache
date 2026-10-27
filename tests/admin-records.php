<?php
declare(strict_types=1);
// In-memory tests only. Enable pdo_sqlite for this CLI invocation if necessary.
require_once dirname(__DIR__) . '/private/src/http.php';
function checkRecords(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); echo "PASS: $message\n"; }
function rejectRecords(callable $fn, int $status): void { try { $fn(); } catch (rgcCache_Error $e) { checkRecords($e->httpStatus === $status, "rejects HTTP $status"); return; } throw new RuntimeException('Expected rejection'); }
$session = [];
$base = ['base_path'=>'/rgccache', 'admin'=>['username'=>'fixture','password_hash'=>password_hash('synthetic-test-only',PASSWORD_DEFAULT)]];
$body = json_encode(['table'=>'Contacts','query'=>'Maya','after'=>'0']);
$response = rgcCache_dispatch('POST','/rgccache/api/v1/admin/records/query','',$base,$body,'','application/json',null,'',$session);
checkRecords($response['status'] === 401, 'record values require authentication before database access');
$session = ['rgcCache_admin'=>['username'=>'fixture','csrf'=>str_repeat('b',64),'authenticated_at'=>time()]];
$response = rgcCache_dispatch('POST','/rgccache/api/v1/admin/records/query','',$base,$body,'','application/json',null,'wrong',$session);
checkRecords($response['status'] === 403, 'record search requires CSRF before database access');
foreach ([['table'=>[], 'query'=>'', 'after'=>'0'], ['table'=>'Contacts','query'=>str_repeat('x',161),'after'=>'0'], ['table'=>'Contacts','query'=>'','after'=>'1 OR 1=1'], ['table'=>'Contacts','query'=>'','after'=>'0','installation'=>'other']] as $bad) rejectRecords(fn()=>rgcCache_adminRecordInput(json_encode($bad)), $bad['after']==='1 OR 1=1' ? 422 : (isset($bad['installation']) ? 400 : 422));
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$pdo->sqliteCreateFunction('JSON_UNQUOTE', static fn($value) => $value);
$pdo->exec('CREATE TABLE rgcCache_records (installation_id TEXT, file_name TEXT, table_name TEXT, fm_record_id INTEGER, primary_key TEXT, payload TEXT, synced_at TEXT)');
$pdo->exec('CREATE TABLE rgcCache_mappings (id TEXT, status TEXT, mapping_json TEXT, mapping_hash TEXT, created_at TEXT, updated_at TEXT, activated_at TEXT)');
$pdo->exec('CREATE TABLE rgcCache_installations (id TEXT, config_hash TEXT, initialized INTEGER, importing INTEGER)');
$tables = ['Contacts'=>['entity'=>'Contacts','record_id_field'=>'ROWID','fields'=>['ROWID','Name','Email','Amount','Note']]];
$config = ['installation_id'=>'ignored', 'filemaker'=>['file'=>'Demo','odata_url'=>'https://example.invalid/fmi/odata/v4/Demo'], 'database'=>['host'=>'127.0.0.1','port'=>3306,'name'=>'unused','user'=>'unused','password'=>'','table_prefix'=>'rgcCache_','charset'=>'utf8mb4']];
$encoded = rgcCache_mappingEncode($tables);
$pdo->prepare("INSERT INTO rgcCache_mappings VALUES ('active_one','active',?,?,NULL,NULL,NULL)")->execute([$encoded,rgcCache_mappingHash($encoded)]);
$pdo->prepare("INSERT INTO rgcCache_installations VALUES ('active_one',?,1,0)")->execute([rgcCache_mappingConfigHash($config,$tables)]);
$insert = $pdo->prepare('INSERT INTO rgcCache_records VALUES (?,?,?,?,?,?,?)');
for ($i=1; $i<=60; $i++) $insert->execute(['active_one','Demo','Contacts',$i,null,json_encode(['ROWID'=>(string)$i,'Name'=>$i===54?'Maya Chen':'Person '.$i,'Email'=>'person'.$i.'@example.test','Amount'=>100+$i,'Note'=>$i===3?'100%_safe <script>alert(1)</script>':'Plain text','NotSelected'=>'PRIVATE_HIDDEN']),'2026-10-27']);
foreach ([['other','Demo','Contacts'],['active_one','OtherFile','Contacts'],['active_one','Demo','OtherTable']] as $scope) $insert->execute([...$scope,61,null,'{"Name":"Maya wrong scope"}','2026-10-27']);
$search = fn($query,$after='0') => rgcCache_adminRecords($pdo,$config,['table'=>'Contacts','query'=>$query,'after'=>$after]);
$page = $search('');
checkRecords(count($page['records'])===25 && $page['next_after_id']==='25', 'bounded keyset pagination with lookahead');
checkRecords($search('', '25')['records'][0]['id']==='26', 'next page advances without repeating rows');
checkRecords(array_column($search('mAYA')['records'],'id')===['54'], 'case-insensitive values search finds a name beyond the first page and isolates scope');
checkRecords(array_column($search('person54@')['records'],'id')===['54'], 'search matches email contents');
checkRecords(array_column($search('154')['records'],'id')===['54'], 'search matches numeric values');
checkRecords($search('Email')['records']===[], 'field names do not count as matching content');
checkRecords($search('PRIVATE_HIDDEN')['records']===[] && !array_key_exists('NotSelected',$page['records'][0]['fields']), 'unselected fields cannot be searched or returned');
checkRecords(array_column($search('%_')['records'],'id')===['3'], 'percent and underscore are literal characters');
checkRecords($search("' OR 1=1 --")['records']===[], 'SQL-like input is data, not a predicate');
checkRecords(str_contains($search('<script>')['records'][0]['fields']['Note'],'<script>'), 'untrusted text is returned as data for React escaping');
rejectRecords(fn()=>rgcCache_adminRecords($pdo,$config,['table'=>'NotAllowed','query'=>'','after'=>'0']),422);
$insert->execute(['active_one','Demo','Contacts',9007199254740993,null,'{"Name":"large-id"}','2026-10-27']);
checkRecords($search('large-id')['records'][0]['id']==='9007199254740993', 'record IDs remain exact strings beyond JavaScript precision');
$insert->execute(['active_one','Demo','Contacts',9007199254740994,null,json_encode(['Note'=>str_repeat('x',1048577)]),'2026-10-27']);
$large = $search('','9007199254740993');
checkRecords($large['records'][0]['omitted'] === true, 'oversized payload is not sent to the browser');
echo "Admin record search checks passed (SQLite in memory; MySQL/MariaDB deployment still needs acceptance).\n";
