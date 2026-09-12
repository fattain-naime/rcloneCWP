<?php
/**
 * rcloneCWP Database Connection Singleton
 *
 * PSR-12 compliant, PHP 7.1+ compatible
 * @package CWP\RcloneCWP
 */

namespace CWP\RcloneCWP;

use PDO;
use PDOException;

class Database
{
    private static $instance = null;
    private $connection = null;

    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct()
    {
        $this->connect();
    }

    /**
     * Get the singleton instance
     *
     * @return Database
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get the PDO connection
     *
     * @return PDO
     * @throws PDOException if connection fails
     */
    public function getConnection()
    {
        if ($this->connection === null) {
            $this->connect();
        }
        return $this->connection;
    }

    /**
     * Establish database connection
     *
     * @return void
     * @throws PDOException if connection fails
     */
    private function connect()
    {
        $db = defined('RCLONE_DB') ? RCLONE_DB : [
            'host' => 'localhost',
            'name' => 'root_cwp',
            'user' => 'root',
            'pass' => ''
        ];

        $dsn = 'mysql:host=' . $db['host'] . ';dbname=' . $db['name'] . ';charset=utf8mb4';

        try {
            $this->connection = new PDO($dsn, $db['user'], $db['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
        } catch (PDOException $e) {
            throw new PDOException('Database connection failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Execute a query and fetch all results
     *
     * @param string $sql SQL query with placeholders
     * @param array $params Parameters to bind
     * @return array
     */
    public function fetchAll($sql, array $params = [])
    {
        $stmt = $this->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Execute a query and fetch single row
     *
     * @param string $sql SQL query with placeholders
     * @param array $params Parameters to bind
     * @return array|null
     */
    public function fetch(string $sql, array $params = []): ?array
    {
        $stmt = $this->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Execute a query and return the statement
     *
     * @param string $sql SQL query with placeholders
     * @param array $params Parameters to bind
     * @return int Number of affected rows
     */
    public function query($sql, array $params = [])
    {
        $stmt = $this->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Insert a row and return the last insert ID
     *
     * @param string $table Table name (whitelisted)
     * @param array $data Column => value pairs
     * @return int Last insert ID
     * @throws \InvalidArgumentException if table not whitelisted
     */
    public function insert($table, array $data)
    {
        $allowedTables = [
            'rclone_destinations', 'rclone_jobs', 'rclone_schedules',
            'rclone_backups', 'rclone_hooks', 'rclone_logs',
            'rclone_api_keys', 'rclone_notifications'
        ];

        if (!in_array($table, $allowedTables, true)) {
            throw new \InvalidArgumentException("Table not whitelisted: $table");
        }

        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');

        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';

        $stmt = $this->prepare($sql);
        $stmt->execute(array_values($data));
        return $this->connection->lastInsertId();
    }

    /**
     * Update rows and return the number of affected rows
     *
     * @param string $table Table name (whitelisted)
     * @param array $data Column => value pairs
     * @param string $where WHERE clause with placeholders
     * @param array $whereParams Parameters for WHERE clause
     * @return int Number of affected rows
     * @throws \InvalidArgumentException if table not whitelisted
     */
    public function update($table, array $data, $where, array $whereParams = [])
    {
        $allowedTables = [
            'rclone_destinations', 'rclone_jobs', 'rclone_schedules',
            'rclone_backups', 'rclone_hooks', 'rclone_logs',
            'rclone_api_keys', 'rclone_notifications'
        ];

        if (!in_array($table, $allowedTables, true)) {
            throw new \InvalidArgumentException("Table not whitelisted: $table");
        }

        $setClauses = [];
        foreach (array_keys($data) as $column) {
            $setClauses[] = $column . ' = ?';
        }

        $sql = 'UPDATE ' . $table . ' SET ' . implode(', ', $setClauses);
        if ($where) {
            $sql .= ' WHERE ' . $where;
        }

        $params = array_merge(array_values($data), $whereParams);
        $stmt = $this->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Delete rows and return the number of affected rows
     *
     * @param string $table Table name (whitelisted)
     * @param string $where WHERE clause with placeholders
     * @param array $params Parameters for WHERE clause
     * @return int Number of affected rows
     * @throws \InvalidArgumentException if table not whitelisted
     */
    public function delete($table, $where, array $params = [])
    {
        $allowedTables = [
            'rclone_destinations', 'rclone_jobs', 'rclone_schedules',
            'rclone_backups', 'rclone_hooks', 'rclone_logs',
            'rclone_api_keys', 'rclone_notifications'
        ];

        if (!in_array($table, $allowedTables, true)) {
            throw new \InvalidArgumentException("Table not whitelisted: $table");
        }

        $sql = 'DELETE FROM ' . $table . ' WHERE ' . $where;
        $stmt = $this->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Prepare a SQL statement
     *
     * @param string $sql SQL query with placeholders
     * @return \PDOStatement
     * @throws PDOException if preparation fails
     */
    public function prepare($sql)
    {
        return $this->connection->prepare($sql);
    }

    /**
     * Get database configuration
     *
     * @return array Database configuration from runtime probe chain
     */
    public function getConfig()
    {
        return RCLONE_DB;
    }

    /**
     * Begin a transaction
     *
     * @return bool
     */
    public function beginTransaction()
    {
        return $this->connection->beginTransaction();
    }

    /**
     * Commit a transaction
     *
     * @return bool
     */
    public function commit()
    {
        return $this->connection->commit();
    }

    /**
     * Rollback a transaction
     *
     * @return bool
     */
    public function rollback()
    {
        return $this->connection->rollBack();
    }

    /**
     * Prevent cloning
     */
    private function __clone() {}

    /**
     * Prevent unserialization
     */
    public function __wakeup()
    {
        throw new \Exception('Cannot unserialize singleton');
    }
}
