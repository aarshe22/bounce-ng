# Bounce Monitor - Email Bounce Monitoring System

A comprehensive web application for monitoring email bounce mailboxes, parsing bounce messages, managing bounce notifications, and tracking domain trust scores. Built with PHP, SQLite, and modern web technologies.

## Table of Contents

- [Overview](#overview)
- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage Guide](#usage-guide)
- [Architecture](#architecture)
- [Security](#security)
- [API Reference](#api-reference)
- [Database Schema](#database-schema)
- [Cron Jobs](#cron-jobs)
- [Troubleshooting](#troubleshooting)
- [Contributing](#contributing)
- [License](#license)

## Overview

Bounce Monitor is designed to help organizations track and manage email bounce messages efficiently. It monitors IMAP mailboxes for bounce messages, parses them to extract critical information, calculates domain trust scores, and sends notifications to original recipients when bounces occur.

### Key Capabilities

- **Multi-Mailbox Monitoring**: Monitor multiple IMAP mailboxes simultaneously
- **Intelligent Bounce Detection**: Distinguishes legitimate bounces from auto-replies, out-of-office messages, and other non-bounce emails
- **Advanced Email Parsing**: Handles all email encoding formats, MIME types, and character sets
- **Domain Trust Scoring**: Calculates trust scores (1-10) for recipient domains based on DNS records, bounce patterns, and SMTP codes
- **Domain Validation**: Validates domains via DNS lookups and identifies invalid domains
- **SMTP Code Analysis**: Comprehensive database of SMTP error codes with descriptions and recommendations
- **Notification System**: Customizable email notifications to original CC recipients
- **Queue Management**: Real-time or queued notification delivery modes
- **User Management**: Role-based access control with admin and read-only users
- **Event Logging**: Comprehensive activity and error logging
- **Dashboard Analytics**: Real-time statistics and visualizations

## Features

### Core Features

- **Email Bounce Monitoring**
  - Monitor multiple IMAP mailboxes concurrently
  - Automatic message processing and folder management
  - Support for SSL, TLS, and unencrypted IMAP connections
  - Configurable folder structure (INBOX, Processed, Problem, Skipped)

- **Advanced Email Parsing**
  - Full support for all email encoding formats (Base64, Quoted-Printable, etc.)
  - Multi-byte character set support (UTF-8, ISO-8859-*, Windows-1252, etc.)
  - MIME message parsing (multipart/alternative, multipart/mixed, etc.)
  - Header extraction (From, To, CC, Subject, Date, etc.)
  - Body text extraction from HTML and plain text

- **Bounce Detection Intelligence**
  - Distinguishes legitimate bounces from:
    - Auto-reply messages
    - Out-of-office messages
    - Vacation messages
    - Delivery receipts
    - Read receipts
  - Pattern matching for common bounce indicators
  - SMTP error code extraction

- **Domain Trust Scoring (1-10 Scale)**
  - DNS-based reputation checks (MX, A, AAAA, SPF, DMARC records)
  - Historical bounce pattern analysis
  - Permanent vs temporary failure weighting
  - SMTP code analysis
  - Spam score integration
  - Automatic recalculation on new bounces

- **Domain Validation**
  - DNS resolution checks
  - Format validation
  - Invalid domain identification
  - Email address association for invalid domains

- **SMTP Code Database**
  - Comprehensive database of SMTP error codes
  - Detailed descriptions and recommendations
  - Affected domain tracking
  - First seen / last seen timestamps

- **Notification System**
  - Customizable email templates with placeholders
  - Real-time or queued delivery modes
  - Test mode with override email address
  - Deduplication of duplicate notifications
  - Support for multiple SMTP relay providers

- **User Management**
  - OAuth 2.0 authentication (Google, Microsoft)
  - Role-based access control (Admin, Read-only)
  - First user automatically becomes admin
  - Subsequent users are read-only until approved
  - User enable/disable functionality

- **Dashboard & Analytics**
  - Real-time statistics (total bounces, domains, mailboxes, queued notifications)
  - Domain panel with trust scores and bounce history
  - SMTP code panel with affected domains
  - Notification queue with filtering and sorting
  - Event log with pagination and filtering
  - Accordion-style detail views

- **Event Logging**
  - Comprehensive activity logging
  - Severity levels (info, success, warning, error, debug)
  - User and mailbox association
  - Searchable and filterable
  - Client-side pagination

### Advanced Features

- **Cron Job Support**
  - Automated processing via `notify-cron.php`
  - Command-line flags for process-only, send-only, dedupe
  - Background execution from web interface
  - Comprehensive logging

- **Configuration Backup & Restore**
  - Export configuration as JSON
  - Import configuration from backup
  - Includes users, mailboxes, relay providers, templates, settings
  - Excludes bounce data and logs

- **Relay Provider Management**
  - Multiple SMTP relay providers
  - Per-mailbox relay provider assignment
  - Connection testing
  - TLS/SSL encryption support

- **Theme Support**
  - Light and dark themes
  - User preference persistence
  - Smooth theme transitions

## Requirements

### Server Requirements

- **PHP**: 8.0 or higher
- **PHP Extensions**:
  - `ext-imap` - For IMAP mailbox access
  - `ext-pdo` - For SQLite database
  - `ext-pdo_sqlite` - SQLite driver
  - `ext-json` - For JSON handling
  - `ext-mbstring` - For multi-byte string handling
  - `ext-curl` - For OAuth and DNS lookups (optional but recommended)
  - `ext-openssl` - For SSL/TLS connections

### System Requirements

- Web server (Apache, Nginx, or PHP built-in server)
- Write permissions for `data/` directory
- Network access to IMAP servers
- Network access to SMTP servers (for notifications)
- DNS resolution capability

### Dependencies

- Composer for PHP dependency management
- OAuth 2.0 credentials (Google and/or Microsoft)

## Installation

### Step 1: Clone Repository

```bash
git clone https://github.com/yourusername/bounce-ng.git
cd bounce-ng
```

### Step 2: Install Dependencies

```bash
composer install
```

### Step 3: Configure Environment

```bash
cp .env.example .env
```

Edit `.env` and configure:

```env
# Application
APP_URL=http://localhost:8000
APP_SECRET=your-random-secret-key-here

# Google OAuth (optional)
GOOGLE_CLIENT_ID=your-google-client-id
GOOGLE_CLIENT_SECRET=your-google-client-secret
GOOGLE_REDIRECT_URI=http://localhost:8000/oauth-callback.php?provider=google

# Microsoft OAuth (optional)
MICROSOFT_CLIENT_ID=your-microsoft-client-id
MICROSOFT_CLIENT_SECRET=your-microsoft-client-secret
MICROSOFT_REDIRECT_URI=http://localhost:8000/oauth-callback.php?provider=microsoft
```

### Step 4: Set Up OAuth Applications

#### Google OAuth Setup

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project or select existing
3. Enable Google+ API
4. Go to "Credentials" → "Create Credentials" → "OAuth 2.0 Client ID"
5. Application type: Web application
6. Add authorized redirect URI: `http://your-domain/oauth-callback.php?provider=google`
7. Copy Client ID and Client Secret to `.env`

#### Microsoft OAuth Setup

1. Go to [Azure Portal](https://portal.azure.com/)
2. Navigate to "Azure Active Directory" → "App registrations"
3. Click "New registration"
4. Name: Bounce Monitor
5. Supported account types: Your choice
6. Redirect URI: `http://your-domain/oauth-callback.php?provider=microsoft` (Web platform)
7. After creation, go to "Certificates & secrets" → "New client secret"
8. Copy Application (client) ID and Client secret to `.env`

### Step 5: Set Up Directory Permissions

```bash
mkdir -p data
chmod 755 data
```

### Step 6: Start Application

#### Development Server

```bash
php -S localhost:8000
```

#### Production Server

Configure your web server (Apache/Nginx) to point to the repository root. Ensure:
- `index.php` is the default file
- `.env` file is not publicly accessible
- `data/` directory is writable by web server user

### Step 7: First Login

1. Navigate to `http://localhost:8000` (or your configured URL)
2. Click "Sign in with Google" or "Sign in with Microsoft"
3. Complete OAuth authentication
4. **The first user automatically becomes an administrator**
5. All subsequent users are read-only until approved by an admin

## Configuration

### Mailbox Configuration

Each mailbox requires:

- **Name**: Descriptive name for the mailbox
- **Email**: Email address of the bounce mailbox
- **IMAP Server**: IMAP server hostname (e.g., `imap.gmail.com`)
- **Port**: IMAP port (993 for SSL, 143 for TLS, 143 for None)
- **Protocol**: SSL, TLS, or None
- **Username**: IMAP username (usually email address)
- **Password**: IMAP password (may require app-specific password)
- **Folders**:
  - **INBOX**: Where bounce messages are read from
  - **PROCESSED**: Where successfully processed bounces are moved
  - **PROBLEM**: Where unparseable messages are moved
  - **SKIPPED**: Where non-bounce messages are moved

### Relay Provider Configuration

Configure SMTP relay providers for sending notifications:

- **Name**: Descriptive name
- **SMTP Host**: SMTP server hostname
- **Port**: SMTP port (587 for TLS, 465 for SSL)
- **Encryption**: TLS or SSL
- **Username**: SMTP username
- **Password**: SMTP password
- **From Email**: Sender email address
- **From Name**: Sender display name

### Notification Template

Customize the bounce notification email template with placeholders:

- `{{original_to}}` - Original TO address
- `{{original_cc}}` - Original CC addresses
- `{{original_subject}}` - Original email subject
- `{{bounce_date}}` - Bounce date/time
- `{{smtp_code}}` - SMTP error code
- `{{smtp_reason}}` - SMTP error reason
- `{{recipient_domain}}` - Recipient domain
- `{{recommendation}}` - SMTP code recommendation

### Settings

- **Test Mode**: When enabled, all notifications go to override email
- **Test Mode Override Email**: Email address for test mode notifications
- **Real-time Notifications**: When enabled, notifications are sent immediately after processing
- **Queue Mode**: When disabled, notifications are queued for manual sending

## Usage Guide

### Adding a Mailbox

1. Navigate to **Control Panel**
2. Click **"Add Mailbox"** button
3. Fill in mailbox details:
   - Name, Email, IMAP Server, Port, Protocol
   - Username and Password
4. Configure folders (use "Browse" buttons to select from IMAP server)
5. Optionally select a Relay Provider for this mailbox
6. Click **"Test Connection"** to verify settings
7. Click **"Save"** to add the mailbox

### Processing Bounces

#### Manual Processing

1. Navigate to **Control Panel**
2. Click **"Run Processing"** button
3. System will:
   - Connect to all enabled mailboxes
   - Read messages from INBOX
   - Identify legitimate bounces
   - Parse bounce information
   - Store bounce records
   - Calculate/update trust scores
   - Queue notifications (if queue mode)
   - Move messages to appropriate folders

#### Automated Processing (Cron)

Set up a cron job to run `notify-cron.php`:

```bash
# Process every 5 minutes
*/5 * * * * cd /path/to/bounce-ng && php notify-cron.php
```

Or use the **"RUN CRON"** button in the header for manual execution.

### Notification Management

#### Real-time Mode

- Notifications are sent immediately after processing
- No manual intervention required
- Best for production environments

#### Queue Mode

- Notifications are queued for manual review
- View queue in **Dashboard** or **Notification Queue** page
- Select notifications and click **"Send Selected"**
- Use **"Deduplicate"** to remove duplicate notifications

#### Test Mode

- Enable in **Control Panel** → **Test Mode**
- Set override email address
- All notifications go to override email instead of original recipients
- Useful for testing without sending real notifications

### User Management

**Admin-only feature**

1. Click user icon in header → **"User Management"**
2. View all users with their status
3. Toggle **Admin** checkbox to grant/revoke admin privileges
4. Toggle **Active** checkbox to enable/disable users
5. Click **"Delete"** to remove users (cannot delete yourself)

**Note**: First user is automatically admin. All subsequent users are read-only until approved.

### Dashboard

The dashboard provides:

- **Statistics**: Total bounces, domains, active mailboxes, queued notifications
- **Domains Panel**: 
  - List of all recipient domains
  - Trust scores (1-10)
  - Bounce counts and failure types
  - Click domain to expand details (recent bounces, SMTP codes, timeline)
- **SMTP Codes Panel**:
  - List of all SMTP error codes
  - Occurrence counts and affected domains
  - Click code to expand and see affected domains
- **Notification Queue**: Pending notifications with filtering and sorting

### Event Log

- View all system events
- Filter by severity (info, success, warning, error, debug)
- Search events by text
- Sort by date or severity
- Paginated display (50+ rows per page)

### Configuration Backup & Restore

**Admin-only feature**

1. Navigate to **Control Panel**
2. Click **"Backup Configuration"** to download JSON backup
3. To restore, select backup file and click **"Restore from Backup"**

Backup includes:
- Users (except passwords)
- Mailboxes (except passwords)
- Relay Providers (except passwords)
- Notification template
- Settings

Backup excludes:
- Bounce data
- Event logs
- Notification queue

## Architecture

### Directory Structure

```
bounce-ng/
├── api/                 # API endpoints
│   ├── backup.php      # Configuration backup/restore
│   ├── cron.php        # Cron execution endpoint
│   ├── dashboard.php   # Dashboard data
│   ├── events.php      # Event log API
│   ├── mailboxes.php   # Mailbox management
│   ├── notifications.php # Notification management
│   ├── relay-providers.php # Relay provider management
│   ├── settings.php    # Settings management
│   ├── smtp-codes.php  # SMTP code management
│   └── users.php       # User management
├── auth/               # OAuth authentication
│   ├── google/         # Google OAuth
│   └── microsoft/      # Microsoft OAuth
├── data/               # Data directory (database, logs)
├── public/             # Public web files
│   ├── index.html      # Main SPA interface
│   ├── app.js          # Frontend JavaScript
│   └── styles.css      # Stylesheets
├── src/                # PHP source code
│   ├── Auth.php        # Authentication
│   ├── Database.php    # Database management
│   ├── DomainValidator.php # Domain validation
│   ├── EmailParser.php # Email parsing
│   ├── EventLogger.php # Event logging
│   ├── MailboxMonitor.php # Mailbox monitoring
│   ├── NotificationSender.php # Notification sending
│   └── TrustScoreCalculator.php # Trust score calculation
├── notify-cron.php      # Cron script
├── index.php           # Main entry point
├── login.php          # Login page
├── config.php          # Configuration loader
├── composer.json       # PHP dependencies
└── README.md           # This file
```

### Technology Stack

- **Backend**: PHP 8.0+
- **Database**: SQLite 3
- **Frontend**: Vanilla JavaScript, Bootstrap 5, Bootstrap Icons
- **Authentication**: OAuth 2.0 (Google, Microsoft)
- **Email**: IMAP (PHP ext-imap), SMTP (PHPMailer)
- **Dependencies**: Composer

### Key Components

#### MailboxMonitor

- Connects to IMAP mailboxes
- Reads and processes messages
- Identifies bounce messages
- Parses bounce information
- Moves messages to appropriate folders

#### EmailParser

- Parses email headers and body
- Handles all encoding formats
- Extracts bounce information
- Identifies SMTP codes and reasons
- Determines deliverability status

#### TrustScoreCalculator

- Calculates domain trust scores (1-10)
- Performs DNS reputation checks
- Analyzes bounce patterns
- Updates scores on new bounces

#### DomainValidator

- Validates domain format
- Checks DNS resolution
- Identifies invalid domains
- Provides validation reasons

#### NotificationSender

- Sends bounce notifications
- Uses configured relay providers
- Supports test mode
- Handles delivery errors

## Security

### Authentication

- **OAuth 2.0**: No local passwords stored
- **Session-based**: Secure session management
- **Role-based Access Control**: Admin and read-only roles
- **First User Admin**: First authenticated user becomes admin
- **Read-only Default**: Subsequent users are read-only until approved

### Data Protection

- **SQL Injection Protection**: Prepared statements throughout
- **XSS Protection**: Proper output escaping
- **CSRF Protection**: Session-based state validation
- **Secure Cookies**: HttpOnly and secure flags
- **Input Validation**: All user input validated

### Access Control

**Admin Users Can**:
- Run processing
- Send notifications
- Deduplicate notifications
- Reset database
- Manage users
- Configure mailboxes and relay providers
- Backup/restore configuration
- Modify settings and templates

**Read-only Users Can**:
- View dashboard
- View event log
- View notification queue
- Filter and sort data
- **Cannot** perform any write operations

### Best Practices

- Keep `.env` file secure and never commit to version control
- Use strong `APP_SECRET` for session security
- Regularly update dependencies
- Monitor event log for suspicious activity
- Use test mode for initial setup
- Backup configuration regularly

## API Reference

### Authentication

All API endpoints require authentication via session. Admin-only endpoints require admin privileges.

### Endpoints

#### `/api/dashboard.php`
- **GET**: Get dashboard data (domains, SMTP codes, statistics)

#### `/api/mailboxes.php`
- **GET** `?action=list`: List all mailboxes
- **GET** `?action=get&id={id}`: Get mailbox details
- **GET** `?action=test&id={id}`: Test mailbox connection
- **GET** `?action=folders&id={id}`: List mailbox folders
- **POST** `?action=create`: Create mailbox (admin)
- **POST** `?action=update`: Update mailbox (admin)
- **POST** `?action=process`: Process mailboxes (admin)
- **POST** `?action=retroactive-queue`: Queue notifications from existing bounces (admin)
- **POST** `?action=reset-database`: Reset database (admin)
- **DELETE** `?action=delete&id={id}`: Delete mailbox (admin)

#### `/api/notifications.php`
- **GET** `?action=queue&status={status}`: Get notification queue
- **POST** `?action=send`: Send selected notifications (admin)
- **POST** `?action=send-all`: Send all pending notifications (admin)
- **POST** `?action=deduplicate`: Deduplicate notifications (admin)

#### `/api/events.php`
- **GET**: Get event log (paginated)

#### `/api/users.php`
- **GET** `?action=list`: List all users (admin)
- **PUT** `?action=update`: Update user (admin)
- **DELETE** `?action=delete&id={id}`: Delete user (admin)

#### `/api/settings.php`
- **GET** `?action=get`: Get all settings
- **GET** `?action=template`: Get notification template
- **POST** `?action=set`: Set setting (admin)
- **POST** `?action=template`: Update template (admin)

#### `/api/cron.php`
- **POST** `?action=run`: Run cron script (admin)

#### `/api/backup.php`
- **GET** `?action=export`: Export configuration (admin)
- **POST** `?action=import`: Import configuration (admin)

## Database Schema

### Tables

#### `users`
- User accounts with OAuth provider information
- Admin and active flags

#### `mailboxes`
- IMAP mailbox configurations
- Folder mappings
- Relay provider associations

#### `bounces`
- Bounce message records
- Original email information
- SMTP codes and reasons
- Deliverability status

#### `recipient_domains`
- Domain trust scores
- Bounce counts
- Last bounce dates

#### `notifications_queue`
- Pending notifications
- Status tracking
- Error messages

#### `events_log`
- System event log
- Severity levels
- User and mailbox associations

#### `smtp_codes`
- SMTP error code database
- Descriptions and recommendations

#### `notification_template`
- Email notification template
- Subject and body

#### `settings`
- Application settings
- Key-value pairs

#### `relay_providers`
- SMTP relay provider configurations
- Connection details

## Cron Jobs

### notify-cron.php

Automated processing script for cron execution.

**Usage**:
```bash
# Full processing (default)
php notify-cron.php

# Process only (no sending)
php notify-cron.php --process-only

# Send only (no processing)
php notify-cron.php --send-only

# Deduplicate notifications
php notify-cron.php --dedupe

# Combine flags
php notify-cron.php --process-only --dedupe
```

**Cron Examples**:
```bash
# Every 5 minutes
*/5 * * * * cd /path/to/bounce-ng && php notify-cron.php

# Every 15 minutes with deduplication
*/15 * * * * cd /path/to/bounce-ng && php notify-cron.php --dedupe

# Hourly processing only
0 * * * * cd /path/to/bounce-ng && php notify-cron.php --process-only
```

**Exit Codes**:
- `0`: Success
- `1`: General error
- `2`: Processing error
- `3`: Sending error
- `4`: Deduplication error

**Logging**:
- Logs to `notify-cron.log` in application root
- Also logs to `events_log` table
- All log messages prefixed with `[CRON]`

## Troubleshooting

### IMAP Connection Issues

**Symptoms**: Cannot connect to mailbox, connection timeouts

**Solutions**:
- Verify IMAP server, port, and protocol settings
- Check firewall rules allow IMAP access
- Ensure credentials are correct
- Some providers (Gmail) require app-specific passwords
- Test connection using "Test Connection" button
- Check event log for detailed error messages

### Email Parsing Issues

**Symptoms**: Messages moved to PROBLEM folder, parsing errors

**Solutions**:
- Verify messages are legitimate bounces
- Check email encoding is supported
- Review event log for parsing errors
- Some bounce formats may not be fully supported
- Check that bounce messages contain standard SMTP error information

### Notification Sending Issues

**Symptoms**: Notifications not sending, SMTP errors

**Solutions**:
- Verify SMTP relay provider settings
- Test SMTP connection separately
- Check SMTP server allows sending from configured address
- Review event log for SMTP errors
- Ensure relay provider is active
- Check for rate limiting on SMTP server

### Domain Validation Issues

**Symptoms**: Valid domains marked as invalid

**Solutions**:
- Check DNS resolution (may be temporary DNS issues)
- Verify domain format is correct
- Some domains may only have MX records (no A records)
- DNS lookups have 2-second timeout

### Trust Score Issues

**Symptoms**: Trust scores seem incorrect

**Solutions**:
- Trust scores recalculate on each new bounce
- Scores are based on historical patterns
- DNS checks may fail temporarily
- Review bounce history for domain
- Check SMTP codes affecting score

### Performance Issues

**Symptoms**: Slow processing, timeouts

**Solutions**:
- Reduce number of messages processed per run
- Increase PHP execution time limits
- Check IMAP server performance
- Consider processing mailboxes separately
- Monitor event log for bottlenecks

### User Access Issues

**Symptoms**: Cannot perform actions, "Forbidden" errors

**Solutions**:
- Verify user has admin privileges
- Check user is active
- First user should be admin automatically
- Subsequent users need admin approval
- Check event log for access denied messages

## Contributing

Contributions are welcome! Please follow these guidelines:

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Test thoroughly
5. Submit a pull request

### Development Setup

1. Clone repository
2. Run `composer install`
3. Copy `.env.example` to `.env` and configure
4. Set up OAuth credentials
5. Start development server: `php -S localhost:8000`

### Code Style

- Follow PSR-12 coding standards
- Use meaningful variable and function names
- Add comments for complex logic
- Update documentation for new features

## License

This project is provided as-is for use in monitoring email bounce mailboxes. See LICENSE file for details.

## Support

For issues, questions, or contributions:
- Open an issue on GitHub
- Check the event log for error details
- Review troubleshooting section
- Consult the help system within the application

---

**Version**: 1.0.0  
**Last Updated**: 2024
