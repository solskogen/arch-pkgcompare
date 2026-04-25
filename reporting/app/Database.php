<?php
/**
 * Database connection and query helper class
 * Provides a clean interface for database operations
 */

// Security headers must be set before any output
require_once __DIR__ . '/SecurityHeaders.php';

class Database {
    private static $instance = null;
    private $conn;
    private $config;

    private function __construct() {
        $this->config = [
            'host' => getenv('DB_HOST') ?: 'localhost',
            'user' => getenv('DB_USER') ?: 'aarch64linux',
            'pass' => getenv('DB_PASS') ?: 'aarch64linux',  // WARNING: Default fallback should be removed in production
            'name' => getenv('DB_NAME') ?: 'aarch64linux'
        ];
        
        $this->connect();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function connect() {
        $this->conn = new mysqli(
            $this->config['host'],
            $this->config['user'],
            $this->config['pass'],
            $this->config['name']
        );

        if ($this->conn->connect_error) {
            error_log("Database connection failed: " . $this->conn->connect_error, 3, "/var/log/reporting.log");
            throw new Exception("An internal error occurred. Please contact support.");
        }
        $this->conn->set_charset("utf8");
    }

    public function query($sql) {
        $result = $this->conn->query($sql);
        if (!$result) {
            error_log("Query failed: " . $this->conn->error . " SQL: " . $sql, 3, "/var/log/reporting.log");
            throw new Exception("An internal error occurred. Please contact support.");
        }
        return $result;
    }

    public function fetchOne($sql) {
        $result = $this->query($sql);
        return $result->fetch_assoc();
    }

    public function fetchAll($sql) {
        $result = $this->query($sql);
        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        return $data;
    }

    public function escape($str) {
        return $this->conn->real_escape_string($str);
    }

    public function prepare($sql) {
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            error_log("Prepare failed: " . $this->conn->error . " SQL: " . $sql, 3, "/var/log/reporting.log");
            throw new Exception("An internal error occurred. Please contact support.");
        }
        return $stmt;
    }

    public function close() {
        if ($this->conn) {
            $this->conn->close();
        }
    }
}
?>
