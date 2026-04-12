<?php
/**
 * XLSX parser class for Notification Routing Importer.
 *
 * Parses XLSX files using native PHP ZipArchive and SimpleXML.
 *
 * @package GF_Notification_Routing_Importer
 */

defined( 'ABSPATH' ) || exit;

/**
 * GFNRI_XLSX_Parser class.
 *
 * Parses XLSX files (ZIP archives containing XML spreadsheet data).
 */
class GFNRI_XLSX_Parser {

    /**
     * Shared strings from the workbook.
     *
     * @var array
     */
    private $shared_strings = array();

    /**
     * Extract all rows from XLSX file.
     *
     * @param string $file_path The path to the XLSX file.
     * @return array|WP_Error Array of rows or error.
     */
    public function extract_all_rows( $file_path ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return new WP_Error( 'no_zip_support', __( 'PHP ZipArchive extension is required for XLSX support.', 'gf-notification-routing-importer' ) );
        }

        if ( ! file_exists( $file_path ) ) {
            return new WP_Error( 'file_not_found', __( 'XLSX file not found.', 'gf-notification-routing-importer' ) );
        }

        $zip    = new ZipArchive();
        $result = $zip->open( $file_path );

        if ( true !== $result ) {
            return new WP_Error( 'zip_open_failed', __( 'Failed to open XLSX file.', 'gf-notification-routing-importer' ) );
        }

        // Load shared strings (text values are stored here).
        $this->shared_strings = $this->load_shared_strings( $zip );

        // Load the first worksheet.
        $sheet_xml = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
        $zip->close();

        if ( false === $sheet_xml ) {
            return new WP_Error( 'no_worksheet', __( 'No worksheet found in XLSX file.', 'gf-notification-routing-importer' ) );
        }

        return $this->parse_sheet( $sheet_xml );
    }

    /**
     * Validate that a file is a genuine XLSX (ZIP archive with expected structure).
     *
     * @param string $file_path Path to the file.
     * @return bool True if valid XLSX.
     */
    public function validate_xlsx_mime( $file_path ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return false;
        }

        $zip = new ZipArchive();
        if ( true !== $zip->open( $file_path, ZipArchive::RDONLY ) ) {
            return false;
        }

        $has_content_types = ( false !== $zip->locateName( '[Content_Types].xml' ) );
        $has_workbook      = ( false !== $zip->locateName( 'xl/workbook.xml' ) );
        $zip->close();

        return $has_content_types && $has_workbook;
    }

    /**
     * Load shared strings from the XLSX file.
     *
     * @param ZipArchive $zip The open ZIP archive.
     * @return array Array of shared strings.
     */
    private function load_shared_strings( $zip ) {
        $strings     = array();
        $xml_content = $zip->getFromName( 'xl/sharedStrings.xml' );

        if ( false === $xml_content ) {
            return $strings;
        }

        libxml_use_internal_errors( true );
        if ( PHP_VERSION_ID < 80000 ) {
            // phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated -- Required for PHP < 8.0 XXE protection
            libxml_disable_entity_loader( true );
        }
        $xml = simplexml_load_string( $xml_content, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOENT );
        libxml_clear_errors();

        if ( false === $xml ) {
            return $strings;
        }

        $xml->registerXPathNamespace( 'ss', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main' );

        foreach ( $xml->si as $si ) {
            if ( isset( $si->t ) ) {
                $strings[] = (string) $si->t;
            } elseif ( isset( $si->r ) ) {
                $text = '';
                foreach ( $si->r as $run ) {
                    if ( isset( $run->t ) ) {
                        $text .= (string) $run->t;
                    }
                }
                $strings[] = $text;
            } else {
                $strings[] = '';
            }
        }

        return $strings;
    }

    /**
     * Parse worksheet XML into rows.
     *
     * @param string $sheet_xml The worksheet XML content.
     * @return array|WP_Error Array of rows or error.
     */
    private function parse_sheet( $sheet_xml ) {
        libxml_use_internal_errors( true );
        if ( PHP_VERSION_ID < 80000 ) {
            // phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated -- Required for PHP < 8.0 XXE protection
            libxml_disable_entity_loader( true );
        }
        $xml = simplexml_load_string( $sheet_xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOENT );
        libxml_clear_errors();

        if ( false === $xml ) {
            return new WP_Error( 'xml_parse_failed', __( 'Failed to parse worksheet XML.', 'gf-notification-routing-importer' ) );
        }

        $namespaces = $xml->getNamespaces( true );
        if ( ! empty( $namespaces ) ) {
            $ns = reset( $namespaces );
            $xml->registerXPathNamespace( 'x', $ns );
        }

        $rows = array();

        if ( ! isset( $xml->sheetData->row ) ) {
            return $rows;
        }

        foreach ( $xml->sheetData->row as $row_element ) {
            $row_data = array();

            foreach ( $row_element->c as $cell ) {
                $cell_ref  = (string) $cell['r'];
                $col_index = $this->column_to_index( $cell_ref );

                while ( count( $row_data ) <= $col_index ) {
                    $row_data[] = '';
                }

                $row_data[ $col_index ] = $this->get_cell_value( $cell );
            }

            $rows[] = $row_data;
        }

        return $rows;
    }

    /**
     * Get the value from a cell element.
     *
     * @param SimpleXMLElement $cell The cell element.
     * @return string The cell value.
     */
    private function get_cell_value( $cell ) {
        $type  = (string) $cell['t'];
        $value = '';

        if ( isset( $cell->v ) ) {
            $value = (string) $cell->v;
        } else {
            $namespaces = $cell->getNamespaces( true );
            if ( ! empty( $namespaces ) ) {
                $ns       = reset( $namespaces );
                $children = $cell->children( $ns );
                if ( isset( $children->v ) ) {
                    $value = (string) $children->v;
                }
            }
        }

        switch ( $type ) {
            case 's':
                $index = intval( $value );
                return isset( $this->shared_strings[ $index ] ) ? $this->shared_strings[ $index ] : '';

            case 'inlineStr':
                if ( isset( $cell->is->t ) ) {
                    return (string) $cell->is->t;
                }
                $namespaces = $cell->getNamespaces( true );
                if ( ! empty( $namespaces ) ) {
                    $ns       = reset( $namespaces );
                    $children = $cell->children( $ns );
                    if ( isset( $children->is->t ) ) {
                        return (string) $children->is->t;
                    }
                }
                return '';

            case 'b':
                return $value === '1' ? 'TRUE' : 'FALSE';

            case 'e':
                return '';

            default:
                return $value;
        }
    }

    /**
     * Convert Excel column reference to zero-based index.
     *
     * @param string $cell_ref The cell reference (e.g., "A1", "AB5").
     * @return int Zero-based column index.
     */
    private function column_to_index( $cell_ref ) {
        preg_match( '/^([A-Z]+)/', $cell_ref, $matches );
        $column = isset( $matches[1] ) ? $matches[1] : 'A';

        $index  = 0;
        $length = strlen( $column );

        for ( $i = 0; $i < $length; $i++ ) {
            $index = $index * 26 + ( ord( $column[ $i ] ) - ord( 'A' ) + 1 );
        }

        return $index - 1;
    }
}
