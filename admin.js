jQuery(document).ready(function($) {
    $('#manual-sync-btn').on('click', function() {
        var $button = $(this);
        var $result = $('#sync-result');
        
        // Show loading state
        $button.prop('disabled', true);
        $button.find('.sync-text').hide();
        $button.find('.sync-loading').show();
        $result.empty();
        
        // Make AJAX request
        $.ajax({
            url: wpGithubThemeRsync.ajax_url,
            type: 'POST',
            data: {
                action: 'manual_theme_sync',
                nonce: wpGithubThemeRsync.nonce
            },
            success: function(response) {
                var messageClass = response.success ? 'notice-success' : 'notice-error';
                var message = response.message || 'Unknown error occurred';
                
                $result.html('<div class="notice ' + messageClass + ' inline"><p>' + message + '</p></div>');
                
                // If sync was successful and not a "no update" case, reload the page to show updated info
                if (response.success && !response.no_update) {
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                }
            },
            error: function(xhr, status, error) {
                $result.html('<div class="notice notice-error inline"><p>AJAX Error: ' + error + '</p></div>');
            },
            complete: function() {
                // Reset button state
                $button.prop('disabled', false);
                $button.find('.sync-text').show();
                $button.find('.sync-loading').hide();
            }
        });
    });
    
    // Auto-refresh current MD5 when target theme changes
    $('#target_theme').on('change', function() {
        var themeName = $(this).val();
        if (themeName) {
            // In a real implementation, you might want to AJAX this
            // For now, just show that it will update on save
            $('#current-md5').html('<em>Will update after saving settings</em>');
        }
    });
    
    // Show/hide GitHub token visibility
    var $tokenField = $('#github_token');
    var $toggleButton = $('<button type="button" class="button" style="margin-left: 5px;">Show</button>');
    
    $tokenField.after($toggleButton);
    
    $toggleButton.on('click', function() {
        if ($tokenField.attr('type') === 'password') {
            $tokenField.attr('type', 'text');
            $(this).text('Hide');
        } else {
            $tokenField.attr('type', 'password');
            $(this).text('Show');
        }
    });
    
    // Form validation
    $('form').on('submit', function(e) {
        var repoValue = $('#github_repo').val().trim();
        var themeValue = $('#target_theme').val();
        
        if (repoValue && !repoValue.match(/^[a-zA-Z0-9\-_\.]+\/[a-zA-Z0-9\-_\.]+$/)) {
            alert('Please enter a valid GitHub repository format (username/repository)');
            e.preventDefault();
            return false;
        }
        
        if (!themeValue) {
            alert('Please select a target theme');
            e.preventDefault();
            return false;
        }
    });
    
    // Auto-save functionality (optional)
    var autoSaveTimeout;
    $('input, select').on('change', function() {
        clearTimeout(autoSaveTimeout);
        autoSaveTimeout = setTimeout(function() {
            // You could implement auto-save here if needed
            console.log('Auto-save triggered');
        }, 2000);
    });
});
