/**
 * Facebook Bot Detector Admin JavaScript
 */

jQuery(document).ready(function($) {
    'use strict';
    
    // Auto-refresh stats every 30 seconds on dashboard
    if ($('.fbd-stats-grid').length > 0) {
        setInterval(refreshStats, 30000);
    }
    
    // Handle bulk actions
    initBulkActions();
    
    // Handle export functionality
    initExportFunctionality();
    
    // Handle settings validation
    initSettingsValidation();
    
    // Handle real-time search/filtering
    initRealTimeFiltering();
    
    /**
     * Refresh statistics
     */
    function refreshStats() {
        $.post(ajaxurl, {
            action: 'fbd_refresh_stats',
            nonce: fbd_ajax.nonce
        }, function(response) {
            if (response.success) {
                updateStatsDisplay(response.data);
            }
        });
    }
    
    /**
     * Update stats display
     */
    function updateStatsDisplay(stats) {
        $('.fbd-stat-card').each(function() {
            var $card = $(this);
            var $number = $card.find('.fbd-stat-number');
            var title = $card.find('h3').text().trim();
            
            switch(title) {
                case 'TOTAL DETECTIONS':
                    $number.text(numberFormat(stats.total_detections));
                    break;
                case 'VERIFIED BOTS':
                    $number.text(numberFormat(stats.verified_detections));
                    break;
                case 'UNIQUE IPS':
                    $number.text(numberFormat(stats.unique_ips));
                    break;
                case 'TODAY':
                    $number.text(numberFormat(stats.today_detections));
                    break;
                case 'THIS WEEK':
                    $number.text(numberFormat(stats.this_week));
                    break;
            }
        });
    }
    
    /**
     * Initialize bulk actions
     */
    function initBulkActions() {
        // Select all checkbox
        $('#cb-select-all-1, #cb-select-all-2').change(function() {
            var checked = this.checked;
            $('input[name="log_ids[]"]').prop('checked', checked);
            updateBulkActionButtons();
        });
        
        // Individual checkboxes
        $(document).on('change', 'input[name="log_ids[]"]', function() {
            updateBulkActionButtons();
            updateSelectAllCheckboxes();
        });
        
        // Bulk delete
        $('#fbd-bulk-delete').click(function() {
            var selected = getSelectedLogIds();
            
            if (selected.length === 0) {
                alert('Please select logs to delete.');
                return;
            }
            
            if (confirm('Are you sure you want to delete ' + selected.length + ' log entries? This action cannot be undone.')) {
                bulkDeleteLogs(selected);
            }
        });
        
        // Bulk export
        $('#fbd-export-selected').click(function() {
            var selected = getSelectedLogIds();
            
            if (selected.length === 0) {
                alert('Please select logs to export.');
                return;
            }
            
            bulkExportLogs(selected);
        });
    }
    
    /**
     * Initialize export functionality
     */
    function initExportFunctionality() {
        // Preview export
        $('#fbd-preview-export').click(function() {
            var $button = $(this);
            var formData = $('#fbd-export-form').serialize();
            
            $button.prop('disabled', true).text('Generating Preview...');
            
            formData += '&action=fbd_preview_export&nonce=' + fbd_ajax.nonce;
            
            $.post(ajaxurl, formData, function(response) {
                if (response.success) {
                    $('#fbd-preview-content').html(response.data);
                    $('#fbd-export-preview').slideDown();
                } else {
                    alert('Error generating preview: ' + (response.data || 'Unknown error'));
                }
            }).always(function() {
                $button.prop('disabled', false).text('Preview Export');
            });
        });
        
        // Export format change
        $('input[name="export_format"]').change(function() {
            var format = $(this).val();
            var $preview = $('#fbd-export-preview');
            
            if ($preview.is(':visible')) {
                $('#fbd-preview-export').click();
            }
        });
    }
    
    /**
     * Initialize settings validation
     */
    function initSettingsValidation() {
        // Range input synchronization
        $('input[type="range"]').on('input', function() {
            $(this).next('output').text(this.value);
        });
        
        // Validate IP addresses in whitelist/blacklist
        $('textarea[name="fbd_ip_whitelist"], textarea[name="fbd_ip_blacklist"]').on('blur', function() {
            validateIpList($(this));
        });
        
        // Validate frequency settings
        $('input[name="fbd_frequency_threshold"], input[name="fbd_frequency_window"]').on('change', function() {
            validateFrequencySettings();
        });
    }
    
    /**
     * Initialize real-time filtering
     */
    function initRealTimeFiltering() {
        var filterTimeout;
        
        $('.fbd-filter-form input, .fbd-filter-form select').on('input change', function() {
            clearTimeout(filterTimeout);
            filterTimeout = setTimeout(function() {
                // Auto-submit form after 500ms delay
                if ($('#fbd-auto-filter').is(':checked')) {
                    $('.fbd-filter-form').submit();
                }
            }, 500);
        });
        
        // Add auto-filter checkbox
        if ($('.fbd-filter-form').length > 0 && !$('#fbd-auto-filter').length) {
            $('.fbd-filter-form .fbd-filter-row').append(
                '<label><input type="checkbox" id="fbd-auto-filter" /> Auto-filter</label>'
            );
        }
    }
    
    /**
     * Get selected log IDs
     */
    function getSelectedLogIds() {
        return $('input[name="log_ids[]"]:checked').map(function() {
            return parseInt(this.value);
        }).get();
    }
    
    /**
     * Update bulk action buttons
     */
    function updateBulkActionButtons() {
        var selectedCount = $('input[name="log_ids[]"]:checked').length;
        var hasSelection = selectedCount > 0;
        
        $('#fbd-bulk-delete, #fbd-export-selected').prop('disabled', !hasSelection);
        
        if (hasSelection) {
            $('#fbd-bulk-delete').text('Delete Selected (' + selectedCount + ')');
            $('#fbd-export-selected').text('Export Selected (' + selectedCount + ')');
        } else {
            $('#fbd-bulk-delete').text('Delete Selected');
            $('#fbd-export-selected').text('Export Selected');
        }
    }
    
    /**
     * Update select all checkboxes
     */
    function updateSelectAllCheckboxes() {
        var $checkboxes = $('input[name="log_ids[]"]');
        var $selectAll = $('#cb-select-all-1, #cb-select-all-2');
        var totalCount = $checkboxes.length;
        var checkedCount = $checkboxes.filter(':checked').length;
        
        if (checkedCount === 0) {
            $selectAll.prop('indeterminate', false).prop('checked', false);
        } else if (checkedCount === totalCount) {
            $selectAll.prop('indeterminate', false).prop('checked', true);
        } else {
            $selectAll.prop('indeterminate', true).prop('checked', false);
        }
    }
    
    /**
     * Bulk delete logs
     */
    function bulkDeleteLogs(logIds) {
        var $button = $('#fbd-bulk-delete');
        $button.prop('disabled', true).text('Deleting...');
        
        $.post(ajaxurl, {
            action: 'fbd_delete_logs',
            log_ids: logIds,
            nonce: fbd_ajax.nonce
        }, function(response) {
            if (response.success) {
                location.reload();
            } else {
                alert('Error deleting logs: ' + (response.data || 'Unknown error'));
                $button.prop('disabled', false);
                updateBulkActionButtons();
            }
        }).fail(function() {
            alert('Network error while deleting logs.');
            $button.prop('disabled', false);
            updateBulkActionButtons();
        });
    }
    
    /**
     * Bulk export logs
     */
    function bulkExportLogs(logIds) {
        var format = $('input[name="export_format"]:checked').val() || 'csv';
        var form = $('<form>', {
            method: 'post',
            action: ajaxurl,
            style: 'display: none;'
        });
        
        form.append($('<input>', {
            name: 'action',
            value: 'fbd_export_logs'
        }));
        
        form.append($('<input>', {
            name: 'nonce',
            value: fbd_ajax.nonce
        }));
        
        form.append($('<input>', {
            name: 'format',
            value: format
        }));
        
        form.append($('<input>', {
            name: 'log_ids',
            value: logIds.join(',')
        }));
        
        $('body').append(form);
        form.submit();
        form.remove();
    }
    
    /**
     * Validate IP address list
     */
    function validateIpList($textarea) {
        var ips = $textarea.val().split('\n');
        var invalidIps = [];
        
        ips.forEach(function(ip) {
            ip = ip.trim();
            if (ip && !isValidIpAddress(ip)) {
                invalidIps.push(ip);
            }
        });
        
        if (invalidIps.length > 0) {
            alert('Invalid IP addresses found:\n' + invalidIps.join('\n'));
            $textarea.focus();
        }
    }
    
    /**
     * Validate frequency settings
     */
    function validateFrequencySettings() {
        var threshold = parseInt($('input[name="fbd_frequency_threshold"]').val());
        var window = parseInt($('input[name="fbd_frequency_window"]').val());
        
        if (threshold > 0 && window > 0) {
            var rate = threshold / (window / 60); // requests per minute
            var $warning = $('#fbd-frequency-warning');
            
            if (rate > 10) { // More than 10 requests per minute
                if ($warning.length === 0) {
                    $warning = $('<div id="fbd-frequency-warning" class="notice notice-warning inline"><p>Warning: This configuration may generate many false positives for normal users.</p></div>');
                    $('input[name="fbd_frequency_window"]').closest('tr').after($('<tr><td colspan="2"></td></tr>').find('td').append($warning).end());
                }
            } else {
                $warning.remove();
            }
        }
    }
    
    /**
     * Check if IP address is valid
     */
    function isValidIpAddress(ip) {
        var ipv4Regex = /^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/;
        var ipv6Regex = /^(?:[0-9a-fA-F]{1,4}:){7}[0-9a-fA-F]{1,4}$/;
        var cidrRegex = /^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\/([0-9]|[1-2][0-9]|3[0-2])$/;
        
        return ipv4Regex.test(ip) || ipv6Regex.test(ip) || cidrRegex.test(ip);
    }
    
    /**
     * Format numbers with commas
     */
    function numberFormat(num) {
        return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    }
    
    /**
     * Copy to clipboard functionality
     */
    function copyToClipboard(text) {
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(function() {
                showNotice('Copied to clipboard!', 'success');
            });
        } else {
            // Fallback for older browsers
            var $temp = $('<textarea>');
            $('body').append($temp);
            $temp.val(text).select();
            document.execCommand('copy');
            $temp.remove();
            showNotice('Copied to clipboard!', 'success');
        }
    }
    
    /**
     * Show temporary notice
     */
    function showNotice(message, type) {
        var $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
        $('.wrap > h1').after($notice);
        
        setTimeout(function() {
            $notice.fadeOut(function() {
                $(this).remove();
            });
        }, 3000);
    }
    
    // Add copy buttons to IP addresses
    $(document).on('click', '.fbd-copy-ip', function(e) {
        e.preventDefault();
        var ip = $(this).data('ip');
        copyToClipboard(ip);
    });
    
    // Add click handlers for IP addresses to show copy option
    $(document).on('contextmenu', 'td strong', function(e) {
        var text = $(this).text();
        if (isValidIpAddress(text)) {
            e.preventDefault();
            if (confirm('Copy IP address "' + text + '" to clipboard?')) {
                copyToClipboard(text);
            }
        }
    });
    
    // Keyboard shortcuts
    $(document).keydown(function(e) {
        // Ctrl+A to select all checkboxes
        if (e.ctrlKey && e.key === 'a' && $('input[name="log_ids[]"]').length > 0) {
            e.preventDefault();
            $('#cb-select-all-1').prop('checked', true).trigger('change');
        }
        
        // Delete key to delete selected items
        if (e.key === 'Delete' && getSelectedLogIds().length > 0) {
            e.preventDefault();
            $('#fbd-bulk-delete').click();
        }
        
        // Ctrl+E to export selected items
        if (e.ctrlKey && e.key === 'e' && getSelectedLogIds().length > 0) {
            e.preventDefault();
            $('#fbd-export-selected').click();
        }
    });
});