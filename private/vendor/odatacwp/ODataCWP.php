<?php
/**
 * A standalone PHP wrapper around the Claris FileMaker OData API.
 *
 * The public API intentionally resembles restCWP while keeping OData concepts
 * such as tables, primary keys, filters, and navigation properties explicit.
 */

namespace ODataCWP;

class ODataCWP {
	const VERSION = '1.1.0-fmmeets.1-rgccache.1';

	private $host;
	private $scheme = 'https';
	private $db;
	private $table;
	private $user;
	private $password;
	private $allowInsecure = false;
	private $timeout = 100;
	private $requestDefaults = array(
		'connectTimeout' => 30,
		'deadline' => null,
		'maxResponseBytes' => null,
		'ieee754Compatible' => false,
		'jsonNumbersAsStrings' => false,
		'strictJson' => false,
	);
	private $httpClient = null;
	private $authorizationHeader = '';
	private $lastRequest = array();
	private $curlOptions = array();
	private $logDir = '';
	private $logType = self::LOG_TYPE_NONE;
	private $logStorage = self::LOG_STORAGE_FILE;
	private $sqlConnection = array();
	private $sqlPdo = null;

	const LOG_TYPE_DEBUG = 'debug';
	const LOG_TYPE_ERRORS = 'errors';
	const LOG_TYPE_NONE = 'none';
	const LOG_STORAGE_FILE = 'file';
	const LOG_STORAGE_DATABASE = 'database';

	/**
	 * @param string     $host FileMaker Server host, with or without https://.
	 * @param string     $db Hosted database name, with or without .fmp12.
	 * @param string     $table Default table occurrence or FMTID.
	 * @param string     $user FileMaker account name.
	 * @param string     $password FileMaker account password.
	 * @param array|null $options Connection/logging options plus connectTimeout (seconds),
	 * deadline (absolute microtime(true) seconds), maxResponseBytes (positive integer),
	 * ieee754Compatible, jsonNumbersAsStrings, and strictJson. New limits are opt-in.
	 */
	public function __construct( $host, $db, $table, $user, $password, $options = null ) {
		$host = trim( (string) $host );
		if ( preg_match( '#^(https?)://#i', $host, $matches ) ) {
			$this->scheme = strtolower( $matches[1] );
		}
		$this->host     = rtrim( preg_replace( '#^https?://#i', '', $host ), '/' );
		$this->db       = preg_replace( '/\.fmp12$/i', '', trim( (string) $db ) );
		$this->table    = trim( (string) $table );
		$this->user     = (string) $user;
		$this->password = (string) $password;
		$this->logDir   = $this->defaultStoragePath( 'logs' );

		if ( is_array( $options ) ) {
			$this->setOptions( $options );
		}
	}

	/** Load a normal PHP configuration or an encrypted SQL-backed profile. */
	public static function loadConfig( $path ) {
		if ( ! is_string( $path ) || '' === $path || ! is_file( $path ) ) {
			throw new \RuntimeException( 'ODataCWP configuration file was not found.' );
		}
		$config = require $path;
		if ( ! is_array( $config ) ) {
			throw new \RuntimeException( 'ODataCWP configuration must return an array.' );
		}
		$configStorage = isset( $config['configStorage'] ) ? strtolower( (string) $config['configStorage'] ) : 'file';
		if ( 'file' === $configStorage ) {
			return $config;
		}
		if ( 'database' !== $configStorage ) {
			throw new \RuntimeException( 'configStorage must be file or database.' );
		}

		$profile       = self::normalizeConfigProfile( isset( $config['configProfile'] ) ? $config['configProfile'] : 'default' );
		$sqlConnection = self::sqlConnectionFromConfig( $config );
		$pdo           = self::createSqlPdo( $sqlConnection );
		$tables        = self::sqlTableNames( $sqlConnection );
		$statement     = $pdo->prepare( 'SELECT payload FROM ' . $tables['config'] . ' WHERE profile = :profile' );
		$statement->execute( array( 'profile' => $profile ) );
		$payload = $statement->fetchColumn();
		if ( ! is_string( $payload ) || '' === $payload ) {
			throw new \RuntimeException( 'No ODataCWP configuration profile named "' . $profile . '" exists in SQL storage.' );
		}
		$stored = self::jsonDecode( self::decryptSqlValue( $payload, $sqlConnection['encryptionKey'] ) );
		if ( ! is_array( $stored ) ) {
			throw new \RuntimeException( 'The SQL-backed ODataCWP configuration is invalid.' );
		}
		$stored['configStorage'] = 'database';
		$stored['configProfile'] = $profile;
		$stored['options'] = isset( $stored['options'] ) && is_array( $stored['options'] ) ? $stored['options'] : array();
		$stored['options']['sqlConnection'] = $sqlConnection;
		return $stored;
	}

	/** Test a SQL connection and create ODataCWP configuration/log tables. */
	public static function setupSqlStorage( array $sqlConnection ) {
		$sqlConnection = self::normalizeSqlConnection( $sqlConnection );
		$pdo = self::createSqlPdo( $sqlConnection );
		self::createSqlTables( $pdo, $sqlConnection );
		return true;
	}

	/** Save an encrypted ODataCWP configuration profile. */
	public static function saveDatabaseConfig( array $bootstrap, array $config ) {
		$profile = self::normalizeConfigProfile( isset( $bootstrap['configProfile'] ) ? $bootstrap['configProfile'] : 'default' );
		$sqlConnection = self::sqlConnectionFromConfig( $bootstrap );
		$pdo = self::createSqlPdo( $sqlConnection );
		self::createSqlTables( $pdo, $sqlConnection );
		unset( $config['configStorage'], $config['configProfile'] );
		if ( isset( $config['options'] ) && is_array( $config['options'] ) ) {
			unset( $config['options']['sqlConnection'] );
		}
		$payload = self::encryptSqlValue( self::jsonEncode( $config ), $sqlConnection['encryptionKey'] );
		self::upsertSqlConfig( $pdo, $sqlConnection, $profile, $payload );
		return true;
	}

	/** Read recent SQL-backed log entries for the authenticated log viewer. */
	public static function readDatabaseLogs( array $sqlConnection, $limit = 200, $level = null ) {
		$sqlConnection = self::normalizeSqlConnection( $sqlConnection );
		$limit = filter_var( $limit, FILTER_VALIDATE_INT );
		if ( false === $limit || $limit < 1 || $limit > 500 ) {
			throw new \InvalidArgumentException( 'Database log limit must be between 1 and 500.' );
		}
		$level = null === $level ? '' : strtolower( trim( (string) $level ) );
		if ( ! in_array( $level, array( '', 'error', 'success', 'info', 'warning', 'debug' ), true ) ) {
			throw new \InvalidArgumentException( 'Database log level is invalid.' );
		}
		$pdo = self::createSqlPdo( $sqlConnection );
		$table = self::sqlTableNames( $sqlConnection )['logs'];
		$where = '' === $level ? '' : ' WHERE level = :level';
		$statement = $pdo->prepare( 'SELECT id, created_at, request_id, level, source, message, context FROM ' . $table . $where . ' ORDER BY id DESC LIMIT ' . (int) $limit );
		$statement->execute( '' === $level ? array() : array( 'level' => $level ) );
		$logs = $statement->fetchAll();
		foreach ( $logs as &$log ) {
			$log['context'] = null === $log['context'] || '' === $log['context'] ? null : self::jsonDecode( self::decryptSqlValue( $log['context'], $sqlConnection['encryptionKey'] ) );
		}
		unset( $log );
		return $logs;
	}

	/** Create a client from ODataCWP/config.php or another PHP config file. */
	public static function fromConfig( $path = null ) {
		$config = self::loadConfig( null === $path ? __DIR__ . '/config.php' : $path );
		foreach ( array( 'host', 'database', 'table', 'user', 'password' ) as $required ) {
			if ( ! array_key_exists( $required, $config ) ) {
				throw new \RuntimeException( sprintf( 'ODataCWP configuration is missing "%s".', $required ) );
			}
		}
		return new self( $config['host'], $config['database'], $config['table'], $config['user'], $config['password'], isset( $config['options'] ) ? $config['options'] : null );
	}

	private function setOptions( $options ) {
		if ( array_key_exists( 'storagePath', $options ) && '' !== trim( (string) $options['storagePath'] ) ) {
			$this->logDir = rtrim( (string) $options['storagePath'], '/\\' ) . DIRECTORY_SEPARATOR . 'logs';
		}
		if ( isset( $options['logDir'] ) && '' !== trim( (string) $options['logDir'] ) ) {
			$this->logDir = rtrim( (string) $options['logDir'], '/\\' );
		}
		if ( isset( $options['logType'] ) ) {
			$logType = strtolower( (string) $options['logType'] );
			if ( ! in_array( $logType, array( self::LOG_TYPE_NONE, self::LOG_TYPE_ERRORS, self::LOG_TYPE_DEBUG ), true ) ) {
				throw new \InvalidArgumentException( 'logType must be none, errors, or debug.' );
			}
			$this->logType = $logType;
		}
		if ( isset( $options['sqlConnection'] ) ) {
			if ( ! is_array( $options['sqlConnection'] ) ) {
				throw new \InvalidArgumentException( 'sqlConnection must be an array.' );
			}
			$this->sqlConnection = self::normalizeSqlConnection( $options['sqlConnection'] );
		}
		if ( isset( $options['logStorage'] ) ) {
			if ( ! in_array( $options['logStorage'], array( self::LOG_STORAGE_FILE, self::LOG_STORAGE_DATABASE ), true ) ) {
				throw new \InvalidArgumentException( 'logStorage must be file or database.' );
			}
			$this->logStorage = $options['logStorage'];
		}
		if ( self::LOG_STORAGE_DATABASE === $this->logStorage && empty( $this->sqlConnection ) ) {
			throw new \InvalidArgumentException( 'Database log storage requires options.sqlConnection.' );
		}
		if ( isset( $options['allowInsecure'] ) ) {
			$this->allowInsecure = (bool) $options['allowInsecure'];
		}
		if ( isset( $options['timeout'] ) ) {
			$this->timeout = max( 1, (int) $options['timeout'] );
		}
		if ( isset( $options['httpClient'] ) && is_callable( $options['httpClient'] ) ) {
			$this->httpClient = $options['httpClient'];
		}
		if ( isset( $options['authorizationHeader'] ) ) {
			$this->authorizationHeader = str_replace( array( "\r", "\n" ), '', trim( (string) $options['authorizationHeader'] ) );
		}
		if ( isset( $options['curlOptions'] ) && is_array( $options['curlOptions'] ) ) {
			$this->curlOptions = $options['curlOptions'];
		}
		foreach ( $this->requestDefaults as $name => $default ) {
			if ( array_key_exists( $name, $options ) ) {
				$this->requestDefaults[ $name ] = $this->validateRequestOption( $name, $options[ $name ] );
			}
		}
	}

	private function validateRequestOption( $name, $value ) {
		if ( in_array( $name, array( 'ieee754Compatible', 'jsonNumbersAsStrings', 'strictJson' ), true ) ) {
			if ( ! is_bool( $value ) ) {
				throw new \InvalidArgumentException( $name . ' must be a boolean.' );
			}
			return $value;
		}
		if ( null === $value && in_array( $name, array( 'deadline', 'maxResponseBytes' ), true ) ) {
			return null;
		}
		if ( 'maxResponseBytes' === $name ) {
			if ( ! is_int( $value ) || $value < 1 ) {
				throw new \InvalidArgumentException( 'maxResponseBytes must be a positive integer or null.' );
			}
		} elseif ( ( ! is_int( $value ) && ! is_float( $value ) ) || ! is_finite( (float) $value ) || $value <= 0 ) {
			throw new \InvalidArgumentException( $name . ' must be a finite positive number.' );
		}
		return $value;
	}

	/** Request overrides can tighten, but never remove, client-level limits. */
	private function resolveRequestOptions( array $options ) {
		$defaults = array_merge( $this->requestDefaults, array( 'timeout' => $this->timeout ) );
		foreach ( $defaults as $name => $default ) {
			$value = array_key_exists( $name, $options ) ? $this->validateRequestOption( $name, $options[ $name ] ) : $default;
			if ( in_array( $name, array( 'timeout', 'connectTimeout', 'deadline', 'maxResponseBytes' ), true ) && null !== $default ) {
				$value = null === $value ? $default : min( $default, $value );
			}
			$options[ $name ] = $value;
		}
		$options['connectTimeout'] = min( $options['connectTimeout'], $options['timeout'] );
		return $options;
	}

	private function deadlineExceeded( array $options ) {
		return null !== $options['deadline'] && microtime( true ) >= $options['deadline'];
	}

	/** Change the default OData table for later calls. */
	public function setFileMakerTable( $table ) {
		$this->table = trim( (string) $table );
		return $this;
	}

	/** Compatibility-friendly shorter alias. */
	public function setTable( $table ) {
		return $this->setFileMakerTable( $table );
	}

	public function getFileMakerTable() {
		return $this->table;
	}

	/** OData uses no session token, so logout is a compatibility no-op. */
	public function logout() {
		return $this->resultEnvelope( 204, array(), '', '' );
	}

	/** Return a sanitized description of the most recent request. */
	public function getLastRequest() {
		return $this->lastRequest;
	}

	/** Retrieve a page of records from the default table. */
	public function getRecords( $parameters = null ) {
		if ( '' === $this->table ) {
			return $this->localError( 'FileMaker table is not set.', -2 );
		}
		$query = $this->buildQueryOptions( is_array( $parameters ) ? $parameters : array() );
		return $this->request( 'GET', $this->tableSegment( $this->table ), $query );
	}

	/** Retrieve one record by the primary key declared in $metadata. */
	public function getRecord( $primaryKey, $parameters = null ) {
		if ( '' === $this->table ) {
			return $this->localError( 'FileMaker table is not set.', -2 );
		}
		$query = $this->buildQueryOptions( is_array( $parameters ) ? $parameters : array() );
		return $this->request( 'GET', $this->tableSegment( $this->table, $primaryKey ), $query );
	}

	/**
	 * Find records using readable structured criteria.
	 *
	 * Example criterion:
	 * array( 'field' => 'Last Name', 'operator' => 'contains', 'value' => 'Cates' )
	 */
	public function findRecords( $parameters ) {
		if ( '' === $this->table ) {
			return $this->localError( 'FileMaker table is not set.', -2 );
		}
		$parameters = is_array( $parameters ) ? $parameters : array();
		$query      = $this->buildQueryOptions( $parameters );
		$criteria   = isset( $parameters['query'] ) && is_array( $parameters['query'] ) ? $parameters['query'] : array();
		if ( isset( $parameters['filter'] ) && is_string( $parameters['filter'] ) ) {
			$query['$filter'] = $parameters['filter'];
		} elseif ( $criteria ) {
			$combine          = isset( $parameters['combine'] ) && 'or' === strtolower( (string) $parameters['combine'] ) ? 'or' : 'and';
			$query['$filter'] = $this->buildFilter( $criteria, $combine );
		}
		return $this->request( 'GET', $this->tableSegment( $this->table ), $query );
	}

	/** Create a record. Accepts a field array or array('fieldData' => ...). */
	public function createRecord( $parameters ) {
		if ( '' === $this->table ) {
			return $this->localError( 'FileMaker table is not set.', -2 );
		}
		$fields = $this->fieldData( $parameters );
		return $this->request( 'POST', $this->tableSegment( $this->table ), array(), $fields, array( 'prefer' => 'return=representation' ) );
	}

	/** Edit selected fields in a record. */
	public function editRecord( $primaryKey, $parameters ) {
		if ( '' === $this->table ) {
			return $this->localError( 'FileMaker table is not set.', -2 );
		}
		$fields = $this->fieldData( $parameters );
		return $this->request( 'PATCH', $this->tableSegment( $this->table, $primaryKey ), array(), $fields, array( 'prefer' => 'return=representation' ) );
	}

	/** Delete one record. */
	public function deleteRecord( $primaryKey ) {
		if ( '' === $this->table ) {
			return $this->localError( 'FileMaker table is not set.', -2 );
		}
		return $this->request( 'DELETE', $this->tableSegment( $this->table, $primaryKey ) );
	}

	/** Get all databases visible to the configured account. */
	public function getDatabaseNames() {
		return $this->request( 'GET', '', array(), null, array( 'includeDatabase' => false ) );
	}

	/** Get the entity sets/tables exposed by the configured database. */
	public function getTableNames() {
		return $this->request( 'GET' );
	}

	/** Compatibility alias: OData entity sets replace Data API layouts. */
	public function getLayoutNames() {
		return $this->getTableNames();
	}

	/** Get the JSON CSDL metadata document. */
	public function getMetadata() {
		return $this->request( 'GET', '$metadata', array(), null, array( 'accept' => 'application/json', 'responseType' => 'json' ) );
	}

	/** Compatibility alias for callers migrating from restCWP. */
	public function getLayoutMetadata() {
		return $this->getMetadata();
	}

	/** Compatibility alias: changes the default OData table, not a layout. */
	public function setFileMakerLayout( $table ) {
		return $this->setFileMakerTable( $table );
	}

	/** Navigate from one record to a related table. */
	public function getRelatedRecords( $primaryKey, $relatedTable, $parameters = null ) {
		if ( '' === $this->table ) {
			return $this->localError( 'FileMaker table is not set.', -2 );
		}
		$path  = $this->tableSegment( $this->table, $primaryKey ) . '/' . $this->nameSegment( $relatedTable );
		$query = $this->buildQueryOptions( is_array( $parameters ) ? $parameters : array() );
		return $this->request( 'GET', $path, $query );
	}

	/** Run a non-interactive FileMaker server-side script. */
	public function runScript( $scriptName, $scriptParameter = null ) {
		// FileMaker Server 21 can decode a path segment before its OData parser
		// sees it. Double-encoding spaces preserves the required %20 for named
		// scripts such as "Create Events" while still calling the script by name.
		$scriptSegment = str_ireplace( '%20', '%2520', $this->nameSegment( $scriptName ) );
		$path = 'Script.' . $scriptSegment;
		$body = null === $scriptParameter ? null : array( 'scriptParameterValue' => $scriptParameter );
		return $this->request( 'POST', $path, array(), $body );
	}

	/** Return the authenticated OData endpoint for a container's raw value. */
	public function getContainerURL( $primaryKey, $fieldName, $repetition = null ) {
		$field = $this->nameSegment( $fieldName );
		if ( null !== $repetition ) {
			$field .= '/' . max( 1, (int) $repetition );
		}
		return $this->buildUrl( $this->tableSegment( $this->table, $primaryKey ) . '/' . $field . '/$value' );
	}

	/** Download raw container bytes through the authenticated server-side request. */
	public function getContainerData( $primaryKey, $fieldName, $repetition = null ) {
		if ( '' === $this->table ) {
			return $this->localError( 'FileMaker table is not set.', -2 );
		}
		$field = $this->nameSegment( $fieldName );
		if ( null !== $repetition ) {
			$field .= '/' . max( 1, (int) $repetition );
		}
		return $this->request( 'GET', $this->tableSegment( $this->table, $primaryKey ) . '/' . $field . '/$value', array(), null, array( 'accept' => '*/*', 'responseType' => 'binary' ) );
	}

	/**
	 * Build a short-lived, signed URL for an application container proxy.
	 *
	 * The endpoint must pass the query parameters to getContainerProxyData().
	 * FileMaker credentials are used only as server-side signing material and
	 * are never included in the generated URL.
	 */
	public function getContainerProxyURL( $endpoint, $primaryKey, $fieldName, $repetition = null, $lifetime = 300 ) {
		$endpoint = trim( (string) $endpoint );
		if ( '' === $endpoint ) {
			throw new \InvalidArgumentException( 'The container proxy endpoint is required.' );
		}
		$lifetime = (int) $lifetime;
		if ( $lifetime < 1 || $lifetime > 86400 ) {
			throw new \InvalidArgumentException( 'Container proxy lifetime must be between 1 and 86400 seconds.' );
		}

		$parameters = array(
			'odatacwp_container' => '1',
			'primaryKey'         => (string) $primaryKey,
			'fieldName'          => (string) $fieldName,
			'repetition'         => null === $repetition ? '' : (string) max( 1, (int) $repetition ),
			'expires'            => (string) ( time() + $lifetime ),
		);
		$parameters['signature'] = $this->containerProxySignature( $parameters );
		$separator = false === strpos( $endpoint, '?' ) ? '?' : '&';
		return $endpoint . $separator . http_build_query( $parameters, '', '&', PHP_QUERY_RFC3986 );
	}

	/** Validate a signed proxy request and download its raw container bytes. */
	public function getContainerProxyData( $parameters ) {
		if ( ! is_array( $parameters ) ) {
			return $this->localError( 'Container proxy parameters must be an array.', -6 );
		}
		$required = array( 'odatacwp_container', 'primaryKey', 'fieldName', 'repetition', 'expires', 'signature' );
		foreach ( $required as $name ) {
			if ( ! array_key_exists( $name, $parameters ) || is_array( $parameters[ $name ] ) ) {
				return $this->localError( 'The container proxy request is incomplete.', -6 );
			}
		}
		$expires = filter_var( $parameters['expires'], FILTER_VALIDATE_INT );
		if ( false === $expires || $expires < time() ) {
			return $this->localError( 'The container proxy URL has expired.', -6 );
		}
		$signed = array(
			'odatacwp_container' => (string) $parameters['odatacwp_container'],
			'primaryKey'         => (string) $parameters['primaryKey'],
			'fieldName'          => (string) $parameters['fieldName'],
			'repetition'         => (string) $parameters['repetition'],
			'expires'            => (string) $parameters['expires'],
		);
		if ( '1' !== $signed['odatacwp_container'] || ! hash_equals( $this->containerProxySignature( $signed ), (string) $parameters['signature'] ) ) {
			return $this->localError( 'The container proxy signature is invalid.', -6 );
		}
		$repetition = '' === $signed['repetition'] ? null : filter_var( $signed['repetition'], FILTER_VALIDATE_INT );
		if ( false === $repetition || ( null !== $repetition && $repetition < 1 ) ) {
			return $this->localError( 'The container proxy repetition is invalid.', -6 );
		}
		return $this->getContainerData( $signed['primaryKey'], $signed['fieldName'], $repetition );
	}

	/** Upload a known server-side file into a container field. */
	public function uploadFileToContainer( $primaryKey, $fieldName, $repetition, $filePath ) {
		if ( ! is_readable( $filePath ) ) {
			return $this->localError( 'The container upload file could not be read.', -3 );
		}
		$binary = file_get_contents( $filePath );
		if ( false === $binary ) {
			return $this->localError( 'The container upload file could not be read.', -3 );
		}
		$type = 'application/octet-stream';
		if ( class_exists( '\\finfo' ) ) {
			$finfo = new \finfo( FILEINFO_MIME_TYPE );
			$detected = $finfo->file( $filePath );
			if ( is_string( $detected ) && '' !== $detected ) {
				$type = $detected;
			}
		}
		return $this->uploadContainerData( $primaryKey, $fieldName, $repetition, $binary, basename( $filePath ), $type );
	}

	/** Upload a PHP $_FILES entry into a container field. */
	public function uploadFormDataToContainer( $primaryKey, $fieldName, $repetition, $file ) {
		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== (int) ( isset( $file['error'] ) ? $file['error'] : UPLOAD_ERR_NO_FILE ) || empty( $file['tmp_name'] ) || ! is_readable( $file['tmp_name'] ) ) {
			return $this->localError( 'The uploaded container file is invalid or unreadable.', -4 );
		}
		$binary = file_get_contents( $file['tmp_name'] );
		if ( false === $binary ) {
			return $this->localError( 'The uploaded container file could not be read.', -4 );
		}
		$name = isset( $file['name'] ) ? basename( (string) $file['name'] ) : 'upload.bin';
		$type = 'application/octet-stream';
		if ( class_exists( '\\finfo' ) ) {
			$finfo = new \finfo( FILEINFO_MIME_TYPE );
			$detected = $finfo->file( $file['tmp_name'] );
			if ( is_string( $detected ) && '' !== $detected ) {
				$type = $detected;
			}
		}
		return $this->uploadContainerData( $primaryKey, $fieldName, $repetition, $binary, $name, $type );
	}

	/** Upload already-read binary data into a container field. */
	public function uploadContainerData( $primaryKey, $fieldName, $repetition, $binary, $fileName, $contentType ) {
		if ( '' === $this->table ) {
			return $this->localError( 'FileMaker table is not set.', -2 );
		}
		$field = $this->nameSegment( $fieldName );
		if ( null !== $repetition ) {
			$field .= '/' . max( 1, (int) $repetition );
		}
		$safeFileName = str_replace( array( '"', "\r", "\n" ), '', basename( (string) $fileName ) );
		$headers = array( 'Content-Disposition' => 'inline; filename="' . $safeFileName . '"' );
		return $this->request(
			'PATCH',
			$this->tableSegment( $this->table, $primaryKey ) . '/' . $field,
			array(),
			$binary,
			array( 'contentType' => (string) $contentType, 'headers' => $headers, 'responseType' => 'text' )
		);
	}

	/**
	 * Advanced escape hatch for raw OData paths and query options.
	 *
	 * @return array Result envelope suitable for isError() and getResponse().
	 */
	public function request( $method, $path = '', $query = array(), $body = null, $options = array() ) {
		$options = $this->resolveRequestOptions( is_array( $options ) ? $options : array() );
		$url     = $this->buildUrl( $path, $query, ! isset( $options['includeDatabase'] ) || (bool) $options['includeDatabase'] );
		$headers = array(
			'Accept'           => isset( $options['accept'] ) ? $options['accept'] : ( $options['ieee754Compatible'] ? 'application/json;IEEE754Compatible=true' : 'application/json' ),
			'Authorization'    => $this->authorizationHeader(),
			'OData-Version'    => '4.0',
			'OData-MaxVersion' => '4.0',
		);
		if ( ! empty( $options['prefer'] ) ) {
			$headers['Prefer'] = $options['prefer'];
		}
		if ( ! empty( $options['headers'] ) && is_array( $options['headers'] ) ) {
			$headers = array_merge( $headers, $options['headers'] );
		}

		$method = strtoupper( (string) $method );
		$requestBody = null;
		if ( null !== $body ) {
			$contentType = isset( $options['contentType'] ) ? $options['contentType'] : 'application/json';
			$headers['Content-Type'] = $contentType;
			$requestBody = 'application/json' === $contentType ? json_encode( $body, JSON_UNESCAPED_SLASHES ) : $body;
			if ( false === $requestBody ) {
				return $this->localError( 'The OData request body could not be encoded as JSON.', -5 );
			}
		}

		$this->lastRequest = array( 'method' => $method, 'url' => $url, 'query' => $query );
		$started = microtime( true );
		if ( $this->deadlineExceeded( $options ) ) {
			$response = $this->transportError( 'deadline_exceeded', 'The OData request deadline has expired.' );
		} elseif ( 'https' !== $this->scheme && ! $this->allowInsecure ) {
			$response = $this->transportError( 'https_required', 'OData requests require HTTPS unless allowInsecure is explicitly enabled.' );
		} else {
			$response = $this->sendHttpRequest( $method, $url, $headers, $requestBody, $options );
			// Also guard custom/subclass transports. They must enforce limits while
			// receiving data themselves; these checks prevent accepting a bad result.
			if ( $this->deadlineExceeded( $options ) ) {
				$response = $this->transportError( 'deadline_exceeded', 'The OData request deadline has expired.' );
			} elseif ( microtime( true ) - $started >= $options['timeout'] ) {
				$response = $this->transportError( 'request_timeout', 'The OData request timeout was exceeded.' );
			} elseif ( ! is_array( $response ) || ( array_key_exists( 'body', $response ) && ! is_string( $response['body'] ) ) ) {
				$response = $this->transportError( 'invalid_http_response', 'The HTTP client returned an invalid response.' );
			} elseif ( null !== $options['maxResponseBytes'] && strlen( $response['body'] ?? '' ) > $options['maxResponseBytes'] ) {
				$response = $this->transportError( 'response_too_large', 'The OData response exceeds maxResponseBytes.' );
			}
		}
		if ( ! empty( $response['error'] ) ) {
			$result = $this->resultEnvelope( 0, array(), (string) $response['error'], $url );
			if ( isset( $response['error_code'] ) ) {
				$result['error_code'] = $response['error_code'];
			}
			$this->writeLog( 'error', __METHOD__, (string) $response['error'], $this->safeLogContext( $method, $url, 0 ) );
			return $result;
		}

		$status       = isset( $response['status'] ) ? (int) $response['status'] : 0;
		$responseBody = isset( $response['body'] ) ? $response['body'] : '';
		$responseType = isset( $options['responseType'] ) ? $options['responseType'] : 'json';
		$data         = $responseBody;
		$decodeError  = false;
		if ( 'json' === $responseType && '' !== $responseBody ) {
			$decoded = $this->decodeResponseJson( $responseBody, $options, $decodeError );
			$data    = ! $decodeError && ( is_array( $decoded ) || $options['strictJson'] || $options['jsonNumbersAsStrings'] ) ? $decoded : $responseBody;
		} elseif ( 'json' === $responseType ) {
			$data = array();
			$decodeError = ( $options['strictJson'] || $options['jsonNumbersAsStrings'] )
				&& ! in_array( $status, array( 204, 205 ), true ) && 'HEAD' !== $method;
		}

		$error = '';
		if ( $status < 200 || $status >= 300 ) {
			$error = is_array( $data ) && isset( $data['error']['message'] )
				? (string) $data['error']['message']
				: sprintf( 'OData request failed with HTTP status %d.', $status );
		}
		if ( $decodeError && ( $options['strictJson'] || $options['jsonNumbersAsStrings'] ) ) {
			$data = array();
			$error = '' === $error ? 'The OData response is not valid JSON.' : $error;
		}
		// Decoding is part of the shared work budget, too.
		if ( $this->deadlineExceeded( $options ) ) {
			$result = $this->resultEnvelope( 0, array(), 'The OData request deadline has expired.', $url );
			$result['error_code'] = 'deadline_exceeded';
			return $result;
		}
		$result = $this->resultEnvelope( $status, $data, $error, $url, isset( $response['headers'] ) ? $response['headers'] : array() );
		if ( $decodeError && ( $options['strictJson'] || $options['jsonNumbersAsStrings'] ) ) {
			$result['error_code'] = 'invalid_json';
		}
		$this->writeLog( '' === $error ? 'success' : 'error', __METHOD__, '' === $error ? 'OData request completed.' : $error, $this->safeLogContext( $method, $url, $status ) );
		return $result;
	}

	/** Validate before rewriting so invalid JSON cannot become valid by quoting numbers. */
	private function decodeResponseJson( $body, array $options, &$invalid ) {
		$flags = $options['ieee754Compatible'] || $options['jsonNumbersAsStrings'] ? JSON_BIGINT_AS_STRING : 0;
		$data = json_decode( $body, true, 512, $flags );
		$invalid = JSON_ERROR_NONE !== json_last_error();
		if ( $invalid || ! $options['jsonNumbersAsStrings'] ) {
			return $data;
		}
		// Skip complete JSON strings (including escaped quotes). Quote numeric
		// lexemes before the final decode so decimals/exponents never round-trip
		// through a float. Booleans, null, and existing strings retain their types.
		$quoted = preg_replace_callback(
			'/"[^"\\\\]*+(?:\\\\.[^"\\\\]*+)*+"(*SKIP)(*F)|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/s',
			static function ( $match ) { return '"' . $match[0] . '"'; },
			$body
		);
		if ( null === $quoted ) {
			$invalid = true;
			return null;
		}
		$data = json_decode( $quoted, true, 512, JSON_BIGINT_AS_STRING );
		$invalid = JSON_ERROR_NONE !== json_last_error();
		return $data;
	}

	private function transportError( $code, $message ) {
		return array( 'status' => 0, 'headers' => array(), 'body' => '', 'error' => $message, 'error_code' => $code );
	}

	/** Perform one HTTP request. Override in tests or inject options['httpClient']. */
	protected function sendHttpRequest( $method, $url, $headers = array(), $body = null, $options = array() ) {
		if ( $this->httpClient ) {
			try {
				$response = call_user_func( $this->httpClient, $method, $url, $headers, $body, $options );
			} catch ( \Throwable $exception ) {
				return array( 'status' => 0, 'headers' => array(), 'body' => '', 'error' => $exception->getMessage() );
			}
			return is_array( $response ) ? $response : array( 'status' => 0, 'headers' => array(), 'body' => '', 'error' => 'The custom HTTP client returned an invalid response.' );
		}
		if ( ! function_exists( 'curl_init' ) ) {
			return array( 'status' => 0, 'headers' => array(), 'body' => '', 'error' => 'The PHP cURL extension is required.' );
		}
		$remaining = $options['timeout'];
		if ( null !== $options['deadline'] ) {
			$remaining = min( $remaining, $options['deadline'] - microtime( true ) );
		}
		if ( $remaining <= 0 ) {
			return $this->transportError( 'deadline_exceeded', 'The OData request deadline has expired.' );
		}

		$handle = curl_init( $url );
		$responseHeaders = array();
		$responseBody = '';
		$responseBytes = 0;
		$tooLarge = false;
		$expired = false;
		$formattedHeaders = array();
		foreach ( $headers as $name => $value ) {
			$formattedHeaders[] = $name . ': ' . $value;
		}
		$curlOptions = array(
			CURLOPT_URL => $url,
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_RETURNTRANSFER => false,
			CURLOPT_HEADER => false,
			CURLOPT_NOBODY => 'HEAD' === $method,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_CONNECTTIMEOUT_MS => max( 1, (int) min( 2147483647, floor( min( $options['connectTimeout'], $remaining ) * 1000 ) ) ),
			CURLOPT_TIMEOUT_MS => max( 1, (int) min( 2147483647, floor( $remaining * 1000 ) ) ),
			CURLOPT_SSL_VERIFYPEER => ! $this->allowInsecure,
			CURLOPT_SSL_VERIFYHOST => $this->allowInsecure ? 0 : 2,
			CURLOPT_HTTPHEADER => $formattedHeaders,
			CURLOPT_WRITEFUNCTION => function ( $curl, $chunk ) use ( &$responseBody, &$responseBytes, &$tooLarge, &$expired, $options ) {
				$length = strlen( $chunk );
				if ( $this->deadlineExceeded( $options ) ) {
					$expired = true;
					return 0;
				}
				if ( null !== $options['maxResponseBytes'] && $length > $options['maxResponseBytes'] - $responseBytes ) {
					$tooLarge = true;
					return 0;
				}
				$responseBody .= $chunk;
				$responseBytes += $length;
				return $length;
			},
			CURLOPT_HEADERFUNCTION => function ( $curl, $line ) use ( &$responseHeaders ) {
				$length = strlen( $line );
				$parts = explode( ':', $line, 2 );
				if ( 2 === count( $parts ) ) {
					$responseHeaders[ strtolower( trim( $parts[0] ) ) ] = trim( $parts[1] );
				}
				return $length;
			},
		);
		if ( defined( 'CURLOPT_PROTOCOLS_STR' ) ) {
			$curlOptions[ CURLOPT_PROTOCOLS_STR ] = $this->allowInsecure ? 'http,https' : 'https';
		} else {
			$curlOptions[ CURLOPT_PROTOCOLS ] = $this->allowInsecure ? CURLPROTO_HTTP | CURLPROTO_HTTPS : CURLPROTO_HTTPS;
		}
		if ( null !== $body ) {
			$curlOptions[ CURLOPT_POSTFIELDS ] = $body;
		}
		try {
			// Apply transport customization first, then reassert protected options.
			// In particular, alternate *_MS / *_STR options must not win by order.
			if ( $this->curlOptions && ! curl_setopt_array( $handle, $this->curlOptions ) ) {
				return $this->transportError( 'invalid_transport_options', 'Could not apply custom cURL options.' );
			}
			if ( ! curl_setopt_array( $handle, $curlOptions ) ) {
				return $this->transportError( 'invalid_transport_options', 'Could not apply protected cURL options.' );
			}
			$ok = curl_exec( $handle );
			if ( $tooLarge ) {
				return $this->transportError( 'response_too_large', 'The OData response exceeds maxResponseBytes.' );
			}
			if ( $expired || $this->deadlineExceeded( $options ) ) {
				return $this->transportError( 'deadline_exceeded', 'The OData request deadline has expired.' );
			}
			if ( false === $ok ) {
				$code = CURLE_OPERATION_TIMEDOUT === curl_errno( $handle ) ? 'request_timeout' : 'connection_failed';
				return $this->transportError( $code, curl_error( $handle ) );
			}
			$status = (int) curl_getinfo( $handle, CURLINFO_HTTP_CODE );
			return array( 'status' => $status, 'headers' => $responseHeaders, 'body' => $responseBody, 'error' => '' );
		} catch ( \Throwable $exception ) {
			return $this->transportError( 'invalid_transport_options', 'Could not execute the OData HTTP request: ' . $exception->getMessage() );
		} finally {
			curl_close( $handle );
		}
	}

	public function isError( $result, $throwException = false ) {
		$status = isset( $result['status']['http_code'] ) ? (int) $result['status']['http_code'] : 0;
		$error  = isset( $result['error'] ) ? (string) $result['error'] : '';
		$isError = 0 === $status || $status < 200 || $status >= 300 || '' !== $error;
		if ( $isError && $throwException ) {
			throw new \Exception( $error ? $error : 'Unknown OData error.', $status );
		}
		return $isError;
	}

	public function getResponse( $result ) {
		return is_array( $result ) && array_key_exists( 'result', $result ) ? $result['result'] : $result;
	}

	/** Return response data as readable text for logs, debugging, or custom views. */
	public function formatResponse( $data ) {
		if ( $this->isResultEnvelope( $data ) ) {
			$data = $this->getResponse( $data );
		}
		if ( is_array( $data ) || is_object( $data ) ) {
			return print_r( $data, true );
		}
		if ( is_string( $data ) ) {
			$trimmed = trim( $data );
			if ( '' !== $trimmed && '<' === $trimmed[0] && class_exists( '\\DOMDocument' ) ) {
				$document = new \DOMDocument( '1.0', 'UTF-8' );
				$document->preserveWhiteSpace = false;
				$document->formatOutput = true;
				$previousLibxmlSetting = libxml_use_internal_errors( true );
				if ( $document->loadXML( $data ) ) {
					$data = $document->saveXML();
				}
				libxml_clear_errors();
				libxml_use_internal_errors( $previousLibxmlSetting );
				return $data;
			}
			if ( '' !== $trimmed && ( '{' === $trimmed[0] || '[' === $trimmed[0] ) ) {
				$decoded = json_decode( $data, true );
				if ( JSON_ERROR_NONE === json_last_error() ) {
					$formatted = json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
					if ( false !== $formatted ) {
						return $formatted;
					}
				}
			}
			return $data;
		}
		return var_export( $data, true );
	}

	/** Display a safe, collapsible, copyable response panel for development use. */
	public function showResponse( $data, $title = 'OData Response' ) {
		static $responseId = 0;
		$responseId++;
		$blockId = 'odatacwp-response-' . $responseId;
		$isEnvelope = $this->isResultEnvelope( $data );
		$isError = $isEnvelope && $this->isError( $data );
		$title = htmlspecialchars( (string) $title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		$output = htmlspecialchars( $this->formatResponse( $data ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		$statusStyle = $isError ? 'border-color:#a33;background:#fff0f0;color:#721c24;' : 'border-color:#087f8c;background:#edf8f9;color:#18505a;';

		if ( $isEnvelope ) {
			echo '<p style="padding:10px 12px;border-left:4px solid;' . $statusStyle . '">' . ( $isError ? 'OData request failed.' : 'OData request succeeded.' ) . '</p>';
		}
		echo '<div class="odatacwp-response" style="margin:20px 0;">';
		echo '<div role="button" tabindex="0" aria-controls="' . $blockId . '" onclick="var b=document.getElementById(\'' . $blockId . '\');b.hidden=!b.hidden;" onkeydown="if(event.key===\'Enter\'||event.key===\' \'){event.preventDefault();this.click();}" style="cursor:pointer;background:#222;color:#fff;padding:8px 110px 8px 14px;border-radius:4px 4px 0 0;font-family:monospace;font-size:15px;font-weight:600;position:relative;">';
		echo $title;
		echo '<button type="button" aria-label="Copy response" onclick="event.stopPropagation();var b=document.getElementById(\'' . $blockId . '\');if(navigator.clipboard){navigator.clipboard.writeText(b.innerText);}else{var t=document.createElement(\'textarea\');t.value=b.innerText;document.body.appendChild(t);t.select();document.execCommand(\'copy\');document.body.removeChild(t);}" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);width:auto;margin:0;padding:3px 8px;font:12px monospace;background:#fff;color:#222;border:0;border-radius:3px;cursor:pointer;">Copy</button>';
		echo '<span aria-hidden="true" style="position:absolute;right:62px;font-size:13px;opacity:.7;">[toggle]</span></div>';
		echo '<pre id="' . $blockId . '" style="background:#111;color:#eee;padding:14px;border-radius:0 0 4px 4px;margin:0;font-family:monospace;font-size:13px;max-height:1000px;overflow:auto;white-space:pre-wrap;">' . $output . '</pre>';
		echo '</div>';
	}

	private function authorizationHeader() {
		return '' !== $this->authorizationHeader
			? $this->authorizationHeader
			: 'Basic ' . base64_encode( $this->user . ':' . $this->password );
	}

	private function fieldData( $parameters ) {
		if ( ! is_array( $parameters ) ) {
			return array();
		}
		return isset( $parameters['fieldData'] ) && is_array( $parameters['fieldData'] ) ? $parameters['fieldData'] : $parameters;
	}

	private function buildQueryOptions( $parameters ) {
		$query = array();
		if ( isset( $parameters['limit'] ) ) {
			$query['$top'] = max( 0, (int) $parameters['limit'] );
		}
		if ( isset( $parameters['offset'] ) ) {
			$query['$skip'] = max( 0, (int) $parameters['offset'] );
		}
		if ( isset( $parameters['count'] ) ) {
			$query['$count'] = $parameters['count'] ? 'true' : 'false';
		}
		if ( ! empty( $parameters['select'] ) ) {
			$fields = is_array( $parameters['select'] ) ? $parameters['select'] : explode( ',', $parameters['select'] );
			$query['$select'] = implode( ',', array_map( array( $this, 'quoteIdentifier' ), $fields ) );
		}
		if ( ! empty( $parameters['expand'] ) ) {
			$tables = is_array( $parameters['expand'] ) ? $parameters['expand'] : explode( ',', $parameters['expand'] );
			$query['$expand'] = implode( ',', array_map( array( $this, 'quoteIdentifier' ), $tables ) );
		}
		if ( ! empty( $parameters['sort'] ) && is_array( $parameters['sort'] ) ) {
			$sorts = array();
			foreach ( $parameters['sort'] as $sort ) {
				if ( ! is_array( $sort ) || empty( $sort['field'] ) ) {
					continue;
				}
				$order   = isset( $sort['order'] ) && in_array( strtolower( $sort['order'] ), array( 'desc', 'descend' ), true ) ? 'desc' : 'asc';
				$sorts[] = $this->quoteIdentifier( $sort['field'] ) . ' ' . $order;
			}
			if ( $sorts ) {
				$query['$orderby'] = implode( ',', $sorts );
			}
		}
		foreach ( $parameters as $key => $value ) {
			if ( is_string( $key ) && 0 === strpos( $key, '$' ) ) {
				$query[ $key ] = $value;
			}
		}
		return $query;
	}

	private function buildFilter( $criteria, $combine ) {
		$expressions = array();
		foreach ( $criteria as $criterion ) {
			if ( ! is_array( $criterion ) || empty( $criterion['field'] ) ) {
				continue;
			}
			$field    = $this->quoteIdentifier( $criterion['field'] );
			$operator = isset( $criterion['operator'] ) ? strtolower( trim( $criterion['operator'] ) ) : 'eq';
			$value    = isset( $criterion['value'] ) ? $criterion['value'] : null;
			$literal  = $this->literal( $value );
			if ( in_array( $operator, array( 'contains', 'startswith', 'endswith' ), true ) ) {
				$expressions[] = sprintf( '%s(%s,%s)', $operator, $field, $literal );
			} elseif ( in_array( $operator, array( 'eq', 'ne', 'gt', 'ge', 'lt', 'le' ), true ) ) {
				$expressions[] = sprintf( '%s %s %s', $field, $operator, $literal );
			}
		}
		return implode( ' ' . $combine . ' ', $expressions );
	}

	private function literal( $value ) {
		if ( null === $value ) {
			return 'null';
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		return "'" . str_replace( "'", "''", (string) $value ) . "'";
	}

	private function quoteIdentifier( $name ) {
		$name = trim( (string) $name );
		// FileMaker's OData parser treats ID as a reserved token even though it
		// is a common FileMaker field name. Quoting preserves the actual field.
		if ( 0 === strcasecmp( $name, 'ID' ) ) {
			return '"ID"';
		}
		if ( preg_match( '/^(?:FMFID|FMTID):\d+$/i', $name ) || preg_match( '/^[A-Za-z][A-Za-z0-9]*$/', $name ) ) {
			return $name;
		}
		return '"' . str_replace( '"', '""', $name ) . '"';
	}

	private function tableSegment( $table, $primaryKey = null ) {
		$segment = $this->nameSegment( $table );
		if ( null !== $primaryKey ) {
			$segment .= '(' . $this->keyLiteral( $primaryKey ) . ')';
		}
		return $segment;
	}

	private function keyLiteral( $value ) {
		if ( is_int( $value ) || is_float( $value ) ) {
			return (string) $value;
		}
		$literal = "'" . str_replace( "'", "''", (string) $value ) . "'";
		return str_ireplace( '%27', "'", rawurlencode( $literal ) );
	}

	private function nameSegment( $name ) {
		return str_ireplace( '%3A', ':', rawurlencode( trim( (string) $name ) ) );
	}

	private function buildUrl( $path = '', $query = array(), $includeDatabase = true ) {
		$url = $this->scheme . '://' . $this->host . '/fmi/odata/v4';
		if ( $includeDatabase ) {
			$url .= '/' . rawurlencode( $this->db );
		}
		if ( '' !== (string) $path ) {
			$url .= '/' . ltrim( (string) $path, '/' );
		}
		if ( $query ) {
			$queryString = http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
			$queryString = str_ireplace(
				array( '%24', '%2C', '%28', '%29', '%3A', '%3D', '%22', '%27', '%2F', '%3B' ),
				array( '$', ',', '(', ')', ':', '=', '"', "'", '/', ';' ),
				$queryString
			);
			$url .= '?' . $queryString;
		}
		return $url;
	}

	private function resultEnvelope( $status, $data, $error, $url, $responseHeaders = array() ) {
		$result = array(
			'status' => array( 'http_code' => (int) $status, 'url' => $url ),
			'result' => $data,
			'error'  => (string) $error,
		);
		if ( isset( $responseHeaders['content-type'] ) ) {
			$result['status']['content_type'] = (string) $responseHeaders['content-type'];
		}
		// Let the host application retain a request-scoped diagnostic trail without
		// coupling this reusable client to the application's logger.
		if ( function_exists( 'fm_meets_capture_odata_response' ) ) {
			fm_meets_capture_odata_response( $result );
		}
		return $result;
	}

	private function localError( $message, $code ) {
		$result = array(
			'status' => array( 'http_code' => 0 ),
			'result' => array( 'error' => array( 'code' => (int) $code, 'message' => (string) $message ) ),
			'error'  => (string) $message,
		);
		if ( function_exists( 'fm_meets_capture_odata_response' ) ) {
			fm_meets_capture_odata_response( $result );
		}
		return $result;
	}

	private function isResultEnvelope( $data ) {
		return is_array( $data ) && array_key_exists( 'status', $data ) && array_key_exists( 'result', $data ) && array_key_exists( 'error', $data );
	}

	private function containerProxySignature( $parameters ) {
		$payload = implode( "\n", array(
			(string) $parameters['odatacwp_container'],
			(string) $parameters['primaryKey'],
			(string) $parameters['fieldName'],
			(string) $parameters['repetition'],
			(string) $parameters['expires'],
		) );
		$key = hash( 'sha256', implode( "\0", array( $this->scheme, $this->host, $this->db, $this->table, $this->user, $this->password, $this->authorizationHeader ) ), true );
		return hash_hmac( 'sha256', $payload, $key );
	}

	private function safeLogContext( $method, $url, $status ) {
		return array( 'method' => (string) $method, 'url' => preg_replace( '/[?&](?:password|token)=[^&]*/i', '', (string) $url ), 'status' => (int) $status );
	}

	private function writeLog( $level, $source, $message, $context = null ) {
		if ( self::LOG_TYPE_NONE === $this->logType || ( self::LOG_TYPE_ERRORS === $this->logType && 'error' !== $level ) ) {
			return;
		}
		try {
			$requestId = random_int( 1000000, 9999999 );
			if ( self::LOG_STORAGE_DATABASE === $this->logStorage ) {
				$this->writeDatabaseLog( $requestId, $level, $source, $message, $context );
				return;
			}
			if ( ! $this->ensureStorageDirectory( $this->logDir ) ) {
				return;
			}
			$line = implode( "\t", array(
				$requestId,
				gmdate( 'Y-m-d H:i:s' ),
				(string) $source,
				strtoupper( (string) $level ),
				null === $context ? '' : self::jsonEncode( $context ),
				(string) $message,
			) ) . "\n";
			$path = rtrim( $this->logDir, '/\\' ) . DIRECTORY_SEPARATOR . 'fm-odata-log_' . gmdate( 'd.m.Y' ) . '.txt';
			if ( false !== file_put_contents( $path, $line, FILE_APPEND | LOCK_EX ) ) {
				@chmod( $path, 0600 );
			}
		} catch ( \Throwable $ignored ) {
			// Logging must never replace the API response with a storage error.
		}
	}

	private function writeDatabaseLog( $requestId, $level, $source, $message, $context ) {
		$tables = self::sqlTableNames( $this->sqlConnection );
		$payload = null;
		if ( null !== $context && array() !== $context && '' !== $context ) {
			$payload = self::encryptSqlValue( self::jsonEncode( $context ), $this->sqlConnection['encryptionKey'] );
		}
		$statement = $this->getSqlPdo()->prepare(
			'INSERT INTO ' . $tables['logs'] . ' (created_at, request_id, level, source, message, context)'
			. ' VALUES (:created_at, :request_id, :level, :source, :message, :context)'
		);
		$statement->execute( array(
			'created_at' => gmdate( 'Y-m-d H:i:s' ), 'request_id' => $requestId,
			'level' => (string) $level, 'source' => (string) $source,
			'message' => (string) $message, 'context' => $payload,
		) );
	}

	private function getSqlPdo() {
		if ( ! $this->sqlPdo instanceof \PDO ) {
			$this->sqlPdo = self::createSqlPdo( $this->sqlConnection );
			self::createSqlTables( $this->sqlPdo, $this->sqlConnection );
		}
		return $this->sqlPdo;
	}

	private function defaultStoragePath( $type ) {
		if ( defined( 'ODATA_CWP_STORAGE_PATH' ) ) {
			return rtrim( ODATA_CWP_STORAGE_PATH, '/\\' ) . DIRECTORY_SEPARATOR . $type;
		}
		return __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . $type;
	}

	private function ensureStorageDirectory( $path ) {
		if ( ! is_string( $path ) || '' === $path ) {
			return false;
		}
		if ( ! is_dir( $path ) && ! @mkdir( $path, 0775, true ) && ! is_dir( $path ) ) {
			return false;
		}
		self::writeStorageProtectionFiles( $path );
		return is_writable( $path );
	}

	private static function writeStorageProtectionFiles( $path ) {
		$files = array(
			'.htaccess' => "# Generated by ODataCWP. Do not serve files from this directory.\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n    <security>\n      <authorization>\n        <remove users=\"*\" roles=\"\" verbs=\"\" />\n        <add accessType=\"Deny\" users=\"*\" />\n      </authorization>\n    </security>\n  </system.webServer>\n</configuration>\n",
		);
		foreach ( $files as $name => $contents ) {
			$file = rtrim( $path, '/\\' ) . DIRECTORY_SEPARATOR . $name;
			if ( ! file_exists( $file ) && false !== @file_put_contents( $file, $contents, LOCK_EX ) ) {
				@chmod( $file, 0644 );
			}
		}
	}

	private static function normalizeSqlConnection( array $connection ) {
		$driver = strtolower( trim( (string) ( isset( $connection['driver'] ) ? $connection['driver'] : '' ) ) );
		$driver = 'mariadb' === $driver ? 'mysql' : $driver;
		if ( ! in_array( $driver, array( 'mysql', 'sqlite' ), true ) ) {
			throw new \InvalidArgumentException( 'SQL driver must be mysql, mariadb, or sqlite.' );
		}
		$prefix = trim( (string) ( isset( $connection['tablePrefix'] ) ? $connection['tablePrefix'] : 'odatacwp_' ) );
		if ( ! preg_match( '/^[A-Za-z][A-Za-z0-9_]{0,31}$/', $prefix ) ) {
			throw new \InvalidArgumentException( 'SQL tablePrefix must begin with a letter and contain only letters, numbers, and underscores.' );
		}
		$key = trim( (string) ( isset( $connection['encryptionKey'] ) ? $connection['encryptionKey'] : '' ) );
		if ( strlen( $key ) < 16 ) {
			throw new \InvalidArgumentException( 'SQL encryptionKey must contain at least 16 characters.' );
		}
		$normalized = array(
			'driver' => $driver, 'host' => trim( (string) ( isset( $connection['host'] ) ? $connection['host'] : 'localhost' ) ),
			'port' => (int) ( isset( $connection['port'] ) ? $connection['port'] : 3306 ),
			'database' => trim( (string) ( isset( $connection['database'] ) ? $connection['database'] : '' ) ),
			'username' => (string) ( isset( $connection['username'] ) ? $connection['username'] : '' ),
			'password' => (string) ( isset( $connection['password'] ) ? $connection['password'] : '' ),
			'sqlitePath' => trim( (string) ( isset( $connection['sqlitePath'] ) ? $connection['sqlitePath'] : '' ) ),
			'tablePrefix' => $prefix, 'encryptionKey' => $key,
		);
		if ( 'mysql' === $driver && ( '' === $normalized['host'] || '' === $normalized['database'] || '' === $normalized['username'] ) ) {
			throw new \InvalidArgumentException( 'MySQL storage requires host, database, and username.' );
		}
		if ( 'mysql' === $driver && ( $normalized['port'] < 1 || $normalized['port'] > 65535 ) ) {
			throw new \InvalidArgumentException( 'MySQL port must be between 1 and 65535.' );
		}
		if ( 'sqlite' === $driver && '' === $normalized['sqlitePath'] ) {
			throw new \InvalidArgumentException( 'SQLite storage requires sqlitePath.' );
		}
		return $normalized;
	}

	private static function sqlConnectionFromConfig( array $config ) {
		$options = isset( $config['options'] ) && is_array( $config['options'] ) ? $config['options'] : array();
		if ( ! isset( $options['sqlConnection'] ) || ! is_array( $options['sqlConnection'] ) ) {
			throw new \RuntimeException( 'SQL-backed configuration requires options.sqlConnection in config.php.' );
		}
		return self::normalizeSqlConnection( $options['sqlConnection'] );
	}

	private static function normalizeConfigProfile( $profile ) {
		$profile = trim( (string) $profile );
		if ( '' === $profile || ! preg_match( '/^[A-Za-z0-9_.-]{1,64}$/', $profile ) ) {
			throw new \InvalidArgumentException( 'Configuration profile must use only letters, numbers, dots, dashes, and underscores.' );
		}
		return $profile;
	}

	private static function createSqlPdo( array $connection ) {
		if ( ! class_exists( '\\PDO' ) ) {
			throw new \RuntimeException( 'The PHP PDO extension is required for SQL storage.' );
		}
		$connection = self::normalizeSqlConnection( $connection );
		if ( ! in_array( $connection['driver'], \PDO::getAvailableDrivers(), true ) ) {
			throw new \RuntimeException( 'The PDO ' . $connection['driver'] . ' driver is not installed.' );
		}
		try {
			if ( 'sqlite' === $connection['driver'] ) {
				$path = $connection['sqlitePath'];
				if ( ':memory:' !== $path ) {
					$directory = dirname( $path );
					if ( ! is_dir( $directory ) && ! @mkdir( $directory, 0775, true ) && ! is_dir( $directory ) ) {
						throw new \RuntimeException( 'Unable to create the SQLite storage directory.' );
					}
					self::writeStorageProtectionFiles( $directory );
				}
				$pdo = new \PDO( 'sqlite:' . $path );
				if ( ':memory:' !== $path && is_file( $path ) ) { @chmod( $path, 0600 ); }
			} else {
				$dsn = 'mysql:host=' . $connection['host'] . ';port=' . $connection['port'] . ';dbname=' . $connection['database'] . ';charset=utf8mb4';
				$pdo = new \PDO( $dsn, $connection['username'], $connection['password'] );
			}
			$pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION );
			$pdo->setAttribute( \PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC );
			$pdo->setAttribute( \PDO::ATTR_EMULATE_PREPARES, false );
			return $pdo;
		} catch ( \PDOException $exception ) {
			throw new \RuntimeException( 'Unable to connect to SQL storage: ' . $exception->getMessage(), 0, $exception );
		}
	}

	private static function sqlTableNames( array $connection ) {
		$prefix = self::normalizeSqlConnection( $connection )['tablePrefix'];
		return array( 'config' => $prefix . 'config', 'logs' => $prefix . 'logs' );
	}

	private static function createSqlTables( \PDO $pdo, array $connection ) {
		$connection = self::normalizeSqlConnection( $connection );
		$tables = self::sqlTableNames( $connection );
		if ( 'mysql' === $connection['driver'] ) {
			$pdo->exec( 'CREATE TABLE IF NOT EXISTS ' . $tables['config'] . ' (profile VARCHAR(64) PRIMARY KEY, payload LONGTEXT NOT NULL, updated_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' );
			$pdo->exec( 'CREATE TABLE IF NOT EXISTS ' . $tables['logs'] . ' (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, created_at DATETIME NOT NULL, request_id BIGINT NULL, level VARCHAR(20) NOT NULL, source VARCHAR(255) NOT NULL, message TEXT NOT NULL, context LONGTEXT NULL, INDEX created_at_index (created_at), INDEX level_index (level)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' );
		} else {
			$pdo->exec( 'CREATE TABLE IF NOT EXISTS ' . $tables['config'] . ' (profile TEXT PRIMARY KEY, payload TEXT NOT NULL, updated_at TEXT NOT NULL)' );
			$pdo->exec( 'CREATE TABLE IF NOT EXISTS ' . $tables['logs'] . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, created_at TEXT NOT NULL, request_id INTEGER NULL, level TEXT NOT NULL, source TEXT NOT NULL, message TEXT NOT NULL, context TEXT NULL)' );
			$pdo->exec( 'CREATE INDEX IF NOT EXISTS ' . $tables['logs'] . '_created_at_index ON ' . $tables['logs'] . ' (created_at)' );
			$pdo->exec( 'CREATE INDEX IF NOT EXISTS ' . $tables['logs'] . '_level_index ON ' . $tables['logs'] . ' (level)' );
		}
	}

	private static function upsertSqlConfig( \PDO $pdo, array $connection, $profile, $payload ) {
		$table = self::sqlTableNames( $connection )['config'];
		$values = array( 'profile' => $profile, 'payload' => $payload, 'updated_at' => gmdate( 'Y-m-d H:i:s' ) );
		$sql = 'mysql' === $connection['driver']
			? 'INSERT INTO ' . $table . ' (profile, payload, updated_at) VALUES (:profile, :payload, :updated_at) ON DUPLICATE KEY UPDATE payload = VALUES(payload), updated_at = VALUES(updated_at)'
			: 'INSERT INTO ' . $table . ' (profile, payload, updated_at) VALUES (:profile, :payload, :updated_at) ON CONFLICT(profile) DO UPDATE SET payload = excluded.payload, updated_at = excluded.updated_at';
		$statement = $pdo->prepare( $sql );
		$statement->execute( $values );
	}

	private static function encryptSqlValue( $plaintext, $encryptionKey ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) { throw new \RuntimeException( 'The PHP OpenSSL extension is required for encrypted SQL storage.' ); }
		$key = hash( 'sha256', (string) $encryptionKey, true );
		$iv = random_bytes( 12 );
		$tag = '';
		$ciphertext = openssl_encrypt( (string) $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'odatacwp-sql-v1' );
		if ( false === $ciphertext ) { throw new \RuntimeException( 'Unable to encrypt SQL storage data.' ); }
		return self::jsonEncode( array( 'version' => 1, 'iv' => base64_encode( $iv ), 'tag' => base64_encode( $tag ), 'data' => base64_encode( $ciphertext ) ) );
	}

	private static function decryptSqlValue( $payload, $encryptionKey ) {
		$envelope = self::jsonDecode( $payload );
		if ( ! is_array( $envelope ) || 1 !== (int) ( isset( $envelope['version'] ) ? $envelope['version'] : 0 ) ) { throw new \RuntimeException( 'SQL storage contains an unsupported encrypted value.' ); }
		$iv = base64_decode( (string) ( isset( $envelope['iv'] ) ? $envelope['iv'] : '' ), true );
		$tag = base64_decode( (string) ( isset( $envelope['tag'] ) ? $envelope['tag'] : '' ), true );
		$data = base64_decode( (string) ( isset( $envelope['data'] ) ? $envelope['data'] : '' ), true );
		if ( false === $iv || false === $tag || false === $data ) { throw new \RuntimeException( 'SQL storage contains invalid encrypted data.' ); }
		$plaintext = openssl_decrypt( $data, 'aes-256-gcm', hash( 'sha256', (string) $encryptionKey, true ), OPENSSL_RAW_DATA, $iv, $tag, 'odatacwp-sql-v1' );
		if ( false === $plaintext ) { throw new \RuntimeException( 'Unable to decrypt SQL storage data. Check the encryption key.' ); }
		return $plaintext;
	}

	private static function jsonEncode( $data ) {
		$json = json_encode( $data, JSON_UNESCAPED_SLASHES );
		if ( false === $json ) { throw new \RuntimeException( 'JSON encode failed: ' . json_last_error_msg() ); }
		return $json;
	}

	private static function jsonDecode( $json ) {
		$data = json_decode( $json, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) { throw new \RuntimeException( 'JSON decode failed: ' . json_last_error_msg() ); }
		return $data;
	}
}

if ( ! class_exists( 'ODataCWP', false ) ) {
	class_alias( __NAMESPACE__ . '\\ODataCWP', 'ODataCWP' );
}
