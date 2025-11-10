<?php

if (!defined('ABSPATH')) {
    exit;
}

class MorPOS_Logger
{
    public static function log($msg, $level = 'info')
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $line = '[' . gmdate('c') . "] [$level] $msg";
            error_log($line);
        }
    }
}
