<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/private/src/sync.php';
require_once dirname(__DIR__) . '/private/src/odata.php';

function rgcCache_odataAssert(bool $condition, string $description): void
{
    if (!$condition) { throw new RuntimeException($description); }
    echo 'PASS: ', $description, PHP_EOL;
}

function rgcCache_odataReject(callable $callable, string $reason): void
{
    try { $callable(); }
    catch (rgcCache_Error $error) {
        rgcCache_odataAssert($error->reason === $reason, 'rejects with ' . $reason);
        return;
    }
    throw new RuntimeException('Expected ' . $reason);
}

final class rgcCache_FakeODataClient
{
    public array $calls = [];
    public function __construct(private array $result) {}
    public function request(mixed ...$arguments): array { $this->calls[] = $arguments; return $this->result; }
    public function isError(array $result): bool { return ($result['error'] ?? '') !== '' || (int)($result['status']['http_code'] ?? 0) < 200 || (int)($result['status']['http_code'] ?? 0) >= 300; }
    public function getResponse(array $result): mixed { return $result['result'] ?? null; }
}

$config = ['filemaker'=>[
    'file'=>'CWP Snippets',
    'odata_url'=>'https://dev.example.test/fmi/odata/v4/CWP%20Snippets',
    'user'=>'api',
    'password'=>'secret',
]];
$connection = rgcCache_odataConnection($config);
rgcCache_odataAssert($connection['host'] === 'https://dev.example.test' && $connection['database'] === 'CWP Snippets', 'derives one odataCWP connection from rgcCache config');
rgcCache_odataReject(fn()=>rgcCache_odataConnection(['filemaker'=>array_replace($config['filemaker'], ['file'=>'Wrong'])]), 'filemaker_database_url_mismatch');

$mapping = ['entity'=>'People Table', 'record_id_field'=>'ROWID', 'fields'=>['ROWID','First Name','Amount']];
$record = rgcCache_odataRecordDescriptor($mapping, '42');
rgcCache_odataAssert($record['path'] === 'People%20Table' && $record['query']['$select'] === 'ROWID,First Name,Amount', 'preserves proven unquoted FileMaker select grammar');
rgcCache_odataAssert($record['query']['$filter'] === '"ROWID" eq 42' && $record['query']['$top'] === '2', 'builds bounded ROWID record lookup');

$numericPrimary = ['entity'=>'People', 'record_id_field'=>'ContactNumber', 'fields'=>['ContactNumber','Name']];
$page = rgcCache_odataPageDescriptor($numericPrimary, '9007199254740993', 100);
rgcCache_odataAssert($page['query']['$filter'] === '"ContactNumber" gt 9007199254740993' && $page['query']['$orderby'] === '"ContactNumber" asc', 'supports configured numeric record identity without forcing ROWID');
rgcCache_odataAssert(rgcCache_odataMetadataDescriptor()['options']['accept'] === 'application/json', 'requests the JSON metadata format rgcCache validates');

$captured = [];
$realClient = rgcCache_odataClient($config, 'People Table', [
    'httpClient'=>static function ($method, $url, $headers, $body, $options) use (&$captured): array {
        $captured = compact('method', 'url', 'headers', 'body', 'options');
        return ['status'=>200, 'headers'=>['content-type'=>'application/json'], 'body'=>'{"version":"4.0"}', 'error'=>''];
    },
]);
$metadataResult = $realClient->getMetadata();
rgcCache_odataAssert(!$realClient->isError($metadataResult) && $realClient->getResponse($metadataResult) === ['version'=>'4.0'], 'odataCWP metadata convenience method returns decoded JSON');
rgcCache_odataAssert(($captured['headers']['Accept'] ?? null) === 'application/json', 'odataCWP metadata convenience method requests JSON rather than XML');
$metadataText = rgcCache_odataExecute($realClient, rgcCache_odataMetadataDescriptor(), microtime(true) + 5, 4096);
rgcCache_odataAssert(\ODataCWP\ODataCWP::VERSION === '1.1.0-fmmeets.1-rgccache.1', 'loads the selected FMMeets odataCWP build with the rgcCache patch');
rgcCache_odataAssert($metadataText === '{"version":"4.0"}' && ($captured['headers']['Accept'] ?? null) === 'application/json', 'uses the real client while keeping metadata JSON-only');
rgcCache_odataAssert(str_ends_with($captured['url'] ?? '', '/fmi/odata/v4/CWP%20Snippets/$metadata'), 'builds the expected FileMaker metadata URL');

$smokeCalls = [];
$smokeClient = rgcCache_odataClient($config, 'People Table', [
    'httpClient'=>static function ($method, $url, $headers, $body, $options) use (&$smokeCalls): array {
        $smokeCalls[] = compact('method', 'url', 'headers', 'body', 'options');
        $path = (string)parse_url($url, PHP_URL_PATH);
        $response = str_ends_with($path, '/$metadata')
            ? '{"$Version":"4.0","@ServerVersion":"FileMaker test","CWP Snippets":{"People Table":{"$Kind":"EntitySet","$Type":"CWP Snippets.PeopleType"},"PeopleType":{"$Kind":"EntityType","$Key":["First Name"],"First Name":{"@FieldID":"1","$Type":"Edm.String"}}}}'
            : (str_ends_with($path, '/People%20Table')
                ? '{"value":[{"ROWID":42,"First Name":"Alex","Amount":50}]}'
                : '{"value":[{"name":"People Table","kind":"EntitySet"}]}');
        return ['status'=>200, 'headers'=>['content-type'=>'application/json'], 'body'=>$response, 'error'=>''];
    },
]);
$catalogText = rgcCache_odataExecute($smokeClient, rgcCache_odataCatalogDescriptor(), microtime(true) + 5, 4096);
$smokeMetadataText = rgcCache_odataExecute($smokeClient, rgcCache_odataMetadataDescriptor(), microtime(true) + 5, 4096);
$recordText = rgcCache_odataExecute($smokeClient, $record, microtime(true) + 5, 4096);
$pageText = rgcCache_odataExecute($smokeClient, rgcCache_odataPageDescriptor($mapping, '0', 100), microtime(true) + 5, 4096);
rgcCache_odataAssert((rgcCache_json($catalogText)->value[0]->name ?? null) === 'People Table', 'real client returns the FileMaker table catalog as JSON');
rgcCache_odataAssert(rgcCache_sourceMetadata($config, microtime(true) + 5, fn()=>$smokeMetadataText)['tables']['People Table']['fields'][1]['name'] === 'First Name', 'real client JSON metadata feeds the existing schema parser');
rgcCache_odataAssert(rgcCache_sourceMetadata($config, microtime(true) + 5, fn()=>$smokeMetadataText)['tables']['People Table']['suggested_primary_key_field'] === 'First Name', 'JSON metadata exposes the single FileMaker-declared key as an admin suggestion');
rgcCache_odataAssert((string)(rgcCache_json($recordText)->value[0]->ROWID ?? '') === '42', 'real client returns an exact record response as JSON');
rgcCache_odataAssert((string)(rgcCache_json($pageText)->value[0]->ROWID ?? '') === '42', 'real client returns a keyset page response as JSON');
$recordQuery = [];
parse_str((string)parse_url($smokeCalls[2]['url'], PHP_URL_QUERY), $recordQuery);
$pageQuery = [];
parse_str((string)parse_url($smokeCalls[3]['url'], PHP_URL_QUERY), $pageQuery);
rgcCache_odataAssert(($recordQuery['$select'] ?? null) === 'ROWID,First Name,Amount' && ($recordQuery['$filter'] ?? null) === '"ROWID" eq 42', 'real client preserves the proven exact-record query grammar');
rgcCache_odataAssert(($pageQuery['$orderby'] ?? null) === '"ROWID" asc' && ($pageQuery['$top'] ?? null) === '100', 'real client preserves the proven keyset-page query grammar');

$payload = '{"value":[{"ROWID":42,"Amount":12345678901234567890.125}]}';
$client = new rgcCache_FakeODataClient(['status'=>['http_code'=>200], 'result'=>$payload, 'error'=>'']);
$returned = rgcCache_odataExecute($client, $record, microtime(true) + 5, 4096);
rgcCache_odataAssert($returned === $payload && $client->calls[0][4]['responseType'] === 'text', 'returns raw JSON for rgcCache exact and duplicate-key validation');

$large = new rgcCache_FakeODataClient(['status'=>['http_code'=>0], 'result'=>[], 'error'=>'large', 'error_code'=>'response_too_large']);
rgcCache_odataReject(fn()=>rgcCache_odataExecute($large, $record, microtime(true) + 5, 16), 'source_response_too_large');
$timeout = new rgcCache_FakeODataClient(['status'=>['http_code'=>0], 'result'=>[], 'error'=>'timeout', 'error_code'=>'request_timeout']);
rgcCache_odataReject(fn()=>rgcCache_odataExecute($timeout, $record, microtime(true) + 5, 16), 'request_time_budget_exhausted');

echo 'All odataCWP adapter compatibility checks passed.', PHP_EOL;
