/**
 * WPForms SWPM Bridge - Admin JavaScript
 */
(function($) {
    'use strict';

    // Debug logging helper
    function log() {
        if (typeof swpm_wpforms !== 'undefined' && swpm_wpforms.debug) {
            console.log.apply(console, ['SWPM:'].concat(Array.prototype.slice.call(arguments)));
        }
    }

    var SwpmWpformsAdmin = {
        
        init: function() {
            this.bindEvents();
            this.toggleIntegrationSettings();
            this.updateActionVisibility();
        },
        
        bindEvents: function() {
            $(document).on('change', '#swpm-integration-enabled', this.toggleIntegrationSettings);
            $(document).on('change', '#swpm-action-type', this.updateActionVisibility);
            $(document).on('wpformsFieldAdd wpformsFieldDelete wpformsFieldUpdate wpformsSaved', this.refreshFieldMapping);
            $(document).on('change', '.swpm-field-select', this.toggleCustomFieldInput);
            $(document).on('change', '#swpm-show-field-ids', this.toggleFieldIds);
            $(document).on('change', '#swpm-field-sort', this.handleSortChange);
        },
        
        toggleIntegrationSettings: function() {
            var enabled = $('#swpm-integration-enabled').is(':checked');
            $('#swpm-integration-settings').toggle(enabled);
        },
        
        updateActionVisibility: function() {
            var actionType = $('#swpm-action-type').val();
            var $section = $('.wpforms-panel-content-section-swpm_integration');
            
            $section.attr('data-action-type', actionType);
            
            if (actionType === 'register_member') {
                $('.swpm-register-only').show();
            } else {
                $('.swpm-register-only').hide();
            }
        },
        
        refreshFieldMapping: function() {
            var $mappingTable = $('#swpm-field-mapping');
            if (!$mappingTable.length) {
                return;
            }
            
            // Remember show field IDs state
            var showFieldIds = $('#swpm-show-field-ids').is(':checked');
            
            // Remember current mapping selections
            var currentMappings = {};
            $mappingTable.find('.swpm-field-select').each(function() {
                var $select = $(this);
                var name = $select.attr('name');
                var match = name ? name.match(/\[field_map\]\[([^\]]+)\]/) : null;
                if (match) {
                    currentMappings[match[1]] = $select.val();
                }
            });
            
            // Remember custom field inputs
            var customInputs = {};
            $mappingTable.find('.swpm-custom-field-input').each(function() {
                var $input = $(this);
                var name = $input.attr('name');
                var match = name ? name.match(/\[field_map_custom\]\[([^\]]+)\]/) : null;
                if (match) {
                    customInputs[match[1]] = $input.val();
                }
            });
            
            // Build fields from current builder DOM
            var fields = {};
            $('#wpforms-panel-fields .wpforms-field').each(function() {
                var $field = $(this);
                var fieldId = $field.data('field-id');
                var fieldType = $field.data('field-type');
                var fieldLabel = $field.find('.label-title .text').text() || 
                                 $field.find('.wpforms-field-option-row-label input').val() ||
                                 'Field ' + fieldId;
                
                if (fieldId !== undefined && fieldId !== '') {
                    fields[fieldId] = {
                        id: String(fieldId),
                        type: fieldType || 'text',
                        label: $.trim(fieldLabel)
                    };
                }
            });
            
            var fieldCount = Object.keys(fields).length;
            log('Found ' + fieldCount + ' fields in builder');
            
            if (fieldCount === 0) {
                return;
            }
            
            var formData = { fields: fields };
            
            // Get form ID to load saved config
            var formId = null;
            if (typeof wpforms_builder !== 'undefined' && wpforms_builder.form_id) {
                formId = wpforms_builder.form_id;
            }
            if (!formId) {
                var urlParams = new URLSearchParams(window.location.search);
                formId = urlParams.get('form_id');
            }
            
            // Show loading
            $mappingTable.css('opacity', '0.5');
            
            // Get nonce
            var nonce = '';
            if (typeof wpforms_builder !== 'undefined') {
                nonce = wpforms_builder.nonce || '';
            }
            
            $.post(ajaxurl, {
                action: 'swpm_wpforms_refresh_mapping',
                nonce: nonce,
                form_data: JSON.stringify(formData),
                form_id: formId
            }, function(response) {
                $mappingTable.css('opacity', '1');
                if (response.success && response.data.html) {
                    $mappingTable.html(response.data.html);
                    
                    // Restore show field IDs state
                    if (showFieldIds) {
                        $('#swpm-show-field-ids').prop('checked', true);
                        $('.swpm-field-id').show();
                    }
                    
                    // Restore mapping selections
                    $mappingTable.find('.swpm-field-select').each(function() {
                        var $select = $(this);
                        var name = $select.attr('name');
                        var match = name ? name.match(/\[field_map\]\[([^\]]+)\]/) : null;
                        if (match && currentMappings[match[1]]) {
                            $select.val(currentMappings[match[1]]);
                        }
                    });
                    
                    // Restore custom field inputs
                    $mappingTable.find('.swpm-custom-field-input').each(function() {
                        var $input = $(this);
                        var name = $input.attr('name');
                        var match = name ? name.match(/\[field_map_custom\]\[([^\]]+)\]/) : null;
                        if (match && customInputs[match[1]]) {
                            $input.val(customInputs[match[1]]);
                        }
                    });
                    
                    SwpmWpformsAdmin.initCustomFieldInputs();
                    log('Mapping refreshed');
                } else {
                    log('Refresh failed', response);
                }
            }).fail(function(xhr, status, error) {
                $mappingTable.css('opacity', '1');
                log('AJAX failed', error);
            });
        },
        
        toggleCustomFieldInput: function() {
            var $select = $(this);
            var $input = $select.siblings('.swpm-custom-field-input');
            
            if ($select.val() === 'custom_') {
                $input.show().focus();
            } else {
                $input.hide().val('');
            }
        },
        
        initCustomFieldInputs: function() {
            $('.swpm-field-select').each(function() {
                var $select = $(this);
                var $input = $select.siblings('.swpm-custom-field-input');
                
                if ($select.val() === 'custom_' || ($select.val() && $select.val().indexOf('custom_') === 0)) {
                    $input.show();
                } else {
                    $input.hide();
                }
            });
        },
        
        toggleFieldIds: function() {
            var show = $('#swpm-show-field-ids').is(':checked');
            $('.swpm-field-id').toggle(show);
        },
        
        handleSortChange: function() {
            var sortBy = $('#swpm-field-sort').val();
            var $tbody = $('#swpm-field-mapping table tbody');
            
            if (!$tbody.length) return;
            
            var $rows = $tbody.find('tr').get();
            
            $rows.sort(function(a, b) {
                var $a = $(a);
                var $b = $(b);
                
                if (sortBy === 'id') {
                    var idA = $a.find('.swpm-field-id code').text() || '0';
                    var idB = $b.find('.swpm-field-id code').text() || '0';
                    // Extract numeric part for proper sorting
                    var numA = parseInt(idA.replace(/\D/g, '')) || 0;
                    var numB = parseInt(idB.replace(/\D/g, '')) || 0;
                    return numA - numB;
                } else if (sortBy === 'label') {
                    var labelA = $a.find('td:first').text().toLowerCase();
                    var labelB = $b.find('td:first').text().toLowerCase();
                    return labelA.localeCompare(labelB);
                } else if (sortBy === 'form') {
                    // Form order - use data attribute if available
                    var orderA = $a.data('form-order') || 0;
                    var orderB = $b.data('form-order') || 0;
                    return orderA - orderB;
                }
                return 0;
            });
            
            $.each($rows, function(idx, row) {
                $tbody.append(row);
            });
        }
    };

    $(document).ready(function() {
        SwpmWpformsAdmin.init();
        SwpmWpformsAdmin.initCustomFieldInputs();
    });

    $(document).on('wpformsReady', function() {
        SwpmWpformsAdmin.init();
        SwpmWpformsAdmin.initCustomFieldInputs();
    });

})(jQuery);