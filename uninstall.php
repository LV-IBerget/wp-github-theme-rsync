<?php
/**
 * Uninstall script for WP GitHub Theme Rsync
 * 
 * This file is executed when the plugin is deleted via the WordPress admin.
 */

// If uninstall not called from WordPress, then exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Delete plugin options
delete_option('wp_github_theme_rsync_settings');

// Clear any scheduled cron events
wp_clear_scheduled_hook('wp_github_theme_sync_cron');

// Optional: Clean up any log files or temporary data
$upload_dir = wp_upload_dir();
$log_dir = $upload_dir['basedir'] . '/wp-github-theme-rsync-logs/';

if (is_dir($log_dir)) {
    // Remove log directory and all files
    $files = glob($log_dir . '*');
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($log_dir);
}

// Remove any backup directories older than 30 days
$themes_dir = get_theme_root();
if (is_dir($themes_dir)) {
    $backup_dirs = glob($themes_dir . '/*_backup_*', GLOB_ONLYDIR);
    $thirty_days_ago = time() - (30 * 24 * 60 * 60);
    
    foreach ($backup_dirs as $backup_dir) {
        if (filemtime($backup_dir) < $thirty_days_ago) {
            // Recursively remove old backup directory
            function remove_backup_directory($dir) {
                if (!is_dir($dir)) {
                    return false;
                }
                
                $files = array_diff(scandir($dir), array('.', '..'));
                foreach ($files as $file) {
                    $path = $dir . '/' . $file;
                    is_dir($path) ? remove_backup_directory($path) : unlink($path);
                }
                return rmdir($dir);
            }
            
            remove_backup_directory($backup_dir);
        }
    }
}
