# Installing Required Libraries

**Note**: The libraries `webklex/php-imap` and `zbateson/mail-mime-parser` are listed in `composer.json` but are **not currently used** in the codebase. The application uses PHP's built-in `ext-imap` extension instead.

## Current Implementation

Bounce Monitor uses:
- **PHP ext-imap**: Built-in IMAP extension for mailbox access (primary email parsing)
- **PHP ext-mbstring**: Built-in multi-byte string handling for email parsing
- **PHPMailer**: For sending notifications (installed via Composer)
- **OAuth2 Libraries**: For Google and Microsoft authentication

## Required PHP Extensions

Ensure the following PHP extensions are installed:
- `ext-imap` - For IMAP mailbox access
- `ext-pdo` - For SQLite database
- `ext-pdo_sqlite` - SQLite driver
- `ext-json` - For JSON handling
- `ext-mbstring` - For multi-byte string handling
- `ext-curl` - For OAuth and DNS lookups (optional but recommended)
- `ext-openssl` - For SSL/TLS connections

## Optional: Remote MSSQL Sync

The **Remote MSSQL Sync** feature (Control Panel → sync confirmed hard-bounce bad addresses to a remote MSSQL table) requires a PDO driver for SQL Server. Install one of the following depending on your platform:

- **Windows**: `pdo_sqlsrv` – Microsoft SQL Server PDO driver. Install via [Microsoft Drivers for PHP for SQL Server](https://docs.microsoft.com/en-us/sql/connect/php/download-drivers-php-sql-server) or PECL.
- **Linux**: `pdo_dblib` with FreeTDS – Enables PDO connections to MSSQL. Install the PHP extension (e.g. `php-sybase` or build with FreeTDS) and ensure FreeTDS is configured for your SQL Server version.

If neither driver is installed, the Control Panel MSSQL sync section will still be available; Test Connection and Sync Now will fail with a clear message asking you to install the appropriate driver.

See the main README.md (Remote MSSQL Sync) and QUICKSTART.md (Step 8b) for configuration and usage.

---

See the main README.md for complete installation instructions.

