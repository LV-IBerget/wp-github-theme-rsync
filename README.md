# WP GitHub Theme Rsync

A WordPress plugin that automatically synchronizes your WordPress theme with the latest release from a GitHub repository using MD5 comparison to detect changes.

## Features

- **Automatic Sync**: Uses WP-Cron to check for updates daily
- **Manual Sync**: Admin panel button for manual synchronization
- **MD5 Comparison**: Only updates when actual changes are detected
- **GitHub Integration**: Works with public and private repositories
- **Backup Creation**: Creates backups before updating themes
- **Admin Interface**: Easy-to-use settings page in WordPress admin

## Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to Settings > GitHub Theme Sync to configure

## Configuration

### Required Settings

1. **GitHub Repository**: Enter the repository in format `username/repository`
2. **Target Theme**: Select which WordPress theme to synchronize

### Optional Settings

1. **GitHub Personal Access Token**: Required for private repositories or to avoid rate limits
   - See [Token Permissions](#token-permissions) section for required scopes
2. **Auto Sync**: Enable/disable automatic daily synchronization

## How It Works

1. **Release Detection**: Fetches the latest release from the specified GitHub repository
2. **MD5 Comparison**: Calculates MD5 hash of current theme files and compares with downloaded release
3. **Smart Updates**: Only updates if changes are detected
4. **Safe Installation**: Creates backups before applying updates
5. **Error Handling**: Comprehensive error handling and logging

## GitHub Repository Setup

Your GitHub repository should:

1. Use GitHub Releases to publish theme versions
2. Include a zip file in each release containing the theme files
3. Have proper theme structure (style.css, index.php, etc.)

## Token Permissions

### For Public Repositories

For public repositories, you can use the plugin without a token, but GitHub API has rate limits (60 requests per hour). To avoid rate limits, create a token with minimal permissions:

**Required Scopes:**
- No specific scopes needed for public repositories (you can create a token with no scopes selected)

### For Private Repositories  

For private repositories, you need a token with appropriate permissions:

**Required Scopes:**
- `repo` - Full control of private repositories
  - OR alternatively, use the more restrictive scopes:
  - `metadata:read` - Read repository metadata
  - `contents:read` - Read repository contents and releases

### Fine-grained Personal Access Tokens (Recommended)

If your organization uses fine-grained tokens, set these permissions:

**Repository Permissions:**
- `Contents`: Read access
- `Metadata`: Read access  
- `Actions`: Read access (if releases are created via GitHub Actions)

**Account Permissions:**
- None required

### Creating a GitHub Token

1. Go to GitHub Settings → Developer settings → Personal access tokens
2. For classic tokens: Click "Generate new token (classic)"
3. For fine-grained tokens: Click "Generate new token" under "Fine-grained tokens"
4. Set expiration (recommend 1 year maximum for security)
5. Select the minimum required scopes as listed above
6. Copy the token immediately (you won't see it again)

### Token Security Best Practices

- **Use fine-grained tokens** when possible for better security
- **Set shortest reasonable expiration** (3-12 months)
- **Use separate tokens** for different applications
- **Regularly rotate tokens** before expiration
- **Monitor token usage** in GitHub settings
- **Revoke unused tokens** immediately

### Example Token Setup Commands

```bash
# Test your token (replace YOUR_TOKEN and USER/REPO)
curl -H "Authorization: token YOUR_TOKEN" \
     "https://api.github.com/repos/USER/REPO/releases/latest"

# Check rate limit status
curl -H "Authorization: token YOUR_TOKEN" \
     "https://api.github.com/rate_limit"
```

## WP-Cron Setup

The plugin automatically schedules a daily cron job. If your site has WP-Cron disabled, you can set up a system cron job:

```bash
# Add to your crontab to run daily at 2 AM
0 2 * * * wget -q -O - "https://yoursite.com/wp-cron.php?doing_wp_cron" >/dev/null 2>&1
```

## Security Considerations

- **Token Security**: 
  - Store GitHub tokens securely (the plugin stores them in WordPress options)
  - Use tokens with minimal required permissions (see [Token Permissions](#token-permissions))
  - Set reasonable expiration dates and rotate tokens regularly
  - Never commit tokens to version control
- **Repository Access**: Use private repositories for proprietary themes
- **Testing**: Always test on staging sites before production use
- **Monitoring**: Monitor sync logs and GitHub API usage for any issues
- **Backups**: The plugin creates backups, but maintain separate backup strategy

## Troubleshooting

### Common Issues

1. **"Failed to fetch release data"**
   - Check repository name format
   - Verify repository exists and is accessible
   - For private repos, ensure valid token is provided

2. **"No zip file found in release"**
   - Ensure your GitHub release includes a zip file
   - Check that the release assets are properly uploaded

3. **"Failed to extract zip file"**
   - Verify zip file format and contents
   - Check file permissions on theme directory

4. **"API rate limit exceeded"**
   - Add a GitHub Personal Access Token to increase rate limits
   - Wait for rate limit to reset (usually 1 hour for unauthenticated requests)

5. **"Invalid GitHub token"**
   - Verify token is not expired
   - Check token has correct permissions (see [Token Permissions](#token-permissions))
   - Ensure token format is correct (no extra spaces)

### Debug Information

The admin page includes debug information showing:
- WordPress and PHP versions
- Plugin version
- WP-Cron status
- Current settings
- File paths

## Development

### File Structure

```
wp-github-theme-rsync/
├── wp-github-theme-rsync.php  # Main plugin file
├── admin-page.php             # Admin interface
├── admin.js                   # Admin JavaScript
└── README.md                  # This file
```

### Hooks and Filters

The plugin provides hooks for customization:

```php
// Custom actions
do_action('wp_github_theme_rsync_before_sync', $theme_name);
do_action('wp_github_theme_rsync_after_sync', $theme_name, $success);

// Custom filters
$download_url = apply_filters('wp_github_theme_rsync_download_url', $download_url, $release_data);
$theme_md5 = apply_filters('wp_github_theme_rsync_theme_md5', $theme_md5, $theme_name);
```

## Changelog

### 1.0.0
- Initial release
- Basic GitHub sync functionality
- MD5 comparison
- Admin interface
- WP-Cron integration

## License

GPL v2 or later

## Support

For support and bug reports, please create an issue in the GitHub repository.
