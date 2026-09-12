<?php
/**
 * rcloneCWP Logger
 *
 * PSR-12 compliant, PHP 7.1+ compatible
 * @package CWP\RcloneCWP
 */

namespace CWP\RcloneCWP;

use DateTime;
use DateTimeZone;

class Logger
{
    private $logDir;
    private $db;
    private $minLevel = 'debug';

    /**
     * Constructor
     *
     * @param string|null $logDir Log directory path (defaults to RCLONE_LOG_DIR)
     * @param Database|null $db Database connection for writing to rclone_logs
     */
    public function __construct($logDir = null, $db = null)
    {
        $this->logDir = $logDir ?: RCLONE_LOG_DIR;
        $this->db = $db;

        // Create log directory if it doesn't exist
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0700, true);
        }
    }

    /**
     * Set minimum log level (DEBUG, INFO, WARNING, ERROR)
     *
     * @param string $level Minimum level to log
     */
    public function setMinLevel($level)
    {
        $this->minLevel = strtoupper($level);
    }

    /**
     * Log debug message
     *
     * @param string $message Log message
     * @param array $context Additional context
     */
    public function debug($message, array $context = [])
    {
        $this->log('debug', $message, $context);
    }

    /**
     * Log info message
     *
     * @param string $message Log message
     * @param array $context Additional context
     */
    public function info($message, array $context = [])
    {
        $this->log('info', $message, $context);
    }

    /**
     * Log warning message
     *
     * @param string $message Log message
     * @param array $context Additional context
     */
    public function warning($message, array $context = [])
    {
        $this->log('warning', $message, $context);
    }

    /**
     * Log error message
     *
     * @param string $message Log message
     * @param array $context Additional context
     */
    public function error($message, array $context = [])
    {
        $this->log('error', $message, $context);
    }

    /**
     * Internal logging method
     *
     * @param string $level Log level
     * @param string $message Log message
     * @param array $context Additional context
     */
    private function log($level, $message, array $context = [])
    {
        // Check level filter
        $levels = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];
        if ($levels[strtolower($level)] < $levels[strtolower($this->minLevel)]) {
            return;
        }

        // Format context for display
        $contextStr = '';
        if (!empty($context)) {
            $contextStr = ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        }

        // Create log entry
        $date = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $entry = sprintf(
            "[%s] [%s] %s%s",
            $date,
            strtoupper($level),
            $message,
            $contextStr
        );

        // Write to file
        $this->writeToFile($level, $entry);

        // Write to database if available
        if ($this->db) {
            $this->writeToDb($level, $message, $context);
        }
    }

    /**
     * Write log entry to file
     *
     * @param string $level Log level
     * @param string $entry Log entry
     */
    private function writeToFile($level, $entry)
    {
        $file = $this->logDir . '/rcloneCWP.log';
        $date = date('Y-m-d');

        // Append to daily rotated file
        $logFile = $this->logDir . '/rcloneCWP.' . $date . '.log';

        if (!is_file($logFile)) {
            @touch($logFile);
            @chmod($logFile, 0600);
        }

        @file_put_contents($logFile, $entry . PHP_EOL, FILE_APPEND);
    }

    /**
     * Write log entry to database
     *
     * @param string $level Log level
     * @param string $message Log message
     * @param array $context Additional context
     */
    private function writeToDb($level, $message, array $context = [])
    {
        try {
            $categoryId = $this->getCategoryFromMessage($message);

            $this->db->insert('rclone_logs', [
                'level' => $level,
                'category' => $categoryId,
                'message' => $message,
                'context' => !empty($context) ? json_encode($context) : null
            ]);
        } catch (\Exception $e) {
            // Silently fail if DB write fails - file log is primary
            error_log('Logger DB write failed: ' . $e->getMessage());
        }
    }

    /**
     * Extract category from message
     *
     * @param string $message Log message
     * @return string
     */
    private function getCategoryFromMessage($message)
    {
        $categories = [
            'auth' => 'Authentication',
            'database' => 'Database',
            'backup' => 'Backup',
            'restore' => 'Restore',
            'rclone' => 'Rclone',
            'api' => 'API',
            'notification' => 'Notification',
            'hook' => 'Hook',
            'error' => 'Error',
            'system' => 'System'
        ];

        foreach ($categories as $key => $name) {
            if (stripos($message, $key) !== false) {
                return $name;
            }
        }

        return 'System';
    }

    /**
     * Get log directory
     *
     * @return string
     */
    public function getLogDir(): string
    {
        return $this->logDir;
    }
}
