# Notification Routing Importer for Gravity Forms

Bulk import notification routing rules into Gravity Forms from CSV or XLSX files.

![Plugin Screenshot](https://github.com/guilamu/gf-notification-routing-importer/blob/main/Screenshot.png)


## Import

- Upload a **CSV** or **XLSX** file to populate notification routing rules in bulk
- **Smart field mapping:** reference form fields by their label (e.g., `Department`) or explicitly by ID (e.g., `{:5}`)
- **Flexible column order:** columns are matched by header name, not position
- **Replace or Append:** choose whether imported rules overwrite existing routing rules or are added alongside them
- **Auto-detect CSV separator:** comma (`,`) and semicolon (`;`) are both supported

## CSV / XLSX Format

Your file must contain a **header row** with these column names (case-insensitive, any order):

| email | field | operator | value |
|-------|-------|----------|-------|
| `sales@acme.com` | `Department` | `is` | `Sales` |
| `support@acme.com` | `Department` | `is` | `Support` |
| `eu@acme.com` | `{:7}` | `contains` | `Europe` |

### Column Details

| Column | Aliases | Description |
|--------|---------|-------------|
| **email** | `email`, `e-mail`, `mail` | Recipient email address or merge tag (e.g., `{admin_email}`) |
| **field** | `field`, `fieldId`, `field_id`, `field id` | Form field **label** (e.g., `Department`) or explicit ID using the `{:ID}` syntax (e.g., `{:5}`) |
| **operator** | `operator`, `op` | Comparison operator: `is`, `isnot`, `>`, `<`, `contains`, `starts_with`, `ends_with` |
| **value** | `value`, `val` | The value to compare against the selected field |

### Field ID Resolution

The **field** column supports two modes:

- **By label:** write the field label exactly as it appears in the form editor (case-insensitive match)
  - Example: `Department`, `First name`, `Zip code`
- **By explicit ID:** wrap the numeric ID in `{:…}`
  - Example: `{:5}`, `{:12}`

A bare number (e.g., `5`) is also accepted and treated as a field ID.

## Key Features

- **Multilingual:** Works with content in any language
- **Translation-Ready:** All strings are internationalized
- **Secure:** Capability checks, nonce verification, file MIME validation, and XXE protection for XLSX files
- **GitHub Updates:** Automatic updates from GitHub releases

## Requirements

- WordPress 5.8 or higher
- PHP 7.4 or higher
- Gravity Forms 2.5 or higher
- PHP `ZipArchive` extension (for XLSX support — typically available on most hosts)

## Installation

1. Upload the `gf-notification-routing-importer` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Navigate to **Forms → [Your Form] → Settings → Notifications → Edit a notification**
4. Select **Configure Routing** under "Send To" — the import button appears below the routing rules

## FAQ

### Where does the import button appear?

The import button is injected directly into the notification edit page, below the routing rules, when **Configure Routing** is selected as the "Send To" option.

### What happens if a field label doesn't match any form field?

The row is **skipped** and a warning is displayed after import. Other valid rows are still processed.

### Can I import merge tags as email addresses?

Yes. GF merge tags like `{admin_email}` are supported in the email column.

### Does the plugin modify the notification automatically?

No. After import, the routing rules appear in the standard GF routing UI. You still need to click **Update Notification** to save.

### What happens if my CSV uses semicolons?

The plugin auto-detects whether your CSV uses `,` or `;` as the separator.

## Project Structure

```
.
├── .github
│   └── workflows
│       └── release.yml                   # GitHub Actions release workflow
├── gf-notification-routing-importer.php  # Main plugin file
├── uninstall.php                         # Cleanup on uninstall
├── README.md
├── assets
│   ├── css
│   │   └── admin.css                     # Import/Export UI styling
│   ├── js
│   │   └── admin.js                      # Import/Export UI logic
│   └── sample-routing-import.csv         # Downloadable sample file
├── includes
│   ├── class-github-updater.php          # GitHub auto-updates
│   ├── class-import-handler.php          # AJAX handler & CSV parsing
│   ├── class-xlsx-parser.php             # XLSX parser (ZipArchive)
│   └── Parsedown.php                     # Markdown parser (for View details)
└── languages
    ├── gf-notification-routing-importer.pot        # Translation template
    └── gf-notification-routing-importer-fr_FR.po   # French translation
```

## Changelog

### 1.0.0
- Initial release
- CSV and XLSX bulk import for notification routing rules
- CSV export of existing routing rules
- Drag-and-drop file upload
- Smart field ID resolution by label or explicit `{:ID}` syntax
- Human-friendly operator aliases (`less than`, `greater than`, `is not`, etc.)
- Non-routable field type validation (page, section, html, captcha)
- Flexible column detection by header name
- Replace/Append toggle
- Auto-detect CSV separator
- Downloadable sample CSV
- GitHub auto-updater with release workflow
- Bug Reporter integration
- Full security: capability checks, nonce verification, file validation, XXE protection

## License

This project is licensed under the GNU Affero General Public License v3.0 (AGPL-3.0) - see the [LICENSE](LICENSE) file for details.

---

<p align="center">
  Made with love for the WordPress community
</p>
