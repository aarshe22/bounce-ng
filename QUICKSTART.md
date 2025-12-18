# Quick Start Guide

Get Bounce Monitor up and running in minutes.

## Prerequisites

- PHP 8.0 or higher
- PHP extensions: `ext-imap`, `ext-pdo`, `ext-pdo_sqlite`, `ext-json`, `ext-mbstring`
- Composer installed
- OAuth credentials (Google and/or Microsoft) - see setup below
- Web server (Apache, Nginx, or PHP built-in server)

## Step 1: Install

```bash
# Clone the repository
git clone https://github.com/yourusername/bounce-ng.git
cd bounce-ng

# Install dependencies (automatically creates data/ directory)
composer install
```

The `composer install` command automatically:
- Creates the `data/` directory
- Sets proper permissions (755)
- Creates necessary files

## Step 2: Configure OAuth

You need OAuth credentials for authentication. Choose one or both:

### Google OAuth (Recommended for Quick Start)

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project or select existing
3. Enable Google+ API
4. Go to "Credentials" → "Create Credentials" → "OAuth 2.0 Client ID"
5. Application type: **Web application**
6. Add authorized redirect URI: `http://localhost:8000/oauth-callback.php?provider=google` (or your domain)
7. Copy **Client ID** and **Client Secret**

### Microsoft OAuth

1. Go to [Azure Portal](https://portal.azure.com/)
2. Navigate to "Azure Active Directory" → "App registrations"
3. Click "New registration"
4. Name: Bounce Monitor
5. Redirect URI: `http://localhost:8000/oauth-callback.php?provider=microsoft` (Web platform)
6. After creation, go to "Certificates & secrets" → "New client secret"
7. Copy **Application (client) ID** and **Client secret**

## Step 3: Set Up Environment

```bash
# Copy example environment file
cp .env.example .env

# Edit .env file with your OAuth credentials
nano .env  # or use your preferred editor
```

Minimum required configuration in `.env`:

```env
# Application
APP_URL=http://localhost:8000
APP_SECRET=your-random-secret-key-here-minimum-32-characters

# Google OAuth (at least one OAuth provider required)
GOOGLE_CLIENT_ID=your-google-client-id
GOOGLE_CLIENT_SECRET=your-google-client-secret
GOOGLE_REDIRECT_URI=http://localhost:8000/oauth-callback.php?provider=google

# Microsoft OAuth (optional)
MICROSOFT_CLIENT_ID=your-microsoft-client-id
MICROSOFT_CLIENT_SECRET=your-microsoft-client-secret
MICROSOFT_REDIRECT_URI=http://localhost:8000/oauth-callback.php?provider=microsoft
```

**Important**: 
- Replace `http://localhost:8000` with your actual domain in production
- Generate a strong random `APP_SECRET` (minimum 32 characters)

## Step 4: Start the Application

### Development (PHP Built-in Server)

```bash
php -S localhost:8000
```

### Production

Configure your web server (Apache/Nginx) to point to the repository root.

## Step 5: First Login

1. Open your browser: `http://localhost:8000` (or your configured URL)
2. Click **"Sign in with Google"** or **"Sign in with Microsoft"**
3. Complete OAuth authentication
4. **Important**: The first user automatically becomes an **administrator**
5. All subsequent users are **read-only** until approved by an admin

## Step 6: Add Your First Mailbox

1. After logging in, you'll see the **Dashboard**
2. Click **"Control Panel"** in the header
3. Click **"Add Mailbox"** button
4. Fill in mailbox details:
   - **Name**: Descriptive name (e.g., "Main Bounce Mailbox")
   - **Email**: The bounce mailbox email address
   - **IMAP Server**: IMAP server hostname
     - Gmail: `imap.gmail.com`
     - Outlook: `outlook.office365.com`
     - Custom: Your IMAP server
   - **Port**: 
     - SSL: `993`
     - TLS: `143`
     - None: `143`
   - **Protocol**: Select SSL, TLS, or None
   - **Username**: Usually your email address
   - **Password**: Your password (Gmail may require an app-specific password)
5. Click **"Browse"** next to folder fields to select folders from your IMAP server
6. Click **"Test Connection"** to verify settings
7. Click **"Save"** to add the mailbox

### Gmail Setup Notes

- Enable "Less secure app access" or use an **App Password**
- To create an App Password:
  1. Go to Google Account → Security
  2. Enable 2-Step Verification
  3. Go to App passwords
  4. Create password for "Mail"
  5. Use this password instead of your regular password

## Step 7: Configure Notification Settings

1. In **Control Panel**, configure notification settings:
   - **Test Mode**: Enable for initial testing
   - **Test Mode Override Email**: Your email address (all notifications go here in test mode)
   - **Real-time Notifications**: Enable to send immediately, or disable to queue
   - **BCC Monitoring**: (Optional) Add email addresses to BCC all production notifications

2. **Notification Template**: Customize the email template if desired
   - Available placeholders: `{{original_to}}`, `{{original_cc}}`, `{{bounce_date}}`, `{{smtp_code}}`, etc.

## Step 8: Add SMTP Relay Provider (For Notifications)

1. In **Control Panel**, scroll to **"Relay Providers"**
2. Click **"Add Relay Provider"**
3. Fill in SMTP details:
   - **Name**: Descriptive name
   - **SMTP Host**: SMTP server (e.g., `smtp.gmail.com`, `smtp.office365.com`)
   - **Port**: Usually `587` (TLS) or `465` (SSL)
   - **Encryption**: TLS or SSL
   - **Username**: SMTP username
   - **Password**: SMTP password
   - **From Email**: Sender email address
   - **From Name**: Sender display name
4. Click **"Test Connection"** to verify
5. Click **"Save"**
6. Assign this relay provider to your mailbox (edit mailbox → select relay provider)

## Step 9: Process Your First Bounces

### Manual Processing

1. Go to **Control Panel**
2. Click **"Run Processing"** button
3. System will:
   - Connect to all enabled mailboxes
   - Read messages from INBOX
   - Identify and parse bounce messages
   - Store bounce records
   - Queue notifications (if queue mode)
   - Move messages to appropriate folders

### Automated Processing (Recommended)

Set up a cron job:

```bash
# Edit crontab
crontab -e

# Add this line (runs every 5 minutes)
*/5 * * * * cd /path/to/bounce-ng && php notify-cron.php
```

Or use the **"RUN CRON"** button in the header for manual execution.

## Step 10: View Results

### Dashboard

- **Statistics**: Total bounces, domains, mailboxes, queued notifications
- **Timeline Charts**: Visualize bounce trends over time
- **Domains Panel**: View recipient domains with trust scores
- **SMTP Codes Panel**: View error codes and affected domains
- **Notification Queue**: Pending notifications ready to send

### Bad Addresses

- Click **"Bad Addresses"** in header
- View all bounced email addresses
- Export to CSV for external analysis

### Event Log

- Click **"Event Log"** in header
- View all system activities and errors
- Filter by severity, search, and paginate

## Next Steps

- **Review Dashboard**: Check bounce statistics and domain trust scores
- **Send Notifications**: If in queue mode, go to Dashboard → Notification Queue → Send Selected
- **Add More Mailboxes**: Monitor multiple bounce mailboxes
- **Configure Cron**: Set up automated processing
- **User Management**: Add additional users (admin-only)
- **Backup Configuration**: Export settings for backup (admin-only)

## Troubleshooting

### Cannot Connect to Mailbox

- Verify IMAP server, port, and protocol
- Check credentials (use app password for Gmail)
- Test connection using "Test Connection" button
- Check event log for detailed errors

### Notifications Not Sending

- Verify SMTP relay provider settings
- Test SMTP connection
- Check event log for SMTP errors
- Ensure test mode is disabled for production

### No Bounces Detected

- Verify messages are legitimate bounces
- Check that bounce messages contain SMTP error codes
- Review event log for parsing errors
- Some bounce formats may not be fully supported

## Getting Help

- Check the **Help** button in the header for comprehensive documentation
- Review the **Event Log** for error details
- See [README.md](README.md) for full documentation
- Check troubleshooting section in README

---

**Congratulations!** You're now ready to monitor email bounces. 🎉

