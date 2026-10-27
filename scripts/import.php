<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/private/src/http.php';
try {
    $rgcCache_action=$argv[1] ?? 'status';
    $rgcCache_config=rgcCache_config();
    $rgcCache_paused=in_array('--source-writes-paused',$argv,true);
    if ($rgcCache_action==='run') {
        $rgcCache_result=rgcCache_importRequest('start',$rgcCache_config,$rgcCache_paused);
        echo json_encode($rgcCache_result,JSON_THROW_ON_ERROR),PHP_EOL;
        while ($rgcCache_result['state']==='importing') {
            $rgcCache_result=rgcCache_importRequest('step',$rgcCache_config);
            echo json_encode($rgcCache_result,JSON_THROW_ON_ERROR),PHP_EOL;
        }
    } else {
        echo json_encode(rgcCache_importRequest($rgcCache_action,$rgcCache_config,$rgcCache_paused),JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT),PHP_EOL;
    }
} catch (Throwable $rgcCache_error) {
    fwrite(STDERR,($rgcCache_error instanceof rgcCache_Error ? $rgcCache_error->reason : 'import_configuration_source_or_database_failed').PHP_EOL);
    fwrite(STDERR,"Usage: php scripts/import.php discover|status|start|step|run [--source-writes-paused]\nProgress already committed is retained.\n");
    exit(1);
}
