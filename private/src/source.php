<?php
declare(strict_types=1);
require_once __DIR__ . '/odata.php';

function rgcCache_sourceUrl(array $config, array $mapping, string $id): string
{
    rgcCache_recordId($id);
    $quote = static fn(string $field): string => '"' . $field . '"';
    return rtrim($config['filemaker']['odata_url'], '/') . '/' . rawurlencode($mapping['entity']) . '?' . str_replace(['%24','%2A%2C'], ['$', '*,'], http_build_query([
        // Validated metadata identifiers: FileMaker rejects SQL-style quoting
        // of each property in a multi-field $select on the live server.
        '$select'=>$mapping['fields'] ? implode(',', $mapping['fields']) : '*,' . $mapping['record_id_field'],
        '$filter'=>$quote($mapping['record_id_field']) . ' eq ' . $id,
        '$top'=>'2',
    ], '', '&', PHP_QUERY_RFC3986));
}

function rgcCache_sourceRecord(array $config, array $mapping, string $id, float $deadline, ?callable $fetch = null): ?stdClass
{
    $url = rgcCache_sourceUrl($config, $mapping, $id);
    $maximum = $config['limits']['record_bytes'] + 65536;
    $text = $fetch
        ? $fetch($url, $config, $deadline)
        : rgcCache_odataExecute(
            rgcCache_odataClient($config, $mapping['entity']),
            rgcCache_odataRecordDescriptor($mapping, $id),
            $deadline,
            $maximum
        );
    if (!is_string($text) || strlen($text) > $maximum) { throw new rgcCache_Error('source_response_too_large', 502); }
    try { $envelope = rgcCache_json($text, true); }
    catch (rgcCache_Error $error) { throw new rgcCache_Error('source_invalid_json', 502); }
    if (!$envelope instanceof stdClass || isset($envelope->error) || !isset($envelope->value) || !is_array($envelope->value) || count($envelope->value) > 1 || isset($envelope->{'@odata.nextLink'})) { throw new rgcCache_Error('source_ambiguous_or_invalid_collection', 502); }
    if (!$envelope->value) { return null; }
    $row = $envelope->value[0];
    if (!$row instanceof stdClass || rgcCache_recordId($row->{$mapping['record_id_field']} ?? null) !== $id) { throw new rgcCache_Error('source_wrong_record', 502); }
    return rgcCache_selectRow($row, $mapping);
}

function rgcCache_selectRow(stdClass $row, array $mapping): stdClass
{
    $selected = new stdClass();
    if (!$mapping['fields']) {
        foreach ($row as $field => $value) {
            // OData annotations/navigation links are not cached as field data.
            if (!str_contains($field, '@')) { $selected->{$field} = $value; }
        }
        return $selected;
    }
    foreach ($mapping['fields'] as $field) {
        if (!property_exists($row, $field)) { throw new rgcCache_Error('source_selected_field_missing', 502); }
        $selected->{$field} = $row->{$field};
    }
    return $selected;
}

function rgcCache_configHash(array $config): string
{
    return hash('sha256', json_encode([$config['filemaker']['file'], $config['filemaker']['odata_url'], $config['filemaker']['tables']], JSON_THROW_ON_ERROR));
}

function rgcCache_expandTables(array $config, float $deadline, ?callable $fetch = null): array
{
    if ($config['filemaker']['tables']) { return $config; }
    $url = rtrim($config['filemaker']['odata_url'], '/') . '/';
    $text = $fetch
        ? $fetch($url, $config, $deadline)
        : rgcCache_odataExecute(
            rgcCache_odataClient($config, '_catalog'),
            rgcCache_odataCatalogDescriptor(),
            $deadline,
            8388608
        );
    if (strlen($text) > 8388608) { throw new rgcCache_Error('table_catalog_too_large',502); }
    $catalog = rgcCache_json($text);
    if (!$catalog instanceof stdClass || !isset($catalog->value) || !is_array($catalog->value) || isset($catalog->{'@odata.nextLink'}) || isset($catalog->error)) { throw new rgcCache_Error('invalid_table_catalog',502); }
    foreach ($catalog->value as $item) {
        if (!$item instanceof stdClass || ($item->kind ?? '') !== 'EntitySet') { continue; }
        $name = $item->name ?? null;
        if (!is_string($name) || $name === '' || strlen($name)>191 || preg_match('/[\x00-\x1f]/',$name)) { throw new rgcCache_Error('unsupported_table_name',502); }
        // Never follow a catalog URL to another host; use a name under our root.
        $config['filemaker']['tables'][$name] = ['entity'=>$name,'record_id_field'=>'ROWID','fields'=>[]];
    }
    if (!$config['filemaker']['tables']) { throw new rgcCache_Error('no_visible_tables',502); }
    return $config;
}

function rgcCache_sourceMetadata(array $config, float $deadline, ?callable $fetch = null): array
{
    $url = rtrim($config['filemaker']['odata_url'], '/') . '/$metadata';
    $text = $fetch
        ? $fetch($url, $config, $deadline)
        : rgcCache_odataExecute(
            rgcCache_odataClient($config, '_metadata'),
            rgcCache_odataMetadataDescriptor(),
            $deadline,
            8388608
        );
    if (!is_string($text) || strlen($text) > 8388608) { throw new rgcCache_Error('metadata_too_large', 502); }
    // FileMaker metadata can contain duplicate enum labels (for example, two
    // value-list entries named "-"). Those enums are not used here. Standard
    // decoding tolerates them; the entity/table/field structures we consume
    // are validated explicitly below. Transaction and record JSON still use
    // the duplicate-rejecting rgcCache_json parser.
    try { $metadata = json_decode($text, false, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); }
    catch (JsonException $error) { throw new rgcCache_Error('invalid_source_metadata', 502); }
    if (!$metadata instanceof stdClass) { throw new rgcCache_Error('invalid_source_metadata', 502); }
    $file = $config['filemaker']['file'];
    $schema = $metadata->{$file} ?? null;
    if (!$schema instanceof stdClass) { throw new rgcCache_Error('metadata_schema_not_found', 502); }

    $tables = [];
    foreach ($schema as $entity => $definition) {
        if (!$definition instanceof stdClass || ($definition->{'$Kind'} ?? null) !== 'EntitySet') { continue; }
        $type = $definition->{'$Type'} ?? null;
        if (!is_string($type) || !str_starts_with($type, $file . '.')) { throw new rgcCache_Error('invalid_source_metadata', 502); }
        $typeName = substr($type, strlen($file) + 1);
        $entityType = $schema->{$typeName} ?? null;
        if (!$entityType instanceof stdClass || ($entityType->{'$Kind'} ?? null) !== 'EntityType') { throw new rgcCache_Error('invalid_source_metadata', 502); }
        $fields = [[
            'name'=>'ROWID', 'type'=>'Edm.Int64', 'nullable'=>false, 'computed'=>true,
            'calculation'=>false, 'global'=>false, 'binary'=>false, 'builtin'=>true,
        ]];
        foreach ($entityType as $name => $property) {
            if (!$property instanceof stdClass || !property_exists($property, '@FieldID')) { continue; }
            try { rgcCache_mappingField($name); }
            catch (InvalidArgumentException $error) { continue; }
            $fieldType = $property->{'$Type'} ?? null;
            if (!is_string($fieldType) || !preg_match('/^Edm\.[A-Za-z0-9]+$/D', $fieldType)) { throw new rgcCache_Error('invalid_source_metadata', 502); }
            $fields[] = [
                'name'=>$name,
                'type'=>$fieldType,
                'nullable'=>($property->{'$Nullable'} ?? false) === true,
                'computed'=>($property->{'@Org.OData.Core.V1.Computed'} ?? false) === true,
                'calculation'=>($property->{'@Calculation'} ?? false) === true,
                'global'=>($property->{'@Global'} ?? false) === true,
                'binary'=>$fieldType === 'Edm.Binary',
                'builtin'=>false,
            ];
        }
        $fieldNames = array_column($fields, 'name');
        $declaredKeys = [];
        $rawKeys = $entityType->{'$Key'} ?? [];
        if (is_array($rawKeys)) {
            foreach ($rawKeys as $key) {
                if (is_string($key) && in_array($key, $fieldNames, true) && !in_array($key, $declaredKeys, true)) {
                    $declaredKeys[] = $key;
                }
            }
        }
        $suggestedPrimary = count($declaredKeys) === 1 && $declaredKeys[0] !== 'ROWID' ? $declaredKeys[0] : null;
        usort($fields, static fn(array $left, array $right): int => $left['builtin'] ? -1 : ($right['builtin'] ? 1 : strcasecmp($left['name'], $right['name'])));
        $tables[$entity] = [
            'entity'=>$entity,
            'record_id_field'=>'ROWID',
            'declared_primary_keys'=>$declaredKeys,
            'suggested_primary_key_field'=>$suggestedPrimary,
            'fields'=>$fields,
        ];
    }
    if (!$tables) { throw new rgcCache_Error('no_visible_tables', 502); }
    ksort($tables, SORT_NATURAL | SORT_FLAG_CASE);
    return [
        'metadata_version'=>(string)($metadata->{'$Version'} ?? ''),
        'server_version'=>(string)($metadata->{'@ServerVersion'} ?? ''),
        'tables'=>$tables,
    ];
}

function rgcCache_sourcePage(array $config, array $mapping, string $after, float $deadline, ?callable $fetch = null): array
{
    if ($after !== '0') { rgcCache_recordId($after); }
    $field = '"' . $mapping['record_id_field'] . '"';
    $url = rtrim($config['filemaker']['odata_url'],'/') . '/' . rawurlencode($mapping['entity']) . '?' . str_replace(['%24','%2A%2C'], ['$', '*,'], http_build_query([
        '$select'=>$mapping['fields'] ? implode(',',$mapping['fields']) : '*,' . $mapping['record_id_field'],
        '$filter'=>$field . ' gt ' . $after, '$orderby'=>$field . ' asc', '$top'=>$config['limits']['import_page_size'],
    ],'', '&', PHP_QUERY_RFC3986));
    $max = $config['limits']['resolved_bytes'] + 65536;
    $text = $fetch
        ? $fetch($url, $config, $deadline)
        : rgcCache_odataExecute(
            rgcCache_odataClient($config, $mapping['entity']),
            rgcCache_odataPageDescriptor($mapping, $after, $config['limits']['import_page_size']),
            $deadline,
            $max
        );
    if (strlen($text)>$max) { throw new rgcCache_Error('import_page_too_large_reduce_page_size',502); }
    $envelope = rgcCache_json($text,true);
    if (!$envelope instanceof stdClass || isset($envelope->error) || !isset($envelope->value) || !is_array($envelope->value) || count($envelope->value)>$config['limits']['import_page_size']) { throw new rgcCache_Error('invalid_import_page',502); }
    $rows = [];
    $last = $after;
    foreach ($envelope->value as $row) {
        if (!$row instanceof stdClass) { throw new rgcCache_Error('invalid_source_row',502); }
        $id = rgcCache_recordId($row->{$mapping['record_id_field']} ?? null);
        if (strlen($id)<strlen($last) || (strlen($id)===strlen($last) && strcmp($id,$last)<=0)) { throw new rgcCache_Error('source_wrong_record_order',502); }
        $rows[] = ['id'=>$id,'data'=>rgcCache_selectRow($row,$mapping)];
        $last = $id;
    }
    // Request the next keyset page ourselves, never follow external nextLink URLs.
    if (!$rows && (isset($envelope->{'@odata.nextLink'}) || isset($envelope->{'@nextLink'}))) { throw new rgcCache_Error('empty_page_with_continuation',502); }
    return $rows;
}
