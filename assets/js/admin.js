/**
 * GF Notification Routing Importer — Admin JavaScript
 *
 * Injects the import UI into the GF notification routing section and
 * handles file upload, Google Sheets import, and integration with
 * GF's native routing JS.
 *
 * @package GF_Notification_Routing_Importer
 */

( function( $ ) {
    'use strict';

    var settings     = window.gfnriSettings || {};
    var strings      = settings.strings || {};
    var gsheets      = settings.gsheets || {};
    var gsheetsSaved = settings.gsheetsSaved || {};

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

        var hasGSheets = gsheets.available && gsheets.accounts && gsheets.accounts.length > 0;
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
            +   '</div>';

        // Tabs (only if Google Sheets is available).
        if ( hasGSheets ) {
            html += '<div id="gfnri-tabs">'
                +     '<button type="button" class="gfnri-tab gfnri-tab--active" data-tab="file">'
                +       escHtml( strings.fileTab || 'File Upload' )
                +     '</button>'
                +     '<button type="button" class="gfnri-tab" data-tab="gsheets">'
                +       escHtml( strings.gsheetsTab || 'Google Sheets' )
                +     '</button>'
                +   '</div>';
        }

        // File upload panel.
        html += '<div id="gfnri-panel-file" class="gfnri-panel gfnri-panel--active">'
            +     '<div id="gfnri-dropzone">'
            +       '<input type="file" id="gfnri-file-input" accept=".csv,.xlsx" style="display:none">'
            +       '<span class="gfnri-drop-instructions">'
            +         escHtml( strings.dropHere || 'Drop your file here or' ) + ' '
            +       '</span>'
            +       '<input type="button" id="gfnri-upload-btn" value="'
            +         escHtml( strings.selectFile || 'Select a file' )
            +       '" class="button">'
            +     '</div>'
            +     '<div id="gfnri-sample">'
            +       escHtml( strings.samplePrefix || 'Download a sample file:' ) + ' '
            +       '<a href="' + escHtml( settings.sampleUrl || '#' ) + '" target="_blank">sample.csv</a>'
            +     '</div>'
            +   '</div>';

        // Google Sheets panel.
        if ( hasGSheets ) {
            html += '<div id="gfnri-panel-gsheets" class="gfnri-panel">'
                +     '<div class="gfnri-gsheets-field">'
                +       '<label for="gfnri-gsheets-account">' + escHtml( strings.gsheetsAccount || 'Google Account' ) + '</label>'
                +       '<select id="gfnri-gsheets-account">'
                +         '<option value="">' + escHtml( strings.gsheetsSelectAccount || 'Select an account…' ) + '</option>';

            for ( var a = 0; a < gsheets.accounts.length; a++ ) {
                html += '<option value="' + escHtml( gsheets.accounts[ a ].email ) + '">'
                    + escHtml( gsheets.accounts[ a ].email )
                    + '</option>';
            }

            html +=       '</select>'
                +     '</div>'
                +     '<div class="gfnri-gsheets-field">'
                +       '<label for="gfnri-gsheets-url">' + escHtml( strings.gsheetsUrl || 'Spreadsheet URL' ) + '</label>'
                +       '<div class="gfnri-gsheets-url-row">'
                +         '<input type="url" id="gfnri-gsheets-url" placeholder="'
                +           escHtml( strings.gsheetsUrlPlaceholder || 'Paste your Google Sheets URL…' )
                +         '" class="regular-text">'
                +         '<input type="button" id="gfnri-gsheets-load-btn" value="'
                +           escHtml( strings.gsheetsLoadSheets || 'Load Sheets' )
                +         '" class="button">'
                +       '</div>'
                +     '</div>'
                +     '<div class="gfnri-gsheets-field">'
                +       '<label for="gfnri-gsheets-sheet">' + escHtml( strings.gsheetsSheet || 'Sheet Tab' ) + '</label>'
                +       '<select id="gfnri-gsheets-sheet" disabled>'
                +         '<option value="">' + escHtml( strings.gsheetsSelectSheet || 'Select a sheet tab…' ) + '</option>'
                +       '</select>'
                +     '</div>'
                +     '<div class="gfnri-gsheets-field gfnri-gsheets-field--actions">'
                +       '<input type="button" id="gfnri-gsheets-import-btn" value="'
                +         escHtml( strings.gsheetsImport || 'Import from Google Sheets' )
                +       '" class="button button-primary" disabled>'
                +     '</div>'                +     '<div class="gfnri-gsheets-field">'
                +       '<label for="gfnri-gsheets-sync-interval">' + escHtml( strings.gsheetsSync || 'Auto-sync' ) + '</label>'
                +       '<select id="gfnri-gsheets-sync-interval">'
                +         '<option value="0">' + escHtml( strings.gsheetsSyncManual || 'Manual only' ) + '</option>'
                +         '<option value="5">' + escHtml( strings.gsheetsSync5 || 'Every 5 minutes' ) + '</option>'
                +         '<option value="15">' + escHtml( strings.gsheetsSync15 || 'Every 15 minutes' ) + '</option>'
                +         '<option value="30">' + escHtml( strings.gsheetsSync30 || 'Every 30 minutes' ) + '</option>'
                +         '<option value="60">' + escHtml( strings.gsheetsSync60 || 'Every hour' ) + '</option>'
                +       '</select>'
                +     '</div>'
                +     '<div id="gfnri-gsheets-sync-info"></div>'                +   '</div>';
        }

        // Export + status (shared).
        html += '<div id="gfnri-export">'
            +     '<input type="button" id="gfnri-export-btn" value="'
            +       escHtml( strings.exportBtn || 'Export Routing Rules' )
            +     '" class="button" disabled>'
            +   '</div>'
            +   '<span id="gfnri-status"></span>'
            +   '<div id="gfnri-warnings" style="display:none"></div>'
            +   '<input type="hidden" name="gfnri_gsheets_account" id="gfnri-hidden-account" value="">'
            +   '<input type="hidden" name="gfnri_gsheets_url" id="gfnri-hidden-url" value="">'
            +   '<input type="hidden" name="gfnri_gsheets_sheet_id" id="gfnri-hidden-sheet-id" value="">'
            +   '<input type="hidden" name="gfnri_gsheets_sheet_name" id="gfnri-hidden-sheet-name" value="">'
            +   '<input type="hidden" name="gfnri_gsheets_sync_interval" id="gfnri-hidden-sync-interval" value="0">'
            + '</div>';

        $routingRules.after( html );

        // --- File Upload Bindings ---

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

        // --- Tab Bindings ---

        if ( hasGSheets ) {
            $( '#gfnri-tabs' ).on( 'click', '.gfnri-tab', function( e ) {
                e.preventDefault();
                var tab = $( this ).data( 'tab' );
                $( '.gfnri-tab' ).removeClass( 'gfnri-tab--active' );
                $( this ).addClass( 'gfnri-tab--active' );
                $( '.gfnri-panel' ).removeClass( 'gfnri-panel--active' );
                $( '#gfnri-panel-' + tab ).addClass( 'gfnri-panel--active' );
                $( '#gfnri-export' ).toggle( tab === 'file' );
            } );

            // --- Google Sheets Bindings ---

            $( '#gfnri-gsheets-load-btn' ).on( 'click', handleGSheetsLoadSheets );
            $( '#gfnri-gsheets-import-btn' ).on( 'click', handleGSheetsImport );

            // Enable import button when sheet is selected.
            $( '#gfnri-gsheets-sheet' ).on( 'change', function() {
                $( '#gfnri-gsheets-import-btn' ).prop( 'disabled', ! $( this ).val() );
            } );
        }

        // Export button.
        $( '#gfnri-export-btn' ).on( 'click', handleExport );

        // Sync hidden inputs on form submit.
        $( '#gfnri-import-container' ).closest( 'form' ).on( 'submit', syncGSheetsHidden );

        // Initial visibility and export button state.
        updateVisibility();
        updateExportBtn();

        // Restore saved Google Sheets connection.
        restoreSavedGSheets();
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
            success:     function( response ) {
                handleImportResponse( response, appendMode );
            },
            error: function( xhr ) {
                handleImportError( xhr );
            }
        } );
    }

    /**
     * Load available sheets for the selected Google Spreadsheet.
     */
    function handleGSheetsLoadSheets() {
        var account = $( '#gfnri-gsheets-account' ).val();
        var url     = $( '#gfnri-gsheets-url' ).val().trim();

        if ( ! account ) {
            setStatus( strings.gsheetsSelectAccount || 'Select an account…', 'error' );
            return;
        }

        if ( ! url ) {
            setStatus( strings.gsheetsUrlPlaceholder || 'Paste your Google Sheets URL…', 'error' );
            return;
        }

        setStatus( strings.gsheetsLoading || 'Loading sheets…', 'loading' );
        $( '#gfnri-gsheets-load-btn' ).prop( 'disabled', true );
        $( '#gfnri-gsheets-sheet' ).prop( 'disabled', true ).html(
            '<option value="">' + escHtml( strings.gsheetsLoading || 'Loading sheets…' ) + '</option>'
        );
        $( '#gfnri-gsheets-import-btn' ).prop( 'disabled', true );

        $.ajax( {
            url:      settings.ajaxurl,
            type:     'POST',
            dataType: 'json',
            data: {
                action:          'gfnri_gsheets_list_sheets',
                nonce:           settings.nonce,
                account_email:   account,
                spreadsheet_url: url
            },
            success: function( response ) {
                $( '#gfnri-gsheets-load-btn' ).prop( 'disabled', false );

                if ( response.success && response.data.sheets ) {
                    var $select = $( '#gfnri-gsheets-sheet' );
                    $select.html( '<option value="">' + escHtml( strings.gsheetsSelectSheet || 'Select a sheet tab…' ) + '</option>' );

                    for ( var i = 0; i < response.data.sheets.length; i++ ) {
                        var s = response.data.sheets[ i ];
                        $select.append( '<option value="' + escHtml( String( s.id ) ) + '">' + escHtml( s.title ) + '</option>' );
                    }

                    // Auto-select the previously saved sheet if available.
                    var savedSheetId = $( '#gfnri-hidden-sheet-id' ).val();
                    if ( savedSheetId ) {
                        $select.val( savedSheetId );
                    }

                    $select.prop( 'disabled', false ).trigger( 'change' );
                    setStatus( '', 'success' );
                    $( '#gfnri-status' ).hide();
                } else {
                    var errMsg = response.data && response.data.message
                        ? response.data.message
                        : 'Could not load sheets.';
                    setStatus( '\u274C ' + errMsg, 'error' );
                    $( '#gfnri-gsheets-sheet' ).html(
                        '<option value="">' + escHtml( strings.gsheetsSelectSheet || 'Select a sheet tab…' ) + '</option>'
                    );
                }
            },
            error: function( xhr ) {
                $( '#gfnri-gsheets-load-btn' ).prop( 'disabled', false );
                handleImportError( xhr );
            }
        } );
    }

    /**
     * Import routing rules from the selected Google Sheet.
     */
    function handleGSheetsImport() {
        var account  = $( '#gfnri-gsheets-account' ).val();
        var url      = $( '#gfnri-gsheets-url' ).val().trim();
        var sheetId  = $( '#gfnri-gsheets-sheet' ).val();
        var appendMode = $( '#gfnri-mode-toggle' ).is( ':checked' );

        if ( ! account || ! url || ! sheetId ) {
            setStatus( '\u274C ' + ( strings.error || 'Import failed: %s' ).replace( '%s', 'Missing fields.' ), 'error' );
            return;
        }

        setStatus( strings.importing || 'Importing…', 'loading' );
        $( '#gfnri-warnings' ).hide().empty();
        $( '#gfnri-gsheets-import-btn' ).prop( 'disabled', true );

        $.ajax( {
            url:      settings.ajaxurl,
            type:     'POST',
            dataType: 'json',
            data: {
                action:          'gfnri_gsheets_import',
                nonce:           settings.nonce,
                form_id:         settings.formId,
                account_email:   account,
                spreadsheet_url: url,
                sheet_id:        sheetId
            },
            success: function( response ) {
                $( '#gfnri-gsheets-import-btn' ).prop( 'disabled', false );
                handleImportResponse( response, appendMode );
                if ( response.success ) {
                    syncGSheetsHidden();
                }
            },
            error: function( xhr ) {
                $( '#gfnri-gsheets-import-btn' ).prop( 'disabled', false );
                handleImportError( xhr );
            }
        } );
    }

    /**
     * Shared handler for a successful AJAX import response (file or Google Sheets).
     *
     * @param {Object}  response   The AJAX response.
     * @param {boolean} appendMode Whether to append or replace.
     */
    function handleImportResponse( response, appendMode ) {
        if ( response.success ) {
            applyRouting( response.data.routing, appendMode );

            var msg = ( strings.success || '%d routing rule(s) imported successfully.' )
                .replace( '%d', response.data.count );
            setStatus( '\u2705 ' + msg, 'success' );

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
    }

    /**
     * Shared handler for AJAX errors.
     *
     * @param {Object} xhr The XHR object.
     */
    function handleImportError( xhr ) {
        var errMsg = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
            ? xhr.responseJSON.data.message
            : 'Server error (' + xhr.status + ')';
        setStatus( '\u274C ' + ( strings.error || 'Import failed: %s' ).replace( '%s', errMsg ), 'error' );
    }

    /**
     * Sync the visible Google Sheets fields to the hidden form inputs.
     *
     * Only updates when the state is complete (all filled or all empty),
     * so partial states (e.g. after a failed "Load Sheets") preserve the
     * previously committed connection.
     */
    function syncGSheetsHidden() {
        var account  = $( '#gfnri-gsheets-account' ).val() || '';
        var url      = $( '#gfnri-gsheets-url' ).val()     || '';
        var sheetId  = $( '#gfnri-gsheets-sheet' ).val()   || '';
        var interval = $( '#gfnri-gsheets-sync-interval' ).val() || '0';

        if ( ( account && url && sheetId ) || ( ! account && ! url && ! sheetId ) ) {
            $( '#gfnri-hidden-account' ).val( account );
            $( '#gfnri-hidden-url' ).val( url );
            $( '#gfnri-hidden-sheet-id' ).val( sheetId );
            $( '#gfnri-hidden-sheet-name' ).val(
                sheetId
                    ? ( $( '#gfnri-gsheets-sheet option:selected' ).text() || '' )
                    : ''
            );
            $( '#gfnri-hidden-sync-interval' ).val( account && url && sheetId ? interval : '0' );
        }
    }

    /**
     * Restore a previously saved Google Sheets connection on page load.
     */
    function restoreSavedGSheets() {
        if ( ! gsheetsSaved.account || ! gsheetsSaved.url || ! gsheetsSaved.sheetId ) {
            return;
        }

        // Bail if the Google Sheets panel doesn't exist.
        if ( ! $( '#gfnri-panel-gsheets' ).length ) {
            return;
        }

        // Pre-fill the visible form fields.
        $( '#gfnri-gsheets-account' ).val( gsheetsSaved.account );
        $( '#gfnri-gsheets-url' ).val( gsheetsSaved.url );

        // Add the saved sheet as the selected option.
        var sheetLabel = gsheetsSaved.sheetName || ( 'Sheet (ID: ' + gsheetsSaved.sheetId + ')' );
        $( '#gfnri-gsheets-sheet' )
            .html( '<option value="' + escHtml( String( gsheetsSaved.sheetId ) ) + '">' + escHtml( sheetLabel ) + '</option>' )
            .prop( 'disabled', false )
            .trigger( 'change' );

        // Restore sync interval.
        if ( gsheetsSaved.syncInterval ) {
            $( '#gfnri-gsheets-sync-interval' ).val( String( gsheetsSaved.syncInterval ) );
        }

        // Set hidden inputs.
        $( '#gfnri-hidden-account' ).val( gsheetsSaved.account );
        $( '#gfnri-hidden-url' ).val( gsheetsSaved.url );
        $( '#gfnri-hidden-sheet-id' ).val( gsheetsSaved.sheetId );
        $( '#gfnri-hidden-sheet-name' ).val( gsheetsSaved.sheetName );
        $( '#gfnri-hidden-sync-interval' ).val( String( gsheetsSaved.syncInterval || 0 ) );

        // Switch to Google Sheets tab.
        $( '.gfnri-tab' ).removeClass( 'gfnri-tab--active' );
        $( '.gfnri-tab[data-tab="gsheets"]' ).addClass( 'gfnri-tab--active' );
        $( '.gfnri-panel' ).removeClass( 'gfnri-panel--active' );
        $( '#gfnri-panel-gsheets' ).addClass( 'gfnri-panel--active' );

        // Hide export when on Google Sheets tab.
        $( '#gfnri-export' ).hide();

        // Show last sync info.
        showSyncInfo();
    }

    /**
     * Display last-synced information in the Google Sheets panel.
     */
    function showSyncInfo() {
        var $info = $( '#gfnri-gsheets-sync-info' );
        if ( ! $info.length ) {
            return;
        }

        if ( ! gsheetsSaved.lastSync ) {
            $info.hide();
            return;
        }

        var html = '';

        if ( gsheetsSaved.syncStatus === 'error' && gsheetsSaved.syncMessage ) {
            html += '<span class="gfnri-sync-error">\u274C '
                + escHtml( ( strings.gsheetsSyncError || 'Sync error: %s' ).replace( '%s', gsheetsSaved.syncMessage ) )
                + '</span><br>';
        } else if ( gsheetsSaved.syncStatus === 'ok' && gsheetsSaved.syncMessage ) {
            html += '<span class="gfnri-sync-ok">\u2705 ' + escHtml( gsheetsSaved.syncMessage ) + '</span><br>';
        }

        html += '<span class="gfnri-sync-time">'
            + escHtml( ( strings.gsheetsLastSync || 'Last synced: %s' ).replace( '%s', gsheetsSaved.lastSync ) )
            + '</span>';

        $info.html( html ).show();
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
