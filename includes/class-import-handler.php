<?php
/**
 * Import handler for Notification Routing Importer.
 *
 * Handles admin script enqueuing and AJAX file processing for bulk
 * import of notification routing rules from CSV/XLSX files.
 *
 * @package GF_Notification_Routing_Importer
 */

defined( 'ABSPATH' ) || exit;

/**
 * GFNRI_Import_Handler class.
 *
 * Main class: enqueues assets on notification edit pages and processes
 * CSV/XLSX file uploads via AJAX.
 */
class GFNRI_Import_Handler {

    /**
     * Operator alias map: human-friendly names → GF internal operator codes.
     *
     * @var array
     */
    private static $operator_aliases = array(
        // Canonical operators.
        'is'              => 'is',
        'isnot'           => 'isnot',
        '>'               => '>',
        '<'               => '<',
        'contains'        => 'contains',
        'starts_with'     => 'starts_with',
        'ends_with'       => 'ends_with',
        // Human-friendly aliases.
        'is not'          => 'isnot',
        'is_not'          => 'isnot',
        'not'             => 'isnot',
        'greater than'    => '>',
        'greater_than'    => '>',
        'greaterthan'     => '>',
        'gt'              => '>',
        'less than'       => '<',
        'less_than'       => '<',
        'lessthan'        => '<',
        'lt'              => '<',
        'startswith'      => 'starts_with',
        'starts with'     => 'starts_with',
        'endswith'        => 'ends_with',
        'ends with'       => 'ends_with',
    );

    /**
     * Field types that cannot be used in routing rules.
     *
     * @var array
     */
    private static $non_routable_types = array(
        'page', 'section', 'html', 'captcha', 'password',
    );

    /**
     * XLSX parser instance.
     *
     * @var GFNRI_XLSX_Parser
     */
    private $xlsx_parser;

    /**
     * Constructor: Set up hooks.
     */
    public function __construct() {
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'wp_ajax_gfnri_import_routing', array( $this, 'handle_import' ) );
    }

    /**
     * Check if the current admin page is the GF notification edit page.
     *
     * @return bool True if on the notification edit page.
     */
    private function is_notification_edit_page() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only check for page context.
        $page    = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
        $subview = isset( $_GET['subview'] ) ? sanitize_text_field( wp_unslash( $_GET['subview'] ) ) : '';
        $nid     = isset( $_GET['nid'] ) ? sanitize_text_field( wp_unslash( $_GET['nid'] ) ) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        return 'gf_edit_forms' === $page && 'notification' === $subview && ! empty( $nid );
    }

    /**
     * Enqueue admin assets on the notification edit page.
     *
     * @return void
     */
    public function enqueue_admin_assets() {
        if ( ! $this->is_notification_edit_page() ) {
            return;
        }

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only, used to determine form context.
        $form_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if ( ! $form_id || ! class_exists( 'GFAPI' ) ) {
            return;
        }

        wp_enqueue_style(
            'gfnri-admin',
            GFNRI_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            GFNRI_VERSION
        );

        wp_enqueue_script(
            'gfnri-admin',
            GFNRI_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            GFNRI_VERSION,
            true
        );

        // Build form fields data for client-side fieldId validation.
        $form        = GFAPI::get_form( $form_id );
        $form_fields = array();

        if ( $form && ! empty( $form['fields'] ) ) {
            foreach ( $form['fields'] as $field ) {
                $form_fields[] = array(
                    'id'    => (string) $field->id,
                    'label' => $field->label,
                    'type'  => $field->get_input_type(),
                );
            }
        }

        wp_localize_script( 'gfnri-admin', 'gfnriSettings', array(
            'ajaxurl'    => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'gfnri_import_routing' ),
            'formId'     => $form_id,
            'formFields' => $form_fields,
            'sampleUrl'  => GFNRI_PLUGIN_URL . 'assets/sample-routing-import.csv',
            'strings'    => array(
                'importing'      => __( 'Importing…', 'gf-notification-routing-importer' ),
                'success'        => __( '%d routing rule(s) imported successfully.', 'gf-notification-routing-importer' ),
                'error'          => __( 'Import failed: %s', 'gf-notification-routing-importer' ),
                'noFile'         => __( 'Please select a CSV or XLSX file.', 'gf-notification-routing-importer' ),
                'appendLabel'    => __( 'Append to existing', 'gf-notification-routing-importer' ),
                'importTitle'    => __( 'Import Routing Rules', 'gf-notification-routing-importer' ),
                'warningsTitle'  => __( 'Warnings:', 'gf-notification-routing-importer' ),
                'dropHere'       => __( 'Drop your file here or', 'gf-notification-routing-importer' ),
                'selectFile'     => __( 'Select a file', 'gf-notification-routing-importer' ),
                'samplePrefix'   => __( 'Download a sample file:', 'gf-notification-routing-importer' ),
                'exportBtn'      => __( 'Export Routing Rules', 'gf-notification-routing-importer' ),
            ),
        ) );
    }

    /**
     * Handle AJAX import request.
     *
     * @return void
     */
    public function handle_import() {
        // Capability check.
        if ( ! current_user_can( 'gravityforms_edit_forms' ) && ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'gf-notification-routing-importer' ) ), 403 );
        }

        // Nonce verification.
        if ( ! check_ajax_referer( 'gfnri_import_routing', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed.', 'gf-notification-routing-importer' ) ), 403 );
        }

        // Validate file upload.
        if ( empty( $_FILES['file'] ) || ! empty( $_FILES['file']['error'] ) ) {
            wp_send_json_error( array( 'message' => __( 'No file uploaded or upload error.', 'gf-notification-routing-importer' ) ) );
        }

        $file      = $_FILES['file'];
        $tmp_path  = $file['tmp_name'];
        $filename  = sanitize_file_name( $file['name'] );
        $extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

        if ( ! in_array( $extension, array( 'csv', 'xlsx' ), true ) ) {
            wp_send_json_error( array( 'message' => __( 'Only CSV and XLSX files are allowed.', 'gf-notification-routing-importer' ) ) );
        }

        // Get form context.
        $form_id = isset( $_POST['form_id'] ) ? absint( $_POST['form_id'] ) : 0;

        if ( ! $form_id || ! class_exists( 'GFAPI' ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid form ID.', 'gf-notification-routing-importer' ) ) );
        }

        $form = GFAPI::get_form( $form_id );

        if ( ! $form ) {
            wp_send_json_error( array( 'message' => __( 'Form not found.', 'gf-notification-routing-importer' ) ) );
        }

        // Parse file to rows.
        if ( 'xlsx' === $extension ) {
            if ( ! $this->xlsx_parser ) {
                $this->xlsx_parser = new GFNRI_XLSX_Parser();
            }

            // Validate XLSX structure.
            if ( ! $this->xlsx_parser->validate_xlsx_mime( $tmp_path ) ) {
                wp_send_json_error( array( 'message' => __( 'Invalid XLSX file.', 'gf-notification-routing-importer' ) ) );
            }

            $rows = $this->xlsx_parser->extract_all_rows( $tmp_path );
        } else {
            $rows = $this->parse_csv( $tmp_path );
        }

        if ( is_wp_error( $rows ) ) {
            wp_send_json_error( array( 'message' => $rows->get_error_message() ) );
        }

        if ( count( $rows ) < 2 ) {
            wp_send_json_error( array( 'message' => __( 'File must have a header row and at least one data row.', 'gf-notification-routing-importer' ) ) );
        }

        // Map columns by header name.
        $column_map = $this->detect_columns( $rows[0] );

        if ( is_wp_error( $column_map ) ) {
            wp_send_json_error( array( 'message' => $column_map->get_error_message() ) );
        }

        // Build field lookup maps (by ID and by label).
        // Exclude non-routable field types (page, section, html, captcha, password).
        $field_by_id    = array();
        $field_by_label = array();

        if ( ! empty( $form['fields'] ) ) {
            foreach ( $form['fields'] as $field ) {
                $field_by_id[ (string) $field->id ] = $field;
                $label = trim( $field->label );
                if ( '' !== $label ) {
                    $label_key                    = mb_strtolower( $label );
                    $field_by_label[ $label_key ] = $field;
                }
            }
        }

        // Process data rows.
        $routing  = array();
        $warnings = array();

        for ( $i = 1; $i < count( $rows ); $i++ ) {
            $row = $rows[ $i ];

            // Skip empty rows.
            $filtered = array_filter( $row, 'strlen' );
            if ( empty( $filtered ) ) {
                continue;
            }

            $row_num = $i + 1; // Human-readable row number.

            // Extract values by column map.
            $email    = isset( $row[ $column_map['email'] ] ) ? trim( $row[ $column_map['email'] ] ) : '';
            $field_id = isset( $row[ $column_map['fieldid'] ] ) ? trim( $row[ $column_map['fieldid'] ] ) : '';
            $operator = isset( $row[ $column_map['operator'] ] ) ? trim( $row[ $column_map['operator'] ] ) : '';
            $value    = isset( $row[ $column_map['value'] ] ) ? trim( $row[ $column_map['value'] ] ) : '';

            // Validate email.
            if ( empty( $email ) || ! self::is_valid_notification_email( $email ) ) {
                $warnings[] = sprintf(
                    /* translators: %1$d: row number, %2$s: email value */
                    __( 'Row %1$d: invalid email "%2$s", skipped.', 'gf-notification-routing-importer' ),
                    $row_num,
                    $email
                );
                continue;
            }

            // Resolve field ID (by label or explicit {:ID} syntax).
            $resolved_field_id = $this->resolve_field_id( $field_id, $field_by_id, $field_by_label );

            if ( is_wp_error( $resolved_field_id ) ) {
                $warnings[] = sprintf(
                    /* translators: %1$d: row number, %2$s: error message */
                    __( 'Row %1$d: %2$s, skipped.', 'gf-notification-routing-importer' ),
                    $row_num,
                    $resolved_field_id->get_error_message()
                );
                continue;
            }

            // Resolve and validate operator.
            $operator = strtolower( trim( $operator ) );
            if ( ! isset( self::$operator_aliases[ $operator ] ) ) {
                $warnings[] = sprintf(
                    /* translators: %1$d: row number, %2$s: operator value */
                    __( 'Row %1$d: invalid operator "%2$s", skipped.', 'gf-notification-routing-importer' ),
                    $row_num,
                    $operator
                );
                continue;
            }
            $operator = self::$operator_aliases[ $operator ];

            // Sanitize value.
            $value = wp_kses( $value, 'post' );

            $routing[] = array(
                'email'    => sanitize_text_field( $email ),
                'fieldId'  => (string) $resolved_field_id,
                'operator' => $operator,
                'value'    => $value,
            );
        }

        if ( empty( $routing ) ) {
            wp_send_json_error( array(
                'message'  => __( 'No valid routing rules found in the file.', 'gf-notification-routing-importer' ),
                'warnings' => $warnings,
            ) );
        }

        wp_send_json_success( array(
            'routing'  => $routing,
            'count'    => count( $routing ),
            'warnings' => $warnings,
        ) );
    }

    /**
     * Parse a CSV file into rows.
     *
     * Auto-detects separator (comma vs semicolon).
     *
     * @param string $file_path Path to the CSV file.
     * @return array|WP_Error Array of rows or error.
     */
    private function parse_csv( $file_path ) {
        $handle = fopen( $file_path, 'r' );

        if ( ! $handle ) {
            return new WP_Error( 'csv_open_failed', __( 'Failed to open CSV file.', 'gf-notification-routing-importer' ) );
        }

        // Read first line to detect separator.
        $first_line = fgets( $handle );
        rewind( $handle );

        if ( false === $first_line ) {
            fclose( $handle );
            return new WP_Error( 'csv_empty', __( 'CSV file is empty.', 'gf-notification-routing-importer' ) );
        }

        // Auto-detect separator: count semicolons vs commas in header.
        $semicolons = substr_count( $first_line, ';' );
        $commas     = substr_count( $first_line, ',' );
        $separator  = ( $semicolons > $commas ) ? ';' : ',';

        $rows = array();

        while ( ( $row = fgetcsv( $handle, 0, $separator ) ) !== false ) {
            $rows[] = $row;
        }

        fclose( $handle );

        return $rows;
    }

    /**
     * Detect column indices by matching header names.
     *
     * Supported header names (case-insensitive):
     * - email, e-mail, mail
     * - fieldid, field_id, field id, field
     * - operator, op
     * - value, val
     *
     * @param array $header_row The first row of the file.
     * @return array|WP_Error Associative array mapping canonical names to column indices.
     */
    private function detect_columns( $header_row ) {
        $aliases = array(
            'email'    => array( 'email', 'e-mail', 'mail' ),
            'fieldid'  => array( 'fieldid', 'field_id', 'field id', 'field' ),
            'operator' => array( 'operator', 'op' ),
            'value'    => array( 'value', 'val' ),
        );

        $map   = array();
        $found = array();

        foreach ( $header_row as $index => $cell ) {
            $normalized = mb_strtolower( trim( $cell ) );

            foreach ( $aliases as $canonical => $names ) {
                if ( isset( $found[ $canonical ] ) ) {
                    continue;
                }
                if ( in_array( $normalized, $names, true ) ) {
                    $map[ $canonical ]   = $index;
                    $found[ $canonical ] = true;
                    break;
                }
            }
        }

        $missing = array_diff( array_keys( $aliases ), array_keys( $map ) );

        if ( ! empty( $missing ) ) {
            return new WP_Error(
                'missing_columns',
                sprintf(
                    /* translators: %s: comma-separated list of missing column names */
                    __( 'Missing required column(s): %s. Expected headers: email, fieldId (or field), operator, value.', 'gf-notification-routing-importer' ),
                    implode( ', ', $missing )
                )
            );
        }

        return $map;
    }

    /**
     * Resolve a field ID from user input.
     *
     * Supports two formats:
     * - Explicit ID: "{:5}" or just "5" (bare number)
     * - Label match: "First name" (case-insensitive match against field labels)
     *
     * @param string $input          The fieldId value from the CSV/XLSX.
     * @param array  $field_by_id    Map of field ID (string) => GF_Field.
     * @param array  $field_by_label Map of lowercase label => GF_Field.
     * @return int|WP_Error Resolved field ID or error.
     */
    private function resolve_field_id( $input, $field_by_id, $field_by_label ) {
        if ( empty( $input ) ) {
            return new WP_Error( 'empty_field_id', __( 'empty field ID', 'gf-notification-routing-importer' ) );
        }

        // Check for explicit ID syntax: {:5}
        if ( preg_match( '/^\{:(\d+)\}$/', $input, $matches ) ) {
            $id = $matches[1];
            if ( isset( $field_by_id[ $id ] ) ) {
                return $this->validate_routable_field( $field_by_id[ $id ] );
            }
            return new WP_Error(
                'field_not_found',
                sprintf(
                    /* translators: %s: field ID */
                    __( 'field ID %s not found in form', 'gf-notification-routing-importer' ),
                    $id
                )
            );
        }

        // Check if it's a bare numeric value (plain field ID).
        if ( is_numeric( $input ) && isset( $field_by_id[ (string) intval( $input ) ] ) ) {
            return $this->validate_routable_field( $field_by_id[ (string) intval( $input ) ] );
        }

        // Try label match (case-insensitive).
        $label_key = mb_strtolower( trim( $input ) );

        if ( isset( $field_by_label[ $label_key ] ) ) {
            return $this->validate_routable_field( $field_by_label[ $label_key ] );
        }

        // If it was numeric but field not found, give a specific message.
        if ( is_numeric( $input ) ) {
            return new WP_Error(
                'field_not_found',
                sprintf(
                    /* translators: %s: field ID */
                    __( 'field ID %s not found in form', 'gf-notification-routing-importer' ),
                    $input
                )
            );
        }

        return new WP_Error(
            'field_not_found',
            sprintf(
                /* translators: %s: field label */
                __( 'no field matching label "%s"', 'gf-notification-routing-importer' ),
                $input
            )
        );
    }

    /**
     * Validate that a resolved field can be used in routing rules.
     *
     * Non-routable types (page, section, html, captcha, password) are rejected
     * with a clear error message.
     *
     * @param GF_Field $field The resolved field object.
     * @return int|WP_Error Field ID or error.
     */
    private function validate_routable_field( $field ) {
        $type = $field->get_input_type();

        if ( in_array( $type, self::$non_routable_types, true ) ) {
            return new WP_Error(
                'non_routable_field',
                sprintf(
                    /* translators: %1$s: field ID, %2$s: field type */
                    __( 'field ID %1$s is a %2$s field (not routable)', 'gf-notification-routing-importer' ),
                    $field->id,
                    $type
                )
            );
        }

        return (int) $field->id;
    }

    /**
     * Validate a notification email string.
     *
     * Supports comma-separated emails and GF merge tags like {admin_email}.
     * Self-contained alternative to GFNotification::is_valid_notification_email()
     * which may not be loaded during AJAX requests.
     *
     * @param string $text Email string to validate.
     * @return bool True if valid.
     */
    private static function is_valid_notification_email( $text ) {
        if ( empty( $text ) ) {
            return false;
        }

        $emails = explode( ',', $text );

        foreach ( $emails as $email ) {
            $email = trim( $email );

            if ( empty( $email ) ) {
                return false;
            }

            // Accept GF merge tags (e.g. {admin_email}, {Email:3}).
            if ( preg_match( '/\{.*?\}/', $email ) ) {
                continue;
            }

            // Standard email validation.
            if ( ! is_email( $email ) ) {
                return false;
            }
        }

        return true;
    }
}
