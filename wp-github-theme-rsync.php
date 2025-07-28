<?php
/**
 * Plugin Name: WP GitHub Theme Rsync
 * Plugin URI: https://github.com/spencomeister/wp-github-theme-rsync
 * Description: Automatically sync WordPress theme from GitHub releases with MD5 comparison
 * Version: 1.0.1
 * Author: Cleva Spencer
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
            
            // Download and extract to temporary location for MD5 calculation
            $temp_file = $this->download_release($download_url, $settings['github_token']);
            if (!$temp_file) {
                return array(
                    'success' => false,
                    'message' => 'Failed to download release file'
                );
            }
            
            // Calculate MD5 of new theme by extracting and analyzing
            $new_md5 = $this->calculate_downloaded_theme_md5($temp_file);
            if (!$new_md5) {
                unlink($temp_file);
                return array(
                    'success' => false,
                    'message' => 'Failed to calculate MD5 of downloaded theme'
                );
            }
            
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
                $relative_path = str_replace($theme_dir, '', $file);
                $combined_content .= $relative_path . ':' . md5_file($file);
            }
        }
        
        return md5($combined_content);
    }
    
    private function calculate_downloaded_theme_md5($zip_file) {
        $temp_extract_dir = wp_tempnam() . '_md5_check';
        
        try {
            // Create temporary extraction directory
            if (!wp_mkdir_p($temp_extract_dir)) {
                return false;
            }
            
            // Extract zip
            $zip = new ZipArchive();
            if ($zip->open($zip_file) !== TRUE) {
                $this->remove_directory($temp_extract_dir);
                return false;
            }
            
            $zip->extractTo($temp_extract_dir);
            $zip->close();
            
            // Find theme directory
            $theme_source_dir = $this->find_theme_directory($temp_extract_dir);
            if (!$theme_source_dir) {
                $this->remove_directory($temp_extract_dir);
                return false;
            }
            
            // Calculate MD5
            $files = $this->get_all_theme_files($theme_source_dir);
            $combined_content = '';
            
            foreach ($files as $file) {
                if (is_file($file)) {
                    $relative_path = str_replace($theme_source_dir, '', $file);
                    $combined_content .= $relative_path . ':' . md5_file($file);
                }
            }
            
            $md5 = md5($combined_content);
            
            // Clean up
            $this->remove_directory($temp_extract_dir);
            
            return $md5;
            
        } catch (Exception $e) {
            $this->remove_directory($temp_extract_dir);
            return false;
        }
    }
    
    private function get_all_theme_files($dir) {
        $files = array();
        
        if (!is_dir($dir)) {
            return $files;
        }
        
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $extension = strtolower($file->getExtension());
                    $filename = $file->getFilename();
                    
                    // Skip temporary, log, and system files
                    if (!in_array($extension, array('log', 'tmp', 'cache', 'bak')) && 
                        !in_array($filename, array('.DS_Store', 'Thumbs.db', 'desktop.ini')) &&
                        strpos($filename, '_backup_') === false) {
                        $files[] = $file->getPathname();
                    }
                }
            }
            
            // Sort files for consistent MD5 calculation
            sort($files);
            
        } catch (Exception $e) {
            // Fallback to simple directory scan if RecursiveIterator fails
            $files = $this->get_files_simple($dir);
        }
        
        return $files;
    }
    
    private function get_files_simple($dir) {
        $files = array();
        $items = scandir($dir);
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            
            $path = $dir . '/' . $item;
            if (is_file($path)) {
                $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if (!in_array($extension, array('log', 'tmp', 'cache', 'bak')) && 
                    !in_array($item, array('.DS_Store', 'Thumbs.db', 'desktop.ini'))) {
                    $files[] = $path;
                }
            } elseif (is_dir($path)) {
                $files = array_merge($files, $this->get_files_simple($path));
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
        $temp_extract_dir = wp_tempnam() . '_extract';
        
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
        
        // Create temporary extraction directory
        if (!wp_mkdir_p($temp_extract_dir)) {
            return array(
                'success' => false,
                'message' => 'Failed to create temporary directory'
            );
        }
        
        try {
            // Extract zip to temporary directory
            $zip = new ZipArchive();
            if ($zip->open($zip_file) !== TRUE) {
                $this->remove_directory($temp_extract_dir);
                return array(
                    'success' => false,
                    'message' => 'Failed to open zip file'
                );
            }
            
            $zip->extractTo($temp_extract_dir);
            $zip->close();
            
            // Find the actual theme directory within the extracted content
            $theme_source_dir = $this->find_theme_directory($temp_extract_dir);
            
            if (!$theme_source_dir) {
                $this->remove_directory($temp_extract_dir);
                return array(
                    'success' => false,
                    'message' => 'No valid theme directory found in the zip file. Expected structure: theme files (style.css, index.php) should be in the root or in a subdirectory.'
                );
            }
            
            // Remove existing theme directory
            if (is_dir($theme_dir)) {
                $this->remove_directory($theme_dir);
            }
            
            // Copy theme files to final destination
            if (!$this->copy_directory($theme_source_dir, $theme_dir)) {
                // Restore backup if copy failed
                if (is_dir($backup_dir)) {
                    $this->copy_directory($backup_dir, $theme_dir);
                }
                $this->remove_directory($temp_extract_dir);
                return array(
                    'success' => false,
                    'message' => 'Failed to copy theme files'
                );
            }
            
            // Clean up
            $this->remove_directory($temp_extract_dir);
            $this->remove_directory($backup_dir);
            
            return array(
                'success' => true,
                'message' => 'Theme updated successfully'
            );
            
        } catch (Exception $e) {
            // Clean up on error
            $this->remove_directory($temp_extract_dir);
            
            // Restore backup if it exists
            if (is_dir($backup_dir) && !is_dir($theme_dir)) {
                $this->copy_directory($backup_dir, $theme_dir);
            }
            
            return array(
                'success' => false,
                'message' => 'Error during theme update: ' . $e->getMessage()
            );
        }
    }
    
    private function find_theme_directory($extract_dir) {
        // First, check if theme files are directly in the extract directory
        if ($this->is_valid_theme_directory($extract_dir)) {
            return $extract_dir;
        }
        
        // If not, look for theme files in subdirectories
        $subdirs = glob($extract_dir . '/*', GLOB_ONLYDIR);
        
        foreach ($subdirs as $subdir) {
            if ($this->is_valid_theme_directory($subdir)) {
                return $subdir;
            }
            
            // Check one level deeper (for nested structures)
            $nested_dirs = glob($subdir . '/*', GLOB_ONLYDIR);
            foreach ($nested_dirs as $nested_dir) {
                if ($this->is_valid_theme_directory($nested_dir)) {
                    return $nested_dir;
                }
            }
        }
        
        return false;
    }
    
    private function is_valid_theme_directory($dir) {
        if (!is_dir($dir)) {
            return false;
        }
        
        // Check for required WordPress theme files
        $required_files = array('style.css', 'index.php');
        
        foreach ($required_files as $file) {
            if (!file_exists($dir . '/' . $file)) {
                return false;
            }
        }
        
        // Additional validation: check if style.css contains theme header
        $style_css = $dir . '/style.css';
        if (is_readable($style_css)) {
            $style_content = file_get_contents($style_css, false, null, 0, 1024); // Read first 1KB
            if (strpos($style_content, 'Theme Name:') === false) {
                return false;
            }
        }
        
        return true;
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
