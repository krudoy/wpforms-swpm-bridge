<?php

declare(strict_types=1);

namespace SWPMWPForms\Services;

/**
 * Logging service with database table writes.
 */
class Logger {
    
    public const LEVEL_DEBUG = 'debug';
    public const LEVEL_INFO = 'info';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR = 'error';
    
    private const LEVEL_PRIORITY = [
        self::LEVEL_DEBUG => 0,
        self::LEVEL_INFO => 1,
        self::LEVEL_WARNING => 2,
        self::LEVEL_ERROR => 3,
    ];
    
    private static ?Logger $instance = null;
    private string $minLevel;
    private string $tableName;
    
    /**
     * Get singleton instance.
     */
    public static function instance(): Logger {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        global $wpdb;
        $this->tableName = $wpdb->prefix . 'swpm_wpforms_logs';
        
        $settings = get_option('swpm_wpforms_settings', []);
        $this->minLevel = $settings['log_level'] ?? self::LEVEL_ERROR;
    }
    
    /**
     * Log a debug message.
     */
    public function debug(string $message, array $context = []): void {
        $this->log(self::LEVEL_DEBUG, $message, $context);
    }
    
    /**
     * Log an info message.
     */
    public function info(string $message, array $context = []): void {
        $this->log(self::LEVEL_INFO, $message, $context);
    }
    
    /**
     * Log a warning message.
     */
    public function warning(string $message, array $context = []): void {
        $this->log(self::LEVEL_WARNING, $message, $context);
    }
    
    /**
     * Log an error message.
     */
    public function error(string $message, array $context = []): void {
        $this->log(self::LEVEL_ERROR, $message, $context);
    }
    
    /**
     * Log a message at the specified level.
     */
    public function log(string $level, string $message, array $context = []): void {
        // Check if we should log at this level
        if (!$this->shouldLog($level)) {
            return;
        }
        
        global $wpdb;
        
        $data = [
            'level' => $level,
            'message' => $message,
            'context' => !empty($context) ? wp_json_encode($context) : null,
            'form_id' => $context['form_id'] ?? null,
            'entry_id' => $context['entry_id'] ?? null,
            'user_id' => get_current_user_id() ?: null,
            'created_at' => current_time('mysql'),
        ];
        
        $wpdb->insert($this->tableName, $data);
        
        // Also log to debug.log if WP_DEBUG_LOG is enabled
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log(sprintf(
                '[SWPM-WPForms] [%s] %s %s',
                strtoupper($level),
                $message,
                !empty($context) ? wp_json_encode($context) : ''
            ));
        }
    }
    
    /**
     * Check if we should log at the given level.
     */
    private function shouldLog(string $level): bool {
        $levelPriority = self::LEVEL_PRIORITY[$level] ?? 0;
        $minPriority = self::LEVEL_PRIORITY[$this->minLevel] ?? 0;
        
        return $levelPriority >= $minPriority;
    }
    
    /**
     * Get logs with optional filtering.
     */
    public function getLogs(array $args = []): array {
        global $wpdb;
        
        $defaults = [
            'level' => null,
            'form_id' => null,
            'limit' => 100,
            'offset' => 0,
            'order' => 'DESC',
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        $where = ['1=1'];
        $values = [];
        
        if ($args['level']) {
            $where[] = 'level = %s';
            $values[] = $args['level'];
        }
        
        if ($args['form_id']) {
            $where[] = 'form_id = %d';
            $values[] = $args['form_id'];
        }
        
        $sql = sprintf(
            "SELECT * FROM {$this->tableName} WHERE %s ORDER BY created_at %s LIMIT %d OFFSET %d",
            implode(' AND ', $where),
            $args['order'] === 'ASC' ? 'ASC' : 'DESC',
            absint($args['limit']),
            absint($args['offset'])
        );
        
        if (!empty($values)) {
            $sql = $wpdb->prepare($sql, ...$values);
        }
        
        return $wpdb->get_results($sql, ARRAY_A) ?: [];
    }
    
    /**
     * Delete old logs based on retention policy.
     */
    public function cleanup(int $retentionDays = 30): int {
        global $wpdb;
        
        $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));
        
        return (int) $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->tableName} WHERE created_at < %s",
                $cutoff
            )
        );
    }
}