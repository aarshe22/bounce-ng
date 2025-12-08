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

See the main README.md for complete installation instructions.

