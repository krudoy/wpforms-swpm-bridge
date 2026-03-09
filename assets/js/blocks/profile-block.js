(function(wp) {
    const { registerBlockType } = wp.blocks;
    const { useBlockProps, InspectorControls } = wp.blockEditor;
    const { PanelBody, SelectControl, TextControl, TextareaControl, Placeholder, CheckboxControl, ToggleControl } = wp.components;
    const { createElement: el, Fragment } = wp.element;
    const { __ } = wp.i18n;
    const ServerSideRender = wp.serverSideRender;
    
    const config = window.swpmWpformsProfile || { forms: [], layouts: [], labels: {} };
    const labels = config.labels;
    
    registerBlockType('swpm-wpforms/profile', {
        title: labels.blockTitle || 'SWPM Profile',
        description: labels.blockDescription || 'Display member profile data.',
        category: 'widgets',
        icon: 'id-alt',
        keywords: ['profile', 'member', 'swpm', 'wpforms'],
        supports: { html: false, className: true },
        
        edit: function(props) {
            const { attributes, setAttributes } = props;
            const { formId, memberId, username, layout, hiddenFields, showEmptyFields, emptyText, showBorder, passwordNotice, customContent } = attributes;
            const blockProps = useBlockProps();
            
            const currentHiddenFields = Array.isArray(hiddenFields) ? hiddenFields : [];
            
            const formOptions = [
                { label: '-- ' + (labels.selectForm || 'Select Form') + ' --', value: '' }
            ].concat(
                config.forms.map(function(form) {
                    return { label: form.title, value: form.id };
                })
            );
            
            const layoutOptions = config.layouts || [
                { value: 'wpforms', label: 'WPForms Style' },
                { value: 'table', label: 'Table' },
                { value: 'list', label: 'Definition List' },
                { value: 'inline', label: 'Inline' }
            ];
            
            const passwordOptions = [
                { value: 'auto', label: labels.auto || 'Auto (detect)' },
                { value: 'yes', label: labels.yes || 'Always show' },
                { value: 'no', label: labels.no || 'Never show' }
            ];
            
            // Get selected form data
            var selectedForm = config.forms.find(function(f) { return f.id === formId; });
            var formFields = selectedForm && selectedForm.fields ? selectedForm.fields : [];
            var hasPasswordField = selectedForm && selectedForm.hasPassword;
            
            // Count visible fields
            var visibleCount = formFields.filter(function(f) {
                return currentHiddenFields.indexOf(f.id) === -1;
            }).length;
            
            // Toggle field visibility by field ID
            function toggleField(fieldId, isVisible) {
                var newHidden;
                if (isVisible) {
                    newHidden = currentHiddenFields.filter(function(id) { return id !== fieldId; });
                } else {
                    newHidden = currentHiddenFields.concat([fieldId]);
                }
                setAttributes({ hiddenFields: newHidden });
            }
            
            // Build checkboxes using WPForms field labels
            var fieldCheckboxes = [];
            if (formFields.length > 0) {
                formFields.forEach(function(field) {
                    var isChecked = currentHiddenFields.indexOf(field.id) === -1;
                    fieldCheckboxes.push(
                        el(CheckboxControl, {
                            key: field.id,
                            label: field.label,
                            checked: isChecked,
                            onChange: function(checked) { toggleField(field.id, checked); }
                        })
                    );
                });
            }
            
            const inspectorControls = el(
                InspectorControls,
                null,
                el(
                    PanelBody,
                    { title: labels.blockTitle || 'SWPM Profile', initialOpen: true },
                    el(SelectControl, {
                        label: labels.selectForm || 'Select Form',
                        help: labels.selectFormHelp,
                        value: formId,
                        options: formOptions,
                        onChange: function(value) { 
                            setAttributes({ formId: value, hiddenFields: [] }); 
                        }
                    }),
                    el(SelectControl, {
                        label: labels.layout || 'Layout Style',
                        help: labels.layoutHelp,
                        value: layout || 'wpforms',
                        options: layoutOptions,
                        onChange: function(value) { setAttributes({ layout: value }); }
                    })
                ),
                formId && formFields.length > 0 ? el(
                    PanelBody,
                    { title: labels.fieldVisibility || 'Field Visibility', initialOpen: false },
                    el('p', { 
                        className: 'components-base-control__help', 
                        style: { marginTop: 0, marginBottom: '12px' } 
                    }, labels.fieldVisibilityHelp),
                    el('div', { className: 'swpm-field-checkboxes' }, fieldCheckboxes),
                    visibleCount === 0 ? el('p', { 
                        style: { color: '#d63638', fontStyle: 'italic', marginTop: '8px' }
                    }, labels.allFieldsHidden) : null
                ) : null,
                formId ? el(
                    PanelBody,
                    { title: 'Empty Fields', initialOpen: false },
                    el(ToggleControl, {
                        label: labels.showEmptyFields || 'Show Empty Fields',
                        help: labels.showEmptyFieldsHelp,
                        checked: showEmptyFields || false,
                        onChange: function(value) { setAttributes({ showEmptyFields: value }); }
                    }),
                    showEmptyFields ? el(TextControl, {
                        label: labels.emptyText || 'Empty Field Text',
                        help: labels.emptyTextHelp,
                        value: emptyText || '\u2014',
                        onChange: function(value) { setAttributes({ emptyText: value }); }
                    }) : null,
                    el(ToggleControl, {
                        label: labels.showBorder || 'Show Border on Values',
                        help: labels.showBorderHelp || 'Display a visible border around field values',
                        checked: showBorder || false,
                        onChange: function(value) { setAttributes({ showBorder: value }); }
                    })
                ) : null,
                formId && hasPasswordField ? el(
                    PanelBody,
                    { title: labels.passwordNotice || 'Password Notice', initialOpen: false },
                    el(SelectControl, {
                        label: labels.passwordNotice,
                        help: 'This form has a password field mapped.',
                        value: passwordNotice || 'auto',
                        options: passwordOptions,
                        onChange: function(value) { setAttributes({ passwordNotice: value }); }
                    })
                ) : null,
                el(
                    PanelBody,
                    { title: 'Advanced', initialOpen: false },
                    el(TextControl, {
                        label: labels.memberId,
                        help: labels.memberIdHelp,
                        value: memberId,
                        onChange: function(value) { setAttributes({ memberId: value }); }
                    }),
                    el(TextControl, {
                        label: labels.username,
                        help: labels.usernameHelp,
                        value: username,
                        onChange: function(value) { setAttributes({ username: value }); }
                    }),
                    el(TextareaControl, {
                        label: labels.customContent,
                        help: labels.customContentHelp,
                        value: customContent,
                        onChange: function(value) { setAttributes({ customContent: value }); }
                    })
                )
            );
            
            var previewContent;
            
            if (config.forms.length === 0) {
                previewContent = el(
                    Placeholder,
                    { icon: 'warning', label: labels.blockTitle || 'SWPM Profile' },
                    labels.noForms || 'No WPForms with SWPM integration found.'
                );
            } else if (!formId && !customContent) {
                previewContent = el(
                    Placeholder,
                    { icon: 'id-alt', label: labels.blockTitle || 'SWPM Profile' },
                    labels.selectFormHelp || 'Select a form.'
                );
            } else {
                // Live preview using ServerSideRender
                previewContent = el(
                    'div',
                    { className: 'swpm-profile-editor-preview' },
                    el('div', { className: 'swpm-profile-editor-preview-header' },
                        el('span', { className: 'dashicons dashicons-visibility' }),
                        ' ',
                        labels.livePreview || 'Live Preview'
                    ),
                    el(ServerSideRender, {
                        block: 'swpm-wpforms/profile',
                        attributes: Object.assign({}, attributes, { editorPreview: true }),
                        EmptyResponsePlaceholder: function() {
                            return el('p', { className: 'swpm-profile-editor-empty' }, 
                                labels.notLoggedIn || 'Log in as a SWPM member to see preview.'
                            );
                        }
                    })
                );
            }
            
            return el(
                Fragment,
                null,
                inspectorControls,
                el('div', blockProps, previewContent)
            );
        },
        
        save: function() {
            return null;
        }
    });
})(window.wp);
