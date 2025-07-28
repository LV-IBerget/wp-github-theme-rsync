<div class="wrap">
    <h1>GitHub Theme Sync Settings</h1>
    
    <form method="post" action="">
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="github_repo">GitHub Repository</label>
                </th>
                <td>
                    <input type="text" 
                           id="github_repo" 
                           name="github_repo" 
                           value="<?php echo esc_attr($settings['github_repo'] ?? ''); ?>" 
                           class="regular-text" 
                           placeholder="username/repository" />
                    <p class="description">Format: username/repository (e.g., your-username/your-theme)</p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="github_token">GitHub Personal Access Token</label>
                </th>
                <td>
                    <input type="password" 
                           id="github_token" 
                           name="github_token" 
                           value="<?php echo esc_attr($settings['github_token'] ?? ''); ?>" 
                           class="regular-text" />
                    <p class="description">Optional: Required for private repositories or to avoid rate limits</p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="target_theme">Target Theme Directory</label>
                </th>
                <td>
                    <select id="target_theme" name="target_theme" class="regular-text">
                        <option value="">Select a theme...</option>
                        <?php
                        $themes = wp_get_themes();
                        $selected_theme = $settings['target_theme'] ?? '';
                        foreach ($themes as $theme_slug => $theme_data) {
                            $selected = selected($selected_theme, $theme_slug, false);
                            echo "<option value='{$theme_slug}' {$selected}>{$theme_data->get('Name')} ({$theme_slug})</option>";
                        }
                        ?>
                    </select>
                    <p class="description">Select the theme to be synchronized</p>
                </td>
            </tr>
            
            <tr>
                <th scope="row">
                    <label for="auto_sync_enabled">Auto Sync</label>
                </th>
                <td>
                    <input type="checkbox" 
                           id="auto_sync_enabled" 
                           name="auto_sync_enabled" 
                           <?php checked($settings['auto_sync_enabled'] ?? true); ?> />
                    <label for="auto_sync_enabled">Enable automatic daily synchronization via WP-Cron</label>
                </td>
            </tr>
        </table>
        
        <?php submit_button(); ?>
    </form>
    
    <hr>
    
    <h2>Sync Status</h2>
    <table class="form-table">
        <tr>
            <th scope="row">Last Sync:</th>
            <td><?php echo !empty($settings['last_sync']) ? esc_html($settings['last_sync']) : 'Never'; ?></td>
        </tr>
        <tr>
            <th scope="row">Last Version:</th>
            <td><?php echo !empty($settings['last_version']) ? esc_html($settings['last_version']) : 'N/A'; ?></td>
        </tr>
        <tr>
            <th scope="row">Current Theme MD5:</th>
            <td>
                <code id="current-md5"><?php 
                    if (!empty($settings['target_theme'])) {
                        $plugin = new WP_GitHub_Theme_Rsync();
                        $reflection = new ReflectionClass($plugin);
                        $method = $reflection->getMethod('calculate_theme_md5');
                        $method->setAccessible(true);
                        echo esc_html($method->invoke($plugin, $settings['target_theme']));
                    } else {
                        echo 'No theme selected';
                    }
                ?></code>
            </td>
        </tr>
    </table>
    
    <h2>Manual Sync</h2>
    <p>Click the button below to manually check for updates and sync the theme:</p>
    <button type="button" id="manual-sync-btn" class="button button-primary">
        <span class="sync-text">Check for Updates</span>
        <span class="sync-loading" style="display: none;">
            <span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span>
            Syncing...
        </span>
    </button>
    
    <div id="sync-result" style="margin-top: 15px;"></div>
    
    <hr>
    
    <h2>Cron Information</h2>
    <p><strong>Next Scheduled Sync:</strong> 
        <?php 
        $next_sync = wp_next_scheduled('wp_github_theme_sync_cron');
        echo $next_sync ? date('Y-m-d H:i:s', $next_sync) : 'Not scheduled';
        ?>
    </p>
    
    <h2>Debug Information</h2>
    <details>
        <summary>Show Debug Info</summary>
        <pre style="background: #f1f1f1; padding: 10px; margin-top: 10px;"><?php
            echo "WordPress Version: " . get_bloginfo('version') . "\n";
            echo "PHP Version: " . PHP_VERSION . "\n";
            echo "Plugin Version: " . WP_GITHUB_THEME_RSYNC_VERSION . "\n";
            echo "WP-Cron Enabled: " . (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON ? 'No' : 'Yes') . "\n";
            echo "Theme Root: " . get_theme_root() . "\n";
            echo "Current Settings:\n";
            print_r($settings);
        ?></pre>
    </details>
</div>
