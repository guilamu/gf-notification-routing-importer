/**
 * GF Notification Routing Importer — Admin JavaScript
 *
 * Injects the import UI into the GF notification routing section and
 * handles file upload + integration with GF's native routing JS.
 *
 * @package GF_Notification_Routing_Importer
 */

( function( $ ) {
    'use strict';

    var settings = window.gfnriSettings || {};
    var strings  = settings.strings || {};

    /**
     * Build and inject the import UI after the routing rules container.
     */
    function injectImportUI() {
        var $routingRules = $( '#gform_notification_to_routing_rules' );

        if ( ! $routingRules.length ) {
            return;
        }

        // Don't inject twice.
        if ( $( '#gfnri-import-container' ).length ) {
            return;
        }

        var html = '<div id="gfnri-import-container">'
            +   '<div id="gfnri-import-header">'
            +     '<span class="gfnri-title">' + escHtml( strings.importTitle || 'Import Routing Rules' ) + '</span>'
            +     '<label class="gfnri-toggle">'
            +       '<input id="gfnri-mode-toggle" type="checkbox">'
            +       '<span class="gfnri-toggle__track"></span>'
            +       '<span class="gfnri-toggle__label">'
            +         escHtml( strings.appendLabel || 'Append to existing' )
            +       '</span>'
            +     '</label>'
            +   '</div>'
            +   '<div id="gfnri-dropzone">'
            +     '<input type="file" id="gfnri-file-input" accept=".csv,.xlsx" style="display:none">'
            +     '<span class="gfnri-drop-instructions">'
            +       escHtml( strings.dropHere || 'Drop your file here or' ) + ' '
            +     '</span>'
            +     '<input type="button" id="gfnri-upload-btn" value="'
            +       escHtml( strings.selectFile || 'Select a file' )
            +     '" class="button">'
            +   '</div>'
            +   '<div id="gfnri-sample">'
            +     escHtml( strings.samplePrefix || 'Download a sample file:' ) + ' '
            +     '<a href="' + escHtml( settings.sampleUrl || '#' ) + '" target="_blank">sample.csv</a>'
            +   '</div>'
            +   '<div id="gfnri-export">'
            +     '<input type="button" id="gfnri-export-btn" value="'
            +       escHtml( strings.exportBtn || 'Export Routing Rules' )
            +     '" class="button" disabled>'
            +   '</div>'
            +   '<span id="gfnri-status"></span>'
            +   '<div id="gfnri-warnings" style="display:none"></div>'
            + '</div>';

        $routingRules.after( html );

        // Bind events.
        $( '#gfnri-upload-btn' ).on( 'click', function( e ) {
            e.preventDefault();
            $( '#gfnri-file-input' ).val( '' ).trigger( 'click' );
        } );

        $( '#gfnri-file-input' ).on( 'change', handleFileSelect );

        // Drag and drop support.
        var $dz = $( '#gfnri-dropzone' );

        $dz.on( 'dragover dragenter', function( e ) {
            e.preventDefault();
            e.stopPropagation();
            $dz.addClass( 'gfnri-dragover' );
        } );

        $dz.on( 'dragleave drop', function( e ) {
            e.preventDefault();
            e.stopPropagation();
            $dz.removeClass( 'gfnri-dragover' );
        } );

        $dz.on( 'drop', function( e ) {
            var files = e.originalEvent.dataTransfer.files;
            if ( files && files.length ) {
                var dt  = new DataTransfer();
                dt.items.add( files[0] );
                document.getElementById( 'gfnri-file-input' ).files = dt.files;
                $( '#gfnri-file-input' ).trigger( 'change' );
            }
        } );

        // Export button.
        $( '#gfnri-export-btn' ).on( 'click', handleExport );

        // Initial visibility and export button state.
        updateVisibility();
        updateExportBtn();
    }

    /**
     * Update import container visibility based on toType radio.
     */
    function updateVisibility() {
        var $container = $( '#gfnri-import-container' );
        if ( ! $container.length ) {
            return;
        }

        // Find the toType radio — GF uses a name like "_gform_setting_toType".
        var $checked = $( 'input[name$="_toType"]:checked' );
        var isRouting = $checked.length && $checked.val() === 'routing';

        $container.toggle( isRouting );
    }

    /**
     * Handle file selection and upload via AJAX.
     */
    function handleFileSelect() {
        var fileInput = document.getElementById( 'gfnri-file-input' );

        if ( ! fileInput || ! fileInput.files || ! fileInput.files.length ) {
            return;
        }

        var file = fileInput.files[0];
        var ext  = file.name.split( '.' ).pop().toLowerCase();

        if ( ext !== 'csv' && ext !== 'xlsx' ) {
            setStatus( strings.noFile || 'Please select a CSV or XLSX file.', 'error' );
            return;
        }

        var appendMode = $( '#gfnri-mode-toggle' ).is( ':checked' );

        setStatus( strings.importing || 'Importing…', 'loading' );
        $( '#gfnri-warnings' ).hide().empty();

        var formData = new FormData();
        formData.append( 'action', 'gfnri_import_routing' );
        formData.append( 'nonce', settings.nonce );
        formData.append( 'form_id', settings.formId );
        formData.append( 'file', file );
        formData.append( 'mode', appendMode ? 'append' : 'replace' );

        $.ajax( {
            url:         settings.ajaxurl,
            type:        'POST',
            data:        formData,
            processData: false,
            contentType: false,
            dataType:    'json',
            success: function( response ) {
                if ( response.success ) {
                    applyRouting( response.data.routing, appendMode );

                    var msg = ( strings.success || '%d routing rule(s) imported successfully.' )
                        .replace( '%d', response.data.count );
                    setStatus( '\u2705 ' + msg, 'success' );

                    // Show warnings.
                    if ( response.data.warnings && response.data.warnings.length ) {
                        showWarnings( response.data.warnings );
                    }
                } else {
                    var errMsg = response.data && response.data.message
                        ? response.data.message
                        : 'Unknown error';
                    setStatus( '\u274C ' + ( strings.error || 'Import failed: %s' ).replace( '%s', errMsg ), 'error' );

                    if ( response.data && response.data.warnings && response.data.warnings.length ) {
                        showWarnings( response.data.warnings );
                    }
                }
            },
            error: function( xhr ) {
                var errMsg = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
                    ? xhr.responseJSON.data.message
                    : 'Server error (' + xhr.status + ')';
                setStatus( '\u274C ' + ( strings.error || 'Import failed: %s' ).replace( '%s', errMsg ), 'error' );
            }
        } );
    }

    /**
     * Apply imported routing rules to GF's native routing UI.
     *
     * @param {Array}   newRouting  Array of {email, fieldId, operator, value}.
     * @param {boolean} appendMode  If true, append; otherwise replace.
     */
    function applyRouting( newRouting, appendMode ) {
        // current_notification is GF's global variable.
        if ( typeof current_notification === 'undefined' ) {
            return;
        }

        if ( appendMode && Array.isArray( current_notification.routing ) && current_notification.routing.length ) {
            // Filter out any empty placeholder rules before appending.
            var existing = current_notification.routing.filter( function( r ) {
                return r && ( r.email || r.fieldId || r.value );
            } );
            current_notification.routing = existing.concat( newRouting );
        } else {
            current_notification.routing = newRouting;
        }

        // Re-render the native routing UI using GF's own function.
        if ( typeof CreateRouting === 'function' ) {
            CreateRouting( current_notification.routing );
        }

        // Sync the hidden input that gets POSTed.
        $( '#routing' ).val( JSON.stringify( current_notification.routing ) );

        // Refresh export button state.
        updateExportBtn();
    }

    /**
     * Update the export button enabled/disabled state.
     */
    function updateExportBtn() {
        var $btn = $( '#gfnri-export-btn' );
        if ( ! $btn.length ) {
            return;
        }

        var hasRules = typeof current_notification !== 'undefined'
            && Array.isArray( current_notification.routing )
            && current_notification.routing.filter( function( r ) {
                return r && ( r.email || r.fieldId || r.value );
            } ).length > 0;

        $btn.prop( 'disabled', ! hasRules );
    }

    /**
     * Handle export: build CSV from current routing rules and trigger download.
     */
    function handleExport() {
        if ( typeof current_notification === 'undefined' || ! Array.isArray( current_notification.routing ) ) {
            return;
        }

        var rules = current_notification.routing.filter( function( r ) {
            return r && ( r.email || r.fieldId || r.value );
        } );

        if ( ! rules.length ) {
            return;
        }

        // Build a field ID → label map from formFields.
        var fieldMap = {};
        if ( settings.formFields && settings.formFields.length ) {
            for ( var i = 0; i < settings.formFields.length; i++ ) {
                var f = settings.formFields[ i ];
                if ( f.id && f.label ) {
                    fieldMap[ f.id ] = f.label;
                }
            }
        }

        var lines = [ 'email,field,operator,value' ];

        for ( var j = 0; j < rules.length; j++ ) {
            var r     = rules[ j ];
            var field = fieldMap[ String( r.fieldId ) ] || r.fieldId;
            lines.push(
                csvEscape( r.email || '' ) + ','
                + csvEscape( field ) + ','
                + csvEscape( r.operator || '' ) + ','
                + csvEscape( r.value || '' )
            );
        }

        var csv  = lines.join( '\n' ) + '\n';
        var blob = new Blob( [ csv ], { type: 'text/csv;charset=utf-8;' } );
        var url  = URL.createObjectURL( blob );
        var a    = document.createElement( 'a' );

        a.href     = url;
        a.download = 'routing-rules-export.csv';
        document.body.appendChild( a );
        a.click();
        document.body.removeChild( a );
        URL.revokeObjectURL( url );
    }

    /**
     * Escape a value for CSV output.
     *
     * @param {string} val The value to escape.
     * @return {string} CSV-safe value.
     */
    function csvEscape( val ) {
        val = String( val );
        if ( val.indexOf( ',' ) !== -1 || val.indexOf( '"' ) !== -1 || val.indexOf( '\n' ) !== -1 ) {
            return '"' + val.replace( /"/g, '""' ) + '"';
        }
        return val;
    }

    /**
     * Set the status message.
     *
     * @param {string} message The message text.
     * @param {string} type    One of 'success', 'error', 'loading'.
     */
    function setStatus( message, type ) {
        var $status = $( '#gfnri-status' );
        $status
            .text( message )
            .removeClass( 'gfnri-status--success gfnri-status--error gfnri-status--loading' )
            .addClass( 'gfnri-status--' + type )
            .show();
    }

    /**
     * Show warning messages.
     *
     * @param {Array} warnings Array of warning strings.
     */
    function showWarnings( warnings ) {
        var $container = $( '#gfnri-warnings' );
        var html = '<strong>' + escHtml( strings.warningsTitle || 'Warnings:' ) + '</strong><ul>';

        for ( var i = 0; i < warnings.length; i++ ) {
            html += '<li>' + escHtml( warnings[ i ] ) + '</li>';
        }

        html += '</ul>';
        $container.html( html ).show();
    }

    /**
     * Escape HTML entities.
     *
     * @param {string} str The string to escape.
     * @return {string} Escaped string.
     */
    function escHtml( str ) {
        if ( ! str ) {
            return '';
        }
        var div       = document.createElement( 'div' );
        div.textContent = str;
        return div.innerHTML;
    }

    // --- Initialization ---

    $( document ).ready( function() {
        injectImportUI();

        // Watch toType radio changes for visibility.
        $( document ).on( 'change', 'input[name$="_toType"]', updateVisibility );

        // Also observe when GF renders the routing section via dependency toggle.
        var observer = new MutationObserver( function() {
            if ( $( '#gform_notification_to_routing_rules' ).length && ! $( '#gfnri-import-container' ).length ) {
                injectImportUI();
            }
            updateVisibility();
        } );

        var target = document.getElementById( 'gform_setting_routing' ) || document.querySelector( '.gform-settings-panel__content' );
        if ( target ) {
            observer.observe( target, { childList: true, subtree: true } );
        }

        // Refresh export button when routing rules change (e.g. user adds/removes rows).
        $( document ).on( 'click', '#gform_notification_to_routing_rules .add_field_choice, #gform_notification_to_routing_rules .delete_field_choice', function() {
            setTimeout( updateExportBtn, 100 );
        } );
    } );

} )( jQuery );
