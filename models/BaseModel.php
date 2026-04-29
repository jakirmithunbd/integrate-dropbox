<?php

namespace CodeConfig\IDB\Models;

defined('ABSPATH') || exit('No direct script access allowed');

// phpcs:disable WordPress.DB.DirectDatabaseQuery

use Exception;

use function is_array;
use function is_int;

use WP_Error;

/**
 * Abstract Database Model for Integration Dropbox Plugin
 *
 * Provides a robust foundation for database operations with WordPress
 * including CRUD operations, validation, error handling, and query building.
 *
 * Features:
 * - Type-safe CRUD operations with comprehensive error handling
 * - Flexible query building and condition management
 * - Built-in pagination and sorting utilities
 * - Data validation and sanitization
 * - Transaction support and batch operations
 * - Security measures against cloning and serialization
 * - Consistent error reporting with WP_Error integration
 *
 * @package CodeConfig\IDB\Models
 * @since 1.0.0
 * @author CodeConfig Team
 */
abstract class BaseModel
{
    /**
     * WordPress database connection instance
     * @var \wpdb
     */
    protected $database;

    /**
     * Full table name with WordPress prefix
     * @var string
     */
    protected $tableName;

    /**
     * Default maximum records per page for pagination
     */
    public const DEFAULT_ITEMS_PER_PAGE = 20;

    /**
     * Maximum allowed items per page to prevent memory issues
     */
    public const MAX_ITEMS_PER_PAGE = 1000;

    /**
     * Supported database output formats
     */
    public const OUTPUT_FORMATS = [
        'OBJECT'            => OBJECT,
        'ARRAY_ASSOCIATIVE' => ARRAY_A,
        'ARRAY_NUMERIC'     => ARRAY_N,
        'BOOLEAN'           => 'bool'
    ];

    /**
     * Database error codes
     */
    public const ERROR_CODES = [
        'INVALID_DATA'      => 'invalid_data',
        'DATABASE_ERROR'    => 'database_error',
        'NOT_FOUND'         => 'not_found',
        'VALIDATION_FAILED' => 'validation_failed',
        'OPERATION_FAILED'  => 'operation_failed'
    ];

    /**
     * Initialize the database model with table configuration
     *
     * @param string $tableSuffix Table suffix to append to WordPress prefix
     * @throws Exception If database connection fails
     */
    public function __construct($tableSuffix)
    {
        global $wpdb;

        if (!$wpdb instanceof \wpdb) {
            throw new Exception('WordPress database connection not available.');
        }

        $this->database  = $wpdb;
        $this->tableName = $this->buildTableName($tableSuffix);
    }

    /**
     * Build full table name with WordPress prefix
     *
     * @param string $tableSuffix Table suffix
     * @return string Full table name
     */
    private function buildTableName($tableSuffix)
    {
        return $this->database->prefix . sanitize_key($tableSuffix);
    }

    // ========================================
    // ERROR HANDLING METHODS
    // ========================================

    /**
     * Create a standardized database error
     *
     * @param string $errorMessage Database error message
     * @return WP_Error
     */
    protected function createDatabaseError($errorMessage)
    {
        return new WP_Error(
            self::ERROR_CODES['DATABASE_ERROR'],
            /* translators: %s: Database error message */
            sprintf(__('Database error: %s', 'integrate-dropbox'), $errorMessage)
        );
    }

    /**
     * Create a standardized validation error
     *
     * @param string $message Error message
     * @return WP_Error
     */
    protected function createValidationError($message)
    {
        return new WP_Error(
            self::ERROR_CODES['VALIDATION_FAILED'],
            $message
        );
    }

    /**
     * Create a standardized operation error
     *
     * @param string $message Error message
     * @return WP_Error
     */
    protected function createOperationError($message)
    {
        return new WP_Error(
            self::ERROR_CODES['OPERATION_FAILED'],
            $message
        );
    }

    /**
     * Create a standardized not found error
     *
     * @param string $resource Resource name that was not found
     * @return WP_Error
     */
    protected function createNotFoundError($resource = 'Record')
    {
        return new WP_Error(
            self::ERROR_CODES['NOT_FOUND'],
            /* translators: %s: Resource name */
            sprintf(__('%s not found.', 'integrate-dropbox'), $resource)
        );
    }

    /**
     * Validate output format
     *
     * @param mixed $output Output format to validate
     * @param mixed $default Default format if invalid
     * @return mixed Valid output format
     */
    protected function validateOutputFormat($output, $default = OBJECT)
    {
        $validFormats = array_values(self::OUTPUT_FORMATS);

        return in_array($output, $validFormats, true) ? $output : $default;
    }

    /**
     * Disallow cloning of this class
     *
     * @throws Exception
     */
    public function __clone()
    {
        throw new Exception('Clone is not allowed.');
    }

    /**
     * Disallow serialization of this class
     *
     * @throws Exception
     */
    public function __sleep()
    {
        throw new Exception('Serialization is forbidden.');
    }

    /**
     * Disallow deserialization of this class
     *
     * @throws Exception
     */
    public function __wakeup()
    {
        throw new Exception('Deserialization is forbidden.');
    }

    // ========================================
    // CORE CRUD OPERATIONS
    // ========================================

    public function countRecords($config = [])
    {

        $availableKeys = [
            'accounts'    => ['userId', 'lost', 'emailVerified', 'disabled', 'country', 'location', 'local', 'type', 'isPaired', 'isTeam'],
            'shortcodes'  => ['type', 'status', 'title'],
            'files'       => ['parent', 'accountId', 'mimeType', 'extension', 'isDir'],
            'logs'        => ['moduleId', 'userId', 'fileKey', 'fileName', 'page', 'type', 'title', 'status'],
            'user_access' => ['type'],
        ];

        global $wpdb;
        $sql = $wpdb->prepare("SELECT COUNT(*) FROM %i WHERE 1=1", $this->tableName);

        $tablePrefix = $wpdb->prefix . 'ccpidb_';
        $tableName   = str_replace($tablePrefix, '', $this->tableName);

        if ($availableKeys[$tableName] ?? false) {
            $validKeys = array_intersect_key($config, array_flip($availableKeys[$tableName]));
            foreach ($validKeys as $key => $value) {
                if ($value !== 'all') {
                    if (is_array($value) && !empty($value)) {
                        $placeholders = implode(', ', array_fill(0, count($value), '%s'));
                        $sql .= $wpdb->prepare(" AND %i IN ($placeholders)", array_merge([$key], $value));
                    } elseif (!empty($value)) {
                        $sql .= $wpdb->prepare(" AND %i = %s", $key, $value);
                    }
                }
            }
        }

        $search = $config['search'] ?? '';

        if (!empty($search)) {
            $sql .= $wpdb->prepare(" AND title LIKE %s", "%$search%");
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $result = $wpdb->get_var($sql);
        if (is_wp_error($result)) {
            return 0;
        }

        return $result;
    }

    // /**
    //  * Count total records in the table with optional conditions
    //  *
    //  * @param string $whereCondition Optional WHERE conditions (without WHERE keyword)
    //  * @param array $conditionData Values for prepared statement placeholders
    //  * @return int|WP_Error Number of records or error
    //  */
    // public function countRecords($whereCondition = '', $conditionData = [])
    // {
    //     if (!empty($whereCondition) && empty($conditionData)) {
    //         return $this->createValidationError('Condition data cannot be empty when using WHERE conditions.');
    //     }

    //     $sql    = $this->database->prepare("SELECT COUNT(*) FROM %i", $this->tableName);

    //     if (!empty($whereCondition)) {
    //         $sql .= $this->database->prepare(" WHERE %i = %s", $whereCondition, $conditionData);
    //     }

    //     // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
    //     $result = $this->database->get_var($sql);
    //     if ($this->database->last_error) {
    //         return $this->createDatabaseError($this->database->last_error);
    //     }

    //     return (int) $result;
    // }

    /**
     * Create a new record in the database
     *
     * @param array $recordData Associative array of column => value pairs
     * @param array $dataFormats Array of format strings (%s, %d, %f) for each value
     * @param string $returnFormat Return format: 'bool', ARRAY_A, ARRAY_N, or OBJECT
     * @return bool|array|object|WP_Error Success status, inserted record, or error
     */
    protected function createRecord(array $recordData, array $dataFormats, $returnFormat = 'bool')
    {
        if (empty($recordData)) {
            return $this->createValidationError('Record data cannot be empty for insert operation.');
        }

        $returnFormat = $this->validateOutputFormat($returnFormat, 'bool');

        $inserted = $this->database->insert($this->tableName, $recordData, $dataFormats);

        if ($this->database->last_error) {
            return $this->createDatabaseError($this->database->last_error);
        }

        if (!$inserted) {
            return $this->createOperationError('Failed to insert record into database.');
        }

        if ($returnFormat === 'bool') {
            return true;
        }

        $insertedId = $this->database->insert_id;
        if (is_int($insertedId) && $insertedId > 0) {
            return $this->findSingleRecord("SELECT * FROM {$this->tableName} WHERE id = %d", [$insertedId], $returnFormat);
        }

        return $inserted;
    }

    /**
     * Update existing records in the database
     *
     * @param array $updateData Associative array of column => value pairs to update
     * @param array $whereConditions Array of where conditions for the update
     * @param array $dataFormats Array of format strings for update data
     * @param array $whereFormats Array of format strings for where conditions
     * @param string $returnFormat Return format: 'bool', ARRAY_A, ARRAY_N, or OBJECT
     * @return bool|array|object|WP_Error Success status, updated record, or error
     */
    protected function updateRecords(array $updateData, array $whereConditions, array $dataFormats, array $whereFormats, $returnFormat = 'bool')
    {
        if (empty($updateData)) {
            return $this->createValidationError('Update data cannot be empty for update operation.');
        }

        if (empty($whereConditions)) {
            return $this->createValidationError('Where conditions cannot be empty for update operation.');
        }

        $returnFormat = $this->validateOutputFormat($returnFormat, 'bool');

        $updated = $this->database->update($this->tableName, $updateData, $whereConditions, $dataFormats, $whereFormats);

        if ($this->database->last_error) {
            return $this->createDatabaseError($this->database->last_error);
        }

        if ($updated === false) {
            return $this->createOperationError('Failed to update records in database.');
        }

        if ($returnFormat === 'bool') {
            return $updated > 0;
        }

        if ($updated > 0 && isset($whereConditions['id'])) {
            return $this->findSingleRecord("SELECT * FROM %i WHERE id = %s", [$this->tableName, $whereConditions['id']], $returnFormat);
        }

        return $updated;
    }

    /**
     * Delete records from the database with flexible condition support
     *
     * Supports both associative arrays for simple conditions and raw SQL for complex conditions.
     *
     * @param array|string $whereConditions Delete conditions: associative array or raw SQL string
     * @param array $conditionFormats Format strings for where conditions
     * @param bool $allowDeleteAll Allow deletion of all records (dangerous operation)
     * @param array $conditionValues Values for raw SQL placeholders
     * @return int|WP_Error Number of deleted records or error
     */
    protected function deleteRecords($whereConditions = [], $conditionFormats = [], $allowDeleteAll = false, $conditionValues = [])
    {
        if (is_array($whereConditions) && !empty($whereConditions)) {
            $result = $this->database->delete($this->tableName, $whereConditions, $conditionFormats);
        } elseif (is_string($whereConditions) && !empty($whereConditions)) {
            $sql = "DELETE FROM {$this->tableName} WHERE {$whereConditions}";
            if (!empty($conditionValues)) {
                // Ensure numeric array for spread operator to prevent "Cannot unpack array with string keys" error
                $conditionValues = array_values($conditionValues);
                $prepared        = $this->database->prepare($sql, ...$conditionValues);
                $result          = $this->database->query($prepared);
            } else {
                $result = $this->database->query($sql);
            }
        } else {
            if (!$allowDeleteAll) {
                return new WP_Error(
                    'no_where_clause',
                    __('Delete operation blocked: WHERE clause is required for safety.', 'integrate-dropbox')
                );
            }
            $result = $this->database->query("DELETE FROM {$this->tableName}");
        }

        if ($this->database->last_error) {
            return $this->createDatabaseError($this->database->last_error);
        }

        if ($result === false) {
            return $this->createOperationError('Failed to delete records from database.');
        }

        return (int) $result;
    }

    /**
     * Find multiple records using custom SQL query
     *
     * @param string $sqlQuery The SQL query to execute
     * @param array $queryParameters Array of values for prepared statement placeholders
     * @param string $returnFormat Output format: OBJECT, ARRAY_A, or ARRAY_N
     * @return array|WP_Error Array of records or error
     */
    protected function findMultipleRecords($sqlQuery, array $queryParameters = [], $returnFormat = OBJECT)
    {
        $returnFormat = $this->validateOutputFormat($returnFormat, OBJECT);

        if (empty($sqlQuery)) {
            return $this->createValidationError('SQL query cannot be empty.');
        }

        if (!empty($queryParameters)) {
            // Ensure numeric array for spread operator to prevent "Cannot unpack array with string keys" error
            $queryParameters = array_values($queryParameters);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $result = $this->database->get_results($this->database->prepare($sqlQuery, ...$queryParameters), $returnFormat);
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $result = $this->database->get_results($sqlQuery, $returnFormat);
        }

        if ($this->database->last_error) {
            return $this->createDatabaseError($this->database->last_error);
        }

        return is_array($result) ? $result : [];
    }

    /**
     * Find a single record using custom SQL query
     *
     * @param string $sqlQuery The SQL query to execute
     * @param array $queryParameters Array of values for prepared statement placeholders
     * @param string $returnFormat Output format: OBJECT, ARRAY_A, or ARRAY_N
     * @return object|array|null|WP_Error Single record, null if not found, or error
     */
    protected function findSingleRecord($sqlQuery, array $queryParameters = [], $returnFormat = ARRAY_A)
    {
        $returnFormat = $this->validateOutputFormat($returnFormat, OBJECT);

        if (empty($sqlQuery)) {
            return $this->createValidationError('SQL query cannot be empty.');
        }

        // Only use prepare() if there are arguments to bind
        if (!empty($queryParameters)) {
            // Ensure numeric array for spread operator to prevent "Cannot unpack array with string keys" error
            $queryParameters = array_values($queryParameters);
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $result = $this->database->get_row($this->database->prepare($sqlQuery, ...$queryParameters), $returnFormat);
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $result = $this->database->get_row($sqlQuery, $returnFormat);
        }

        if ($this->database->last_error) {
            return $this->createDatabaseError($this->database->last_error);
        }

        return $result;
    }

    /**
     * Check if an account exists and is valid (not lost)
     *
     * @param string $id The account ID to validate
     * @return bool True if account is valid, false otherwise
     */
    protected function isValidAccount($id)
    {
        if (empty($id)) {
            return false;
        }

        $result = $this->findSingleRecord(
            "SELECT `lost` FROM {$this->database->prefix}integrate_dropbox_accounts WHERE `id` = %s AND `lost` = 0 LIMIT 1",
            [$id]
        );

        if (is_wp_error($result)) {
            return false;
        }

        return !empty($result);
    }

    /**
     * Get the table name for this model
     *
     * @return string The full table name including prefix
     */
    public function getTableName()
    {
        return $this->tableName;
    }

    // ========================================
    // UTILITY AND VALIDATION METHODS
    // ========================================

    /**
     * Check if records exist based on given conditions
     *
     * @param array $whereConditions Associative array of column => value conditions
     * @param array $conditionFormats Array of format strings for conditions
     * @return bool|WP_Error True if records exist, false if not, error on database failure
     */
    protected function recordExists(array $whereConditions, array $conditionFormats = [])
    {
        if (empty($whereConditions)) {
            return $this->createValidationError('Where conditions cannot be empty for existence check.');
        }

        $whereClause  = [];
        $placeholders = [];

        foreach ($whereConditions as $column => $value) {
            $whereClause[]  = "`{$column}` = {$conditionFormats[$column]}";
            $placeholders[] = $value;
        }

        $sql = "SELECT 1 FROM {$this->tableName} WHERE " . implode(' AND ', $whereClause) . " LIMIT 1";

        // Ensure numeric array for spread operator to prevent "Cannot unpack array with string keys" error
        $placeholders = array_values($placeholders);
        $result       = $this->database->get_var($this->database->prepare($sql, ...$placeholders));

        if ($this->database->last_error) {
            return $this->createDatabaseError($this->database->last_error);
        }

        return !is_null($result);
    }

    /**
     * Get a single column value from the first matching record
     *
     * @param string $columnName The column name to retrieve
     * @param array $whereConditions Array of where conditions
     * @param array $conditionFormats Array of format strings for conditions
     * @return mixed|WP_Error The column value or error
     */
    protected function getColumnValue($columnName, array $whereConditions, array $conditionFormats = [])
    {
        if (empty($columnName)) {
            return $this->createValidationError('Column name cannot be empty.');
        }

        if (empty($whereConditions) || empty($conditionFormats)) {
            return $this->createValidationError('Where conditions cannot be empty.');
        }

        $whereClause  = [];
        $placeholders = [];

        foreach ($whereConditions as $column => $value) {
            $whereClause[]  = "`{$column}` = $conditionFormats[$column]";
            $placeholders[] = $value;
        }

        $sql = "SELECT `{$columnName}` FROM {$this->tableName} WHERE " . implode(' AND ', $whereClause) . " LIMIT 1";

        // Ensure numeric array for spread operator to prevent "Cannot unpack array with string keys" error
        $placeholders = array_values($placeholders);
        $result       = $this->database->get_var($this->database->prepare($sql, ...$placeholders));

        if ($this->database->last_error) {
            return $this->createDatabaseError($this->database->last_error);
        }

        return $result;
    }

    /**
     * Sanitize and validate order direction
     *
     * @param string $order The order direction (ASC or DESC)
     * @return string Valid order direction
     */
    protected function sanitizeOrder($order)
    {
        $order = strtoupper(trim($order));

        return in_array($order, ['ASC', 'DESC'], true) ? $order : 'DESC';
    }

    /**
     * Sanitize and validate order by column
     *
     * @param string $orderBy The column to order by
     * @param array $allowedColumns Array of allowed column names
     * @return string Valid column name or default
     */
    protected function sanitizeOrderBy($orderBy, array $allowedColumns)
    {
        $orderBy = trim($orderBy);

        return in_array($orderBy, $allowedColumns, true) ? $orderBy : ($allowedColumns[0] ?? 'id');
    }

    /**
     * Sanitize pagination parameters
     *
     * @param int $page The page number
     * @param int $perPage Items per page
     * @return array Sanitized pagination parameters
     */
    protected function sanitizePagination($page, $perPage)
    {
        $page    = max(1, (int) $page);
        $perPage = max(0, min(self::MAX_ITEMS_PER_PAGE, (int) $perPage));
        $offset  = ($page - 1) * $perPage;

        return [
            'page'    => $page,
            'perPage' => $perPage,
            'offset'  => $offset
        ];
    }

    // ========================================
    // BATCH OPERATIONS
    // ========================================

    /**
     * Insert multiple records in a batch operation
     *
     * @param array $recordsData Array of record arrays, each containing data for one record
     * @param array $dataFormats Array of format strings for the data
     * @return array|WP_Error Array with success/failure counts or error
     */
    protected function batchCreateRecords(array $recordsData, array $dataFormats)
    {
        if (empty($recordsData)) {
            return $this->createValidationError('Records data cannot be empty for batch insert operation.');
        }

        $successCount = 0;
        $failureCount = 0;
        $totalCount   = count($recordsData);
        $errors       = [];

        foreach ($recordsData as $index => $record) {
            if (!is_array($record)) {
                $failureCount++;
                $errors[] = "Record at index {$index} is not an array.";
                continue;
            }

            $result = $this->createRecord($record, $dataFormats);
            if (!is_wp_error($result) && $result) {
                $successCount++;
            } else {
                $failureCount++;
                $errors[] = is_wp_error($result) ? $result->get_error_message() : "Failed to insert record at index {$index}";
            }
        }

        return [
            'total'        => $totalCount,
            'success'      => $successCount,
            'failed'       => $failureCount,
            'errors'       => $errors,
            'success_rate' => $totalCount > 0 ? round(($successCount / $totalCount) * 100, 2) : 0
        ];
    }

    // ========================================
    // ADVANCED QUERY BUILDING METHODS
    // ========================================

    /**
     * Build a flexible WHERE clause from conditions array
     *
     * @param array $conditions Associative array of conditions
     * @param string $operator Logical operator (AND/OR)
     * @return array ['clause' => string, 'values' => array]
     */
    protected function buildWhereClause(array $conditions, $operator = 'AND')
    {
        if (empty($conditions)) {
            return ['clause' => '', 'values' => []];
        }

        $operator = strtoupper($operator);
        if (!in_array($operator, ['AND', 'OR'], true)) {
            $operator = 'AND';
        }

        $clauses = [];
        $values  = [];

        foreach ($conditions as $column => $value) {
            if (is_array($value)) {
                // Handle IN clauses
                $placeholders = array_fill(0, count($value), '%s');
                $clauses[]    = "`{$column}` IN (" . implode(',', $placeholders) . ")";
                $values       = array_merge($values, $value);
            } elseif ($value === null) {
                $clauses[] = "`{$column}` IS NULL";
            } else {
                $clauses[] = "`{$column}` = %s";
                $values[]  = $value;
            }
        }

        return [
            'clause' => implode(" {$operator} ", $clauses),
            'values' => $values
        ];
    }

    /**
     * Find records with flexible conditions and pagination
     *
     * @param array $conditions WHERE conditions
     * @param array $options Query options (limit, offset, orderBy, order)
     * @param string $returnFormat Return format
     * @return array|WP_Error
     */
    public function findRecordsWhere(array $conditions = [], array $options = [], $returnFormat = OBJECT)
    {
        $returnFormat = $this->validateOutputFormat($returnFormat, OBJECT);

        $sql    = "SELECT * FROM {$this->tableName}";
        $values = [];

        if (!empty($conditions)) {
            $whereClause = $this->buildWhereClause($conditions);
            $sql .= " WHERE " . $whereClause['clause'];
            $values = $whereClause['values'];
        }

        // Add ordering
        if (!empty($options['orderBy'])) {
            $orderBy = sanitize_key($options['orderBy']);
            $order   = $this->sanitizeOrder($options['order'] ?? 'ASC');
            $sql .= " ORDER BY `{$orderBy}` {$order}";
        }

        // Add pagination
        if (isset($options['limit'])) {
            $limit = max(1, min(self::MAX_ITEMS_PER_PAGE, (int) $options['limit']));
            $sql .= " LIMIT {$limit}";

            if (isset($options['offset'])) {
                $offset = max(0, (int) $options['offset']);
                $sql .= " OFFSET {$offset}";
            }
        }

        return $this->findMultipleRecords($sql, $values, $returnFormat);
    }

    /**
     * Get paginated records with total count
     *
     * @param array $conditions WHERE conditions
     * @param int $page Page number (1-based)
     * @param int $itemsPerPage Items per page
     * @param string $orderBy Column to order by
     * @param string $order Order direction (ASC/DESC)
     * @return array|WP_Error
     */
    public function getPaginatedRecords(array $conditions = [], $page = 1, $itemsPerPage = null, $orderBy = 'id', $order = 'DESC')
    {
        if ($itemsPerPage === null) {
            $itemsPerPage = self::DEFAULT_ITEMS_PER_PAGE;
        }

        $pagination = $this->sanitizePagination($page, $itemsPerPage);

        $options = [
            'orderBy' => $orderBy,
            'order'   => $order,
            'limit'   => $pagination['perPage'],
            'offset'  => $pagination['offset']
        ];

        $records = $this->findRecordsWhere($conditions, $options);

        if (is_wp_error($records)) {
            return $records;
        }

        // Get total count
        $whereClause = $this->buildWhereClause($conditions);
        $countSql    = "SELECT COUNT(*) FROM {$this->tableName}";

        if (!empty($whereClause['clause'])) {
            $countSql .= " WHERE " . $whereClause['clause'];
        }

        if (!empty($whereClause['values'])) {
            // Ensure numeric array for spread operator to prevent "Cannot unpack array with string keys" error
            $whereValues = array_values($whereClause['values']);
            $totalCount  = $this->database->get_var($this->database->prepare($countSql, ...$whereValues));
        } else {
            $totalCount = $this->database->get_var($countSql);
        }

        if ($this->database->last_error) {
            return $this->createDatabaseError($this->database->last_error);
        }

        return [
            'records'    => $records,
            'pagination' => [
                'current_page' => $pagination['page'],
                'per_page'     => $pagination['perPage'],
                'total_items'  => (int) $totalCount,
                'total_pages'  => ceil($totalCount / $pagination['perPage']),
                'has_next'     => $pagination['page'] * $pagination['perPage'] < $totalCount,
                'has_previous' => $pagination['page'] > 1
            ]
        ];
    }

    // ========================================
    // HELPER AND ACCESSOR METHODS
    // ========================================

    /**
     * Get the full table name
     *
     * @return string Full table name with prefix
     */
    public function getFullTableName()
    {
        return $this->tableName;
    }

    /**
     * Get the table name without prefix
     *
     * @return string Table name without WordPress prefix
     */
    public function getTableSuffix()
    {
        return str_replace($this->database->prefix, '', $this->tableName);
    }

    /**
     * Execute a raw SQL query with optional prepared statement
     *
     * @param string $sql SQL query
     * @param array $parameters Optional parameters for prepared statement
     * @return mixed Query result
     */
    protected function executeRawQuery($sql, array $parameters = [])
    {
        if (empty($parameters)) {
            return $this->database->query($sql);
        }

        // Ensure numeric array for spread operator to prevent "Cannot unpack array with string keys" error
        $parameters = array_values($parameters);

        return $this->database->query($this->database->prepare($sql, ...$parameters));
    }

    /**
     * Begin database transaction (if supported)
     *
     * @return bool Success status
     */
    protected function beginTransaction()
    {
        return $this->database->query('START TRANSACTION') !== false;
    }

    /**
     * Commit database transaction (if supported)
     *
     * @return bool Success status
     */
    protected function commitTransaction()
    {
        return $this->database->query('COMMIT') !== false;
    }

    /**
     * Rollback database transaction (if supported)
     *
     * @return bool Success status
     */
    protected function rollbackTransaction()
    {
        return $this->database->query('ROLLBACK') !== false;
    }

    /**
     * Truncate the table (remove all records)
     *
     * @return bool|WP_Error Success status or error
     */
    protected function truncateTable()
    {
        $result = $this->database->query("TRUNCATE TABLE {$this->tableName}");

        if ($this->database->last_error) {
            return $this->createDatabaseError($this->database->last_error);
        }

        return $result !== false;
    }
}

// phpcs:enable WordPress.DB.DirectDatabaseQuery
