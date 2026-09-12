<?php
/**
 * rcloneCWP Schema Loader
 *
 * Loads SQL DDL files into the database with quote-aware statement splitting.
 * PSR-12 compliant, PHP 7.1+ compatible.
 * @package CWP\RcloneCWP
 */

namespace CWP\RcloneCWP;

class SchemaLoader
{
    /**
     * Load an SQL file and execute each statement.
     *
     * Statement splitting is quote-aware — semicolons inside string literals
     * ('...', "...", \`...\`) do not split statements.
     *
     * @param string $sqlFile Path to SQL file
     * @param Database|null $db Database instance (creates its own if null)
     * @return int Number of statements executed
     * @throws \RuntimeException if file not readable or a statement fails
     */
    public static function load($sqlFile, $db = null)
    {
        if (!is_file($sqlFile) || !is_readable($sqlFile)) {
            throw new \RuntimeException('SQL file not readable: ' . $sqlFile);
        }

        $sql = file_get_contents($sqlFile);
        if ($sql === false) {
            throw new \RuntimeException('Failed to read SQL file: ' . $sqlFile);
        }

        $statements = self::splitStatements($sql);

        if ($db === null) {
            $db = Database::getInstance();
        }

        $conn = $db->getConnection();
        $count = 0;

        foreach ($statements as $stmt) {
            $stmt = trim($stmt);
            if ($stmt === '') {
                continue;
            }

            try {
                $conn->exec($stmt);
                $count++;
            } catch (\PDOException $e) {
                throw new \RuntimeException('SQL statement failed: ' . $e->getMessage() . "\nStatement: " . substr($stmt, 0, 200), 0, $e);
            }
        }

        return $count;
    }

    /**
     * Split SQL into individual statements, respecting quotes.
     *
     * Handles:
     * - Single quotes '...'
     * - Double quotes "..."
     * - Backticks \`...\`
     * - Backslash escapes inside strings
     * - Line comments -- (until end of line)
     * - Block comments (slash-star ... star-slash)
     *
     * @param string $sql Full SQL string
     * @return array Array of statements (strings)
     */
    public static function splitStatements($sql)
    {
        $statements = [];
        $current = '';
        $len = strlen($sql);
        $i = 0;
        $inQuote = null;  // null, "'", '"', or '`'
        $escaped = false;
        $blockComment = false;

        while ($i < $len) {
            $char = $sql[$i];
            $peek = ($i + 1 < $len) ? $sql[$i + 1] : '';

            // Block comment handling: skip from /* to */ in one pass.
            // (Sets $blockComment only as a safety net for unterminated
            // comments — the skip loop below normally consumes the whole
            // comment and lands $i just past */.)
            if (!$inQuote && !$escaped && $char === '/' && $peek === '*') {
                $i += 2;
                while ($i + 1 < $len && !($sql[$i] === '*' && $sql[$i + 1] === '/')) {
                    $i++;
                }
                if ($i + 1 < $len) {
                    $i += 2;  // skip the closing */
                    $blockComment = false;
                } else {
                    $blockComment = true;  // unterminated comment: eat the rest
                }
                continue;
            }

            // Safety net: still inside an unterminated block comment
            if ($blockComment) {
                $i++;
                continue;
            }

            // Line comment --
            if (!$inQuote && !$escaped && $char === '-' && $peek === '-') {
                $i += 2;
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }

            // Quote handling
            if (!$escaped && in_array($char, ["'", '"', '`'], true)) {
                if ($inQuote === null) {
                    $inQuote = $char;
                } elseif ($inQuote === $char) {
                    $inQuote = null;
                }
            }

            // Escape handling
            $escaped = ($char === '\\' && !$escaped);

            // Statement separator
            if (!$inQuote && $char === ';' && !$escaped) {
                $stmt = trim($current);
                if ($stmt !== '') {
                    $statements[] = $stmt;
                }
                $current = '';
                $i++;
                continue;
            }

            $current .= $char;
            $i++;
        }

        // Add final statement if no trailing ;
        if (trim($current) !== '') {
            $statements[] = trim($current);
        }

        return $statements;
    }

    /**
     * Get the statement count from an SQL file without executing.
     *
     * @param string $sqlFile Path to SQL file
     * @return int Number of statements
     */
    public static function countStatements($sqlFile)
    {
        if (!is_file($sqlFile) || !is_readable($sqlFile)) {
            return 0;
        }

        $sql = file_get_contents($sqlFile);
        if ($sql === false) {
            return 0;
        }

        return count(self::splitStatements($sql));
    }
}
