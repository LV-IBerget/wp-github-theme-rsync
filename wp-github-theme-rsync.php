<?php
/**
 * Plugin Name: WP GitHub Theme Rsync
 * Plugin URI: https://github.com/spencomeister/wp-github-theme-rsync
 * Description: Automatically sync WordPress theme from GitHub releases with MD5 comparison
 * Author: Cleva Spencer
 * Version: 1.0.3
 * License: GPL v2 or later
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WP_GITHUB_THEME_RSYNC_VERSION', '1.0.3');
define('WP_GITHUB_THEME_RSYNC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WP_GITHUB_THEME_RSYNC_PLUGIN_URL', plugin_dir_url(__FILE__));

class WP_GitHub_Theme_Rsync {
    
    /** @var array|null Structured diagnostics for manual sync (admin AJAX only). */
    private $manual_sync_debug = null;
    
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
        
        $result = $this->execute_theme_sync(true);
        
        wp_send_json($result);
    }
    
    /**
     * @param bool $include_sync_debug When true (manual sync from admin), response includes sync_debug details.
     */
    public function execute_theme_sync($include_sync_debug = false) {
        $this->manual_sync_debug = $include_sync_debug ? array(
            'plugin_version' => WP_GITHUB_THEME_RSYNC_VERSION,
            'php_version' => PHP_VERSION,
            'zip_extension_loaded' => class_exists('ZipArchive'),
        ) : null;
        
        try {
            $result = $this->do_execute_theme_sync();
        } catch (Exception $e) {
            $this->sync_dbg('exception', $e->getMessage());
            $result = array(
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            );
        }
        
        $result = $this->attach_sync_debug($result);
        $this->manual_sync_debug = null;
        return $result;
    }
    
    private function sync_dbg($key, $value) {
        if ($this->manual_sync_debug === null) {
            return;
        }
        $this->manual_sync_debug[$key] = $value;
    }
    
    private function attach_sync_debug($result) {
        if ($this->manual_sync_debug !== null) {
            $result['sync_debug'] = $this->manual_sync_debug;
        }
        return $result;
    }
    
    private function list_directory_names($dir, $limit = 40) {
        if (!is_dir($dir)) {
            return array();
        }
        $names = array_diff(scandir($dir), array('.', '..'));
        $names = array_values($names);
        if (count($names) > $limit) {
            return array_merge(array_slice($names, 0, $limit), array('… (' . count($names) . ' total entries)'));
        }
        return $names;
    }
    
    private function zip_open_status_message($code) {
        if ($code === true) {
            return 'OK';
        }
        $n = (int) $code;
        // Numeric ZipArchive error codes (portable across PHP versions).
        $map = array(
            0 => 'ER_OK',
            19 => 'ER_NOZIP (not a valid zip — often HTML/error body instead of a zip)',
            11 => 'ER_OPEN (could not open file)',
            1 => 'ER_MULTIDISK',
            9 => 'ER_NOENT',
        );
        $label = isset($map[$n]) ? $map[$n] : 'see PHP ZipArchive docs';
        return $label . ' (code ' . $n . ')';
    }
    
    private function do_execute_theme_sync() {
        $settings = get_option('wp_github_theme_rsync_settings', array());
        
        if (empty($settings['github_repo']) || empty($settings['target_theme'])) {
            $this->sync_dbg('failure_stage', 'configuration');
            return array(
                'success' => false,
                'message' => 'GitHub repository or target theme not configured'
            );
        }
        
        $this->sync_dbg('target_theme_slug', $settings['target_theme']);
        $this->sync_dbg('theme_path', get_theme_root() . '/' . $settings['target_theme']);
        
        try {
            $release_data = $this->get_latest_github_release($settings['github_repo'], $settings['github_token']);
            
            if (!$release_data) {
                return array(
                    'success' => false,
                    'message' => 'Failed to fetch release data from GitHub'
                );
            }
            
            $this->sync_dbg('release_tag', isset($release_data['tag_name']) ? $release_data['tag_name'] : '(none)');
            
            if (empty($release_data['assets']) || !is_array($release_data['assets'])) {
                $this->sync_dbg('failure_stage', 'release_assets');
                $this->sync_dbg('release_has_assets_key', isset($release_data['assets']));
                return array(
                    'success' => false,
                    'message' => 'Latest GitHub release has no downloadable assets'
                );
            }
            
            $asset_names = array();
            foreach ($release_data['assets'] as $asset) {
                if (!empty($asset['name'])) {
                    $asset_names[] = $asset['name'];
                }
            }
            $this->sync_dbg('release_asset_names', $asset_names);
            
            $selected_asset = null;
            $selected_asset_obj = null;
            foreach ($release_data['assets'] as $asset) {
                if (!empty($asset['name']) && stripos($asset['name'], '.zip') !== false) {
                    $selected_asset = $asset['name'];
                    $selected_asset_obj = $asset;
                    break;
                }
            }
            
            if (!$selected_asset_obj || empty($selected_asset_obj['browser_download_url'])) {
                $this->sync_dbg('failure_stage', 'no_zip_asset');
                return array(
                    'success' => false,
                    'message' => 'No zip file found in the latest release'
                );
            }
            
            $this->sync_dbg('selected_zip_asset', $selected_asset);
            if (!empty($selected_asset_obj['id'])) {
                $this->sync_dbg('github_asset_id', $selected_asset_obj['id']);
            }
            
            $current_md5 = $this->calculate_theme_md5($settings['target_theme']);
            $this->sync_dbg('current_theme_md5', $current_md5 ? $current_md5 : '(empty — theme dir missing or no files)');
            
            $temp_file = $this->download_release_asset($selected_asset_obj, $settings['github_token']);
            if (!$temp_file) {
                return array(
                    'success' => false,
                    'message' => 'Failed to download release file'
                );
            }
            
            $new_md5 = $this->calculate_downloaded_theme_md5($temp_file);
            if (!$new_md5) {
                unlink($temp_file);
                $this->sync_dbg('failure_stage', 'md5_downloaded_theme');
                return array(
                    'success' => false,
                    'message' => 'Failed to calculate MD5 of downloaded theme'
                );
            }
            
            $this->sync_dbg('release_theme_md5', $new_md5);
            
            if ($current_md5 === $new_md5 && !empty($settings['last_md5']) && $settings['last_md5'] === $new_md5) {
                unlink($temp_file);
                $this->sync_dbg('outcome', 'MD5 matches installed theme and last recorded sync; no file copy performed.');
                return array(
                    'success' => true,
                    'message' => 'Theme is already up to date',
                    'no_update' => true
                );
            }
            
            $update_result = $this->update_theme($temp_file, $settings['target_theme']);
            
            if ($update_result['success']) {
                $settings['last_sync'] = current_time('mysql');
                $settings['last_version'] = $release_data['tag_name'];
                $settings['last_md5'] = $new_md5;
                update_option('wp_github_theme_rsync_settings', $settings);
            }
            
            unlink($temp_file);
            if (!empty($update_result['success'])) {
                $this->sync_dbg('outcome', 'Theme files copied from release zip to target theme directory.');
            }
            return $update_result;
            
        } catch (Exception $e) {
            $this->sync_dbg('failure_stage', 'exception');
            $this->sync_dbg('exception_detail', $e->getMessage());
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
            $this->sync_dbg('failure_stage', 'github_api');
            $this->sync_dbg('github_api_error', $response->get_error_message());
            return false;
        }
        
        $http = wp_remote_retrieve_response_code($response);
        $this->sync_dbg('github_api_http', $http);
        $body = wp_remote_retrieve_body($response);
        
        if ($http !== 200) {
            $this->sync_dbg('failure_stage', 'github_api');
            $snippet = is_string($body) ? trim(wp_strip_all_tags(substr($body, 0, 300))) : '';
            $this->sync_dbg('github_api_body_preview', $snippet);
            $decoded = json_decode($body, true);
            if (is_array($decoded) && !empty($decoded['message'])) {
                $this->sync_dbg('github_api_message', $decoded['message']);
            }
            return false;
        }
        
        $data = json_decode($body, true);
        if (!is_array($data)) {
            $this->sync_dbg('failure_stage', 'github_api_json');
            $this->sync_dbg('github_api_json_error', 'Response was not valid JSON');
            return false;
        }
        
        return $data;
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
            if (!class_exists('ZipArchive')) {
                $this->sync_dbg('md5_zip_issue', 'PHP ZipArchive class not available');
                return false;
            }
            
            if (!wp_mkdir_p($temp_extract_dir)) {
                $this->sync_dbg('md5_zip_issue', 'Could not create temp extract directory');
                $this->sync_dbg('md5_temp_dir', $temp_extract_dir);
                return false;
            }
            
            $zip = new ZipArchive();
            $open_result = $zip->open($zip_file);
            if ($open_result !== true) {
                $this->sync_dbg('md5_zip_issue', 'ZipArchive::open failed');
                $this->sync_dbg('zip_open_status', $this->zip_open_status_message($open_result));
                $this->remove_directory($temp_extract_dir);
                return false;
            }
            
            $zip->extractTo($temp_extract_dir);
            $zip->close();
            
            $this->sync_dbg('md5_extract_root_listing', $this->list_directory_names($temp_extract_dir));
            
            $theme_source_dir = $this->find_theme_directory($temp_extract_dir);
            if (!$theme_source_dir) {
                $this->sync_dbg('md5_zip_issue', 'No valid theme folder after extract (need style.css + Theme Name header, and index.php or theme.json)');
                $this->remove_directory($temp_extract_dir);
                return false;
            }
            
            $this->sync_dbg('detected_theme_path_relative', basename($theme_source_dir));
            
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
            $this->sync_dbg('md5_zip_issue', 'Exception: ' . $e->getMessage());
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
    
    /**
     * GitHub release assets: download via API URL + Accept: application/octet-stream first (works for private repos
     * with a PAT). browser_download_url often returns 404 for private assets without a session cookie.
     *
     * @param array $asset Asset object from GET /repos/.../releases/latest (includes url, browser_download_url).
     */
    private function download_release_asset($asset, $token = '') {
        $api_url = (!empty($asset['url']) && is_string($asset['url']) && strpos($asset['url'], 'api.github.com') !== false)
            ? $asset['url']
            : '';
        $browser_url = !empty($asset['browser_download_url']) ? $asset['browser_download_url'] : '';
        
        $attempts = array();
        if ($api_url !== '') {
            $attempts[] = array(
                'method' => 'api_asset',
                'url' => $api_url,
                'accept' => 'application/octet-stream',
            );
        }
        if ($browser_url !== '') {
            $attempts[] = array(
                'method' => 'browser_download_url',
                'url' => $browser_url,
                'accept' => null,
            );
        }
        
        if (empty($attempts)) {
            $this->sync_dbg('failure_stage', 'download');
            $this->sync_dbg('download_error', 'Release asset has no API or browser download URL');
            return false;
        }
        
        $this->sync_dbg('download_token_configured', !empty(trim((string) $token)));
        
        $last_http = null;
        foreach ($attempts as $attempt) {
            $temp_file = wp_tempnam();
            
            $headers = array(
                'User-Agent' => 'WordPress-GitHub-Theme-Sync',
            );
            if (!empty($attempt['accept'])) {
                $headers['Accept'] = $attempt['accept'];
            }
            if (!empty($token)) {
                $headers = array_merge($headers, $this->github_authorization_header($token));
            }
            
            $args = array(
                'stream' => true,
                'filename' => $temp_file,
                'headers' => $headers,
                'redirection' => 5,
                'timeout' => 300,
            );
            
            $response = wp_remote_get($attempt['url'], $args);
            
            if (is_wp_error($response)) {
                $this->sync_dbg('download_attempt', $attempt['method']);
                $this->sync_dbg('download_error', $response->get_error_message());
                if (file_exists($temp_file)) {
                    unlink($temp_file);
                }
                $last_http = null;
                continue;
            }
            
            $http = wp_remote_retrieve_response_code($response);
            $last_http = $http;
            $this->sync_dbg('download_attempt', $attempt['method']);
            $this->sync_dbg('download_http', $http);
            
            if ($http !== 200) {
                $body_preview = '';
                if (file_exists($temp_file)) {
                    $body_preview = @file_get_contents($temp_file, false, null, 0, 400);
                    $body_preview = $body_preview ? trim(wp_strip_all_tags($body_preview)) : '';
                    unlink($temp_file);
                }
                if ($body_preview !== '') {
                    $this->sync_dbg('download_body_preview', $body_preview);
                }
                continue;
            }
            
            if (!file_exists($temp_file)) {
                $this->sync_dbg('download_error', 'Temp file missing after download');
                continue;
            }
            
            $this->sync_dbg('download_method_used', $attempt['method']);
            
            $size = filesize($temp_file);
            $this->sync_dbg('download_bytes', $size);
            
            $magic = @file_get_contents($temp_file, false, null, 0, 4);
            $looks_like_zip = ($magic === "PK\x03\x04" || $magic === "PK\x05\x06" || $magic === "PK\x07\x08");
            $this->sync_dbg('download_starts_with_zip_magic', $looks_like_zip);
            if (!$looks_like_zip && $size > 0) {
                $this->sync_dbg('download_hint', 'File does not start with ZIP magic bytes; response may be HTML/JSON or a redirect page.');
            }
            
            return $temp_file;
        }
        
        $this->sync_dbg('failure_stage', 'download');
        if ($last_http === 404 && empty(trim((string) $token))) {
            $this->sync_dbg('download_hint', 'If this repository is private, create a PAT with repo scope and paste it in GitHub Personal Access Token.');
        }
        
        return false;
    }
    
    /**
     * @return array Authorization header line(s) for GitHub.
     */
    private function github_authorization_header($token) {
        $t = trim((string) $token);
        if ($t === '') {
            return array();
        }
        if (preg_match('/^Bearer\s+/i', $t)) {
            return array('Authorization' => $t);
        }
        return array('Authorization' => 'Bearer ' . $t);
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
            $open_result = $zip->open($zip_file);
            if ($open_result !== true) {
                $this->sync_dbg('update_zip_issue', 'ZipArchive::open failed');
                $this->sync_dbg('zip_open_status', $this->zip_open_status_message($open_result));
                $this->remove_directory($temp_extract_dir);
                return array(
                    'success' => false,
                    'message' => 'Failed to open zip file'
                );
            }
            
            $zip->extractTo($temp_extract_dir);
            $zip->close();
            
            $this->sync_dbg('update_extract_root_listing', $this->list_directory_names($temp_extract_dir));
            
            $theme_source_dir = $this->find_theme_directory($temp_extract_dir);
            
            if (!$theme_source_dir) {
                $this->sync_dbg('update_theme_issue', 'No valid theme directory in zip after extract');
                $this->remove_directory($temp_extract_dir);
                return array(
                    'success' => false,
                    'message' => 'No valid theme directory found in the zip file. Expected: style.css with a theme header plus either index.php (classic) or theme.json (block theme), in the root or a subdirectory.'
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
        
        // style.css is always required; classic themes need index.php, block themes use theme.json
        if (!file_exists($dir . '/style.css')) {
            return false;
        }
        $is_classic = file_exists($dir . '/index.php');
        $is_block = file_exists($dir . '/theme.json');
        if (!$is_classic && !$is_block) {
            return false;
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
