# Installing Required Libraries

To use the improved IMAP and MIME parsing, you need to install the following Composer packages:

```bash
composer require webklex/php-imap zbateson/mail-mime-parser
```

Or if composer is not in your PATH:

```bash
php composer.phar require webklex/php-imap zbateson/mail-mime-parser
```

These libraries provide:
- **webklex/php-imap**: Robust IMAP connection handling, better folder support, and reliable message fetching
- **zbateson/mail-mime-parser**: Comprehensive MIME parsing with full support for all encodings, nested parts, and embedded messages

After installation, the application will automatically use these libraries for better email parsing and CC extraction.

