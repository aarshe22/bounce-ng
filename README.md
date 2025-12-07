# Bounce Monitor - Email Bounce Monitoring System

A comprehensive web application for monitoring email bounce mailboxes, parsing bounce messages, and managing bounce notifications.

## Features

- **Email Bounce Monitoring**: Monitor multiple IMAP mailboxes for bounce messages
- **Advanced Email Parsing**: Full support for all email encoding formats and codepages
- **Bounce Detection**: Distinguishes legitimate bounces from auto-replies and out-of-office messages
- **Trust Score Calculation**: Calculates trust scores (0-100) for recipient domains
- **SMTP Code Database**: Comprehensive database of SMTP error codes with explanations and recommendations
- **Notification System**: Send bounce notifications to original CC addresses with customizable templates
- **Queue Management**: Real-time or queued notification delivery
- **OAuth Authentication**: Login with Google or Microsoft accounts
- **User Management**: Admin panel for user management
- **Event Logging**: Comprehensive activity logging
- **Dark Mode**: Light/dark theme support
- **SPA Interface**: Modern single-page application interface

## Requirements

- PHP 8.0 or higher
- PHP extensions:
  - `ext-imap` - For IMAP mailbox access
  - `ext-pdo` - For SQLite database
  - `ext-json` - For JSON handling
  - `ext-mbstring` - For multi-byte string handling
- Composer for dependency management

## Installation

1. Clone or download this repository

2. Install dependencies:
```bash
composer install
```

3. Copy `.env.example` to `.env` and configure:
```bash
cp .env.example .env
```

4. Edit `.env` and configure:
   - OAuth credentials (Google and Microsoft)
   - SMTP settings for sending notifications
   - Application URL

5. Set up OAuth applications:
   - **Google**: Create OAuth 2.0 credentials at https://console.cloud.google.com/
   - **Microsoft**: Register an application at https://portal.azure.com/

6. Ensure the `data` directory is writable:
```bash
mkdir -p data
chmod 755 data
```

7. Start the PHP development server:
```bash
php -S localhost:8000
```

8. Access the application at `http://localhost:8000`

9. The first user to log in will automatically become an administrator

## Configuration

### OAuth Setup

#### Google OAuth
1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project or select existing
3. Enable Google+ API
4. Create OAuth 2.0 credentials
5. Add authorized redirect URI: `http://your-domain/auth/google/callback`
6. Copy Client ID and Client Secret to `.env`

#### Microsoft OAuth
1. Go to [Azure Portal](https://portal.azure.com/)
2. Register a new application
3. Add redirect URI: `http://your-domain/auth/microsoft/callback`
4. Copy Application (client) ID and Client secret to `.env`

### SMTP Configuration

Configure SMTP settings in `.env` for sending bounce notifications:
- `SMTP_HOST`: SMTP server hostname
- `SMTP_PORT`: SMTP port (usually 587 for TLS)
- `SMTP_USER`: SMTP username
- `SMTP_PASS`: SMTP password
- `SMTP_FROM_EMAIL`: From email address
- `SMTP_FROM_NAME`: From name

## Usage

### Adding a Mailbox

1. Click "Add Mailbox" button
2. Enter mailbox details:
   - Name: Descriptive name for the mailbox
   - Email: Email address of the bounce mailbox
   - IMAP Server: IMAP server hostname
   - Port: IMAP port (usually 993 for SSL, 143 for TLS)
   - Protocol: SSL, TLS, or None
   - Username and Password: IMAP credentials
3. Configure folders:
   - INBOX: Where bounce messages are read from
   - PROCESSED: Where successfully processed bounces are moved
   - PROBLEM: Where unparseable messages are moved
   - SKIPPED: Where non-bounce messages are moved
4. Use "Browse" buttons to select folders from the IMAP server
5. Test connection before saving
6. Save the mailbox

### Processing Bounces

1. Click "Run Processing" to process all enabled mailboxes
2. The system will:
   - Read messages from INBOX
   - Identify legitimate bounces
   - Parse bounce information
   - Store bounce records
   - Calculate trust scores
   - Queue notifications
   - Move messages to appropriate folders

### Notification Management

- **Real-time Mode**: Notifications are sent immediately after processing
- **Queue Mode**: Notifications are queued for manual sending
- Select notifications from the queue and click "Send Selected" to send manually

### Test Mode

Enable test mode to send all notifications to an override email address instead of the original CC addresses. Useful for testing without sending real notifications.

### User Management

Admins can:
- View all users
- Grant/revoke admin privileges
- Enable/disable users
- Delete users

## Database

The application uses SQLite database stored in `data/bounce_monitor.db`. The database is automatically created and seeded on first run.

### Tables

- `users`: User accounts
- `mailboxes`: IMAP mailbox configurations
- `bounces`: Bounce records
- `recipient_domains`: Domain trust scores
- `notifications_queue`: Pending notifications
- `events_log`: System event log
- `smtp_codes`: SMTP error code database
- `notification_template`: Bounce notification template
- `settings`: Application settings

## Security

- OAuth 2.0 authentication (no local passwords)
- First authenticated user becomes admin
- Session-based authentication
- SQL injection protection via prepared statements
- XSS protection via proper output escaping

## Troubleshooting

### IMAP Connection Issues

- Verify IMAP server, port, and protocol settings
- Check firewall rules
- Ensure credentials are correct
- Some servers require app-specific passwords

### Email Parsing Issues

- Check that messages are legitimate bounces
- Verify email encoding is supported
- Check event log for parsing errors
- Messages that can't be parsed are moved to PROBLEM folder

### Notification Sending Issues

- Verify SMTP settings in `.env`
- Check SMTP server allows sending from configured address
- Review event log for SMTP errors
- Test SMTP connection separately

## License

This project is provided as-is for use in monitoring email bounce mailboxes.

