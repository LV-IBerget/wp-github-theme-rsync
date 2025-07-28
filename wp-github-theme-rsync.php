<?php
/**
 * Plugin Name: WP GitHub Theme Rsync
 * Plugin URI: https://example.com
 * Description: Automatically sync WordPress theme from GitHub releases with MD5 comparison
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL v2 or later
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WP_GITHUB_THEME_RSYNC_VERSION', '1.0.0');
define('WP_GITHUB_THEME_RSYNC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WP_GITHUB_THEME_RSYNC_PLUGIN_URL', plugin_dir_url(__FILE__));

class WP_GitHub_Theme_Rsync {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    public function init() {
        // Admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Ajax handlers
        add_action('wp_ajax_manual_theme_sync', array($this, 'manual_theme_sync'));
        
        // WP-Cron setup
        add_action('wp_github_theme_sync_cron', array($this, 'execute_theme_sync'));
        
        // Enqueue admin scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }
    
    public function activate() {
        // Schedule WP-Cron event (daily check)
        if (!wp_next_scheduled('wp_github_theme_sync_cron')) {
            wp_schedule_event(time(), 'daily', 'wp_github_theme_sync_cron');
        }
        
        // Create options with default values
        add_option('wp_github_theme_rsync_settings', array(
            'github_repo' => '',
            'github_token' => '',
            'target_theme' => '',
            'auto_sync_enabled' => true,
            'last_sync' => '',
            'last_version' => '',
            'last_md5' => ''
        ));
    }
    
    public function deactivate() {
        // Clear scheduled events
        wp_clear_scheduled_hook('wp_github_theme_sync_cron');
    }
    
    public function add_admin_menu() {
        add_options_page(
            'GitHub Theme Sync',
            'GitHub Theme Sync',
            'manage_options',
            'wp-github-theme-rsync',
            array($this, 'admin_page')
        );
    }
    
    public function enqueue_admin_scripts($hook) {
        if ('settings_page_wp-github-theme-rsync' !== $hook) {
            return;
        }
        
        wp_enqueue_script(
            'wp-github-theme-rsync-admin',
            WP_GITHUB_THEME_RSYNC_PLUGIN_URL . 'admin.js',
            array('jquery'),
            WP_GITHUB_THEME_RSYNC_VERSION,
            true
        );
        
        wp_localize_script('wp-github-theme-rsync-admin', 'wpGithubThemeRsync', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wp_github_theme_rsync_nonce')
        ));
    }
    
    public function admin_page() {
        $settings = get_option('wp_github_theme_rsync_settings', array());
        
        if (isset($_POST['submit'])) {
            $settings = array(
                'github_repo' => sanitize_text_field($_POST['github_repo']),
                'github_token' => sanitize_text_field($_POST['github_token']),
                'target_theme' => sanitize_text_field($_POST['target_theme']),
                'auto_sync_enabled' => isset($_POST['auto_sync_enabled']),
                'last_sync' => $settings['last_sync'] ?? '',
                'last_version' => $settings['last_version'] ?? '',
                'last_md5' => $settings['last_md5'] ?? ''
            );
            update_option('wp_github_theme_rsync_settings', $settings);
            echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
        }
        
        include WP_GITHUB_THEME_RSYNC_PLUGIN_DIR . 'admin-page.php';
    }
    
    public function manual_theme_sync() {
        check_ajax_referer('wp_github_theme_rsync_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $result = $this->execute_theme_sync();
        
        wp_send_json($result);
    }
    
    public function execute_theme_sync() {
        $settings = get_option('wp_github_theme_rsync_settings', array());
        
        if (empty($settings['github_repo']) || empty($settings['target_theme'])) {
            return array(
                'success' => false,
                'message' => 'GitHub repository or target theme not configured'
            );
        }
        
        try {
            // Get latest release from GitHub
            $release_data = $this->get_latest_github_release($settings['github_repo'], $settings['github_token']);
            
            if (!$release_data) {
                return array(
                    'success' => false,
                    'message' => 'Failed to fetch release data from GitHub'
                );
            }
            
            // Find zip asset
            $download_url = null;
            foreach ($release_data['assets'] as $asset) {
                if (strpos($asset['name'], '.zip') !== false) {
                    $download_url = $asset['browser_download_url'];
                    break;
                }
            }
            
            if (!$download_url) {
                return array(
                    'success' => false,
                    'message' => 'No zip file found in the latest release'
                );
            }
            
            // Calculate MD5 of current theme
            $current_md5 = $this->calculate_theme_md5($settings['target_theme']);
            
            // Download and calculate MD5 of new theme
            $temp_file = $this->download_release($download_url, $settings['github_token']);
            if (!$temp_file) {
                return array(
                    'success' => false,
                    'message' => 'Failed to download release file'
                );
            }
            
            $new_md5 = md5_file($temp_file);
            
            // Compare MD5
            if ($current_md5 === $new_md5 && !empty($settings['last_md5']) && $settings['last_md5'] === $new_md5) {
                unlink($temp_file);
                return array(
                    'success' => true,
                    'message' => 'Theme is already up to date',
                    'no_update' => true
                );
            }
            
            // Extract and update theme
            $update_result = $this->update_theme($temp_file, $settings['target_theme']);
            
            if ($update_result['success']) {
                // Update settings with new sync info
                $settings['last_sync'] = current_time('mysql');
                $settings['last_version'] = $release_data['tag_name'];
                $settings['last_md5'] = $new_md5;
                update_option('wp_github_theme_rsync_settings', $settings);
            }
            
            unlink($temp_file);
            return $update_result;
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            );
        }
    }
    
    private function get_latest_github_release($repo, $token = '') {
        $url = "https://api.github.com/repos/{$repo}/releases/latest";
        
        $args = array(
            'headers' => array(
                'User-Agent' => 'WordPress-GitHub-Theme-Sync'
            )
        );
        
        if (!empty($token)) {
            $args['headers']['Authorization'] = "token {$token}";
        }
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }
    
    private function calculate_theme_md5($theme_name) {
        $theme_dir = get_theme_root() . '/' . $theme_name;
        
        if (!is_dir($theme_dir)) {
            return '';
        }
        
        $files = $this->get_all_theme_files($theme_dir);
        $combined_content = '';
        
        foreach ($files as $file) {
            if (is_file($file)) {
                $combined_content .= md5_file($file);
            }
        }
        
        return md5($combined_content);
    }
    
    private function get_all_theme_files($dir) {
        $files = array();
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && !in_array($file->getExtension(), array('log', 'tmp'))) {
                $files[] = $file->getPathname();
            }
        }
        
        return $files;
    }
    
    private function download_release($url, $token = '') {
        $temp_file = wp_tempnam();
        
        $args = array(
            'stream' => true,
            'filename' => $temp_file,
            'headers' => array(
                'User-Agent' => 'WordPress-GitHub-Theme-Sync'
            )
        );
        
        if (!empty($token)) {
            $args['headers']['Authorization'] = "token {$token}";
        }
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            return false;
        }
        
        return $temp_file;
    }
    
    private function update_theme($zip_file, $theme_name) {
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php');
        require_once(ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php');
        
        $theme_dir = get_theme_root() . '/' . $theme_name;
        
        // Create backup
        $backup_dir = $theme_dir . '_backup_' . date('YmdHis');
        if (is_dir($theme_dir)) {
            if (!$this->copy_directory($theme_dir, $backup_dir)) {
                return array(
                    'success' => false,
                    'message' => 'Failed to create backup'
                );
            }
        }
        
        // Extract zip
        $zip = new ZipArchive();
        if ($zip->open($zip_file) === TRUE) {
            // Remove existing theme directory
            if (is_dir($theme_dir)) {
                $this->remove_directory($theme_dir);
            }
            
            // Extract to theme directory
            $zip->extractTo(get_theme_root());
            $zip->close();
            
            // Check if extraction created a subdirectory
            $extracted_dirs = glob(get_theme_root() . '/*', GLOB_ONLYDIR);
            $latest_dir = '';
            $latest_time = 0;
            
            foreach ($extracted_dirs as $dir) {
                if (filemtime($dir) > $latest_time && basename($dir) !== $theme_name && basename($dir) !== basename($backup_dir)) {
                    $latest_time = filemtime($dir);
                    $latest_dir = $dir;
                }
            }
            
            // If extracted to a different directory name, rename it
            if ($latest_dir && basename($latest_dir) !== $theme_name) {
                rename($latest_dir, $theme_dir);
            }
            
            // Remove backup if successful
            $this->remove_directory($backup_dir);
            
            return array(
                'success' => true,
                'message' => 'Theme updated successfully'
            );
            
        } else {
            return array(
                'success' => false,
                'message' => 'Failed to extract zip file'
            );
        }
    }
    
    private function copy_directory($src, $dst) {
        if (!is_dir($src)) {
            return false;
        }
        
        if (!is_dir($dst)) {
            mkdir($dst, 0755, true);
        }
        
        $dir = opendir($src);
        while (($file = readdir($dir)) !== false) {
            if ($file != '.' && $file != '..') {
                if (is_dir($src . '/' . $file)) {
                    $this->copy_directory($src . '/' . $file, $dst . '/' . $file);
                } else {
                    copy($src . '/' . $file, $dst . '/' . $file);
                }
            }
        }
        closedir($dir);
        return true;
    }
    
    private function remove_directory($dir) {
        if (!is_dir($dir)) {
            return false;
        }
        
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->remove_directory($path) : unlink($path);
        }
        return rmdir($dir);
    }
}

// Initialize the plugin
new WP_GitHub_Theme_Rsync();
