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
                var html = '<div class="notice ' + messageClass + ' inline"><p>' + $('<div/>').text(message).html() + '</p></div>';
                
                if (response.sync_debug && typeof response.sync_debug === 'object') {
                    var dbg = JSON.stringify(response.sync_debug, null, 2);
                    html += '<details class="wpgtr-sync-debug" style="margin-top:12px;"><summary style="cursor:pointer;">Technical details (for support / debugging)</summary>';
                    html += '<pre style="margin-top:8px;max-height:320px;overflow:auto;background:#f6f7f7;border:1px solid #c3c4c7;padding:10px;font-size:12px;line-height:1.4;">';
                    html += $('<div/>').text(dbg).html();
                    html += '</pre></details>';
                }
                
                $result.html(html);
                
                if (response.success && !response.no_update) {
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                }
            },
            error: function(xhr, status, error) {
                var errHtml = '<div class="notice notice-error inline"><p>AJAX Error: ' + $('<div/>').text(error).html() + '</p></div>';
                if (xhr.responseJSON && xhr.responseJSON.sync_debug) {
                    errHtml += '<details style="margin-top:12px;"><summary style="cursor:pointer;">Technical details</summary><pre style="margin-top:8px;max-height:240px;overflow:auto;background:#f6f7f7;padding:10px;font-size:12px;">';
                    errHtml += $('<div/>').text(JSON.stringify(xhr.responseJSON.sync_debug, null, 2)).html();
                    errHtml += '</pre></details>';
                }
                $result.html(errHtml);
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
    
});
