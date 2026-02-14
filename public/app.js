// Global state
let currentMailboxId = null;
let eventPollInterval = null;
let userIsAdmin = false; // Will be set from session

// Event log pagination state
let eventLogCurrentPage = 1;
let eventLogPageSize = 50; // Will be calculated based on viewport
let eventLogTotalEvents = 0;
let eventLogAllEvents = []; // Store all events for client-side pagination

// Timezone for event log display (default to UTC, will be loaded from settings)
window.eventLogTimezone = 'UTC';

// Initialize app
document.addEventListener('DOMContentLoaded', function() {
    loadUserInfo();
    loadSettings();
    loadNotificationTemplate();
    loadRelayProviders();
    loadMailboxes();
    loadDashboard();
    loadEventLog();
    loadNotificationQueue();
    
    // Initialize view switching
    initializeViews();
    
    // Calculate event log page size based on viewport
    calculateEventLogPageSize();
    window.addEventListener('resize', calculateEventLogPageSize);
    
    // Add click handler for refresh button
    const refreshBtn = document.getElementById('refreshLogBtn');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', loadEventLog);
    }
    
    // Add change handlers for event log filtering and sorting
    const eventFilter = document.getElementById('eventFilter');
    if (eventFilter) {
        eventFilter.addEventListener('change', function() {
            eventLogCurrentPage = 1;
            applyEventLogFilters();
        });
    }
    
    const eventLogSearch = document.getElementById('eventLogSearch');
    if (eventLogSearch) {
        eventLogSearch.addEventListener('input', function() {
            eventLogCurrentPage = 1;
            applyEventLogFilters();
        });
    }
    
    const eventLogSort = document.getElementById('eventLogSort');
    if (eventLogSort) {
        eventLogSort.addEventListener('change', function() {
            eventLogCurrentPage = 1;
            applyEventLogFilters();
        });
    }
    
    // Add change handlers for notification queue filtering and sorting
    // Filter can be applied with Enter key or Apply button
    const notificationQueueFilter = document.getElementById('notificationQueueFilter');
    if (notificationQueueFilter) {
        notificationQueueFilter.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                applyNotificationQueueFilters();
            }
        });
    }
    
    // Sort changes immediately when dropdown changes
    const notificationQueueSort = document.getElementById('notificationQueueSort');
    if (notificationQueueSort) {
        notificationQueueSort.addEventListener('change', function() {
            applyNotificationQueueFilters();
        });
    }
    
    // No auto-refresh for event log - user can manually refresh or it auto-refreshes during processing
    // Poll dashboard every 10 seconds
    setInterval(loadDashboard, 10000);
    setInterval(loadNotificationQueue, 5000);
    
    // Set up BCC monitoring toggle event listener
    const bccMonitoringToggle = document.getElementById('bccMonitoringToggle');
    if (bccMonitoringToggle) {
        bccMonitoringToggle.addEventListener('change', function() {
            document.getElementById('bccMonitoringEmails').style.display = this.checked ? 'block' : 'none';
            saveBccMonitoringSettings();
        });
    }
});

// View switching functions
function switchView(viewName) {
    // Hide all views
    document.querySelectorAll('.view-container').forEach(view => {
        view.style.display = 'none';
    });
    
    // Remove active class from all buttons
    document.querySelectorAll('#viewControlsBtn, #viewDashboardBtn, #viewEventsBtn, #viewNotificationsBtn, #viewBadAddressesBtn').forEach(btn => {
        btn.classList.remove('active');
    });
    
    // Show selected view and activate button
    if (viewName === 'controls') {
        document.getElementById('viewControls').style.display = 'block';
        document.getElementById('viewControlsBtn').classList.add('active');
    } else if (viewName === 'dashboard') {
        document.getElementById('viewDashboard').style.display = 'block';
        document.getElementById('viewDashboardBtn').classList.add('active');
    } else if (viewName === 'notifications') {
        document.getElementById('viewNotifications').style.display = 'block';
        document.getElementById('viewNotificationsBtn').classList.add('active');
        // Load notification queue when switching to this view
        loadNotificationQueue();
        // Ensure the view container shows content
        setTimeout(() => {
            const container = document.getElementById('notificationQueueView');
            if (container && (!container.innerHTML || container.innerHTML.trim() === '')) {
                if (allNotifications.length === 0) {
                    container.innerHTML = '<p class="text-muted">No pending notifications</p>';
                } else {
                    applyNotificationQueueFilters();
                }
            }
        }, 100);
    } else if (viewName === 'events') {
        document.getElementById('viewEvents').style.display = 'block';
        document.getElementById('viewEventsBtn').classList.add('active');
        // Recalculate page size when switching to events view (container is now visible)
        setTimeout(() => {
            calculateEventLogPageSize();
            displayEventLogPage(); // Refresh display with new page size
        }, 100);
    } else if (viewName === 'badAddresses') {
        document.getElementById('viewBadAddresses').style.display = 'block';
        document.getElementById('viewBadAddressesBtn').classList.add('active');
        loadBadAddresses();
    }
}

function initializeViews() {
    // Set default view to Dashboard
    switchView('dashboard');
}

// Event log pagination functions
function calculateEventLogPageSize() {
    const eventLogContainer = document.getElementById('eventLog');
    if (!eventLogContainer) return;
    
    // Calculate how many events fit in the viewport - maximize rows per page
    // Measure actual line height by creating a test element
    const testEvent = document.createElement('div');
    testEvent.className = 'event info';
    testEvent.innerHTML = '<span class="text-muted">[ID:99999]</span> <span class="text-muted">2025-12-07 12:00:00</span> <strong>INFO</strong>: Test event for height calculation';
    testEvent.style.visibility = 'hidden';
    testEvent.style.position = 'absolute';
    eventLogContainer.appendChild(testEvent);
    
    const actualLineHeight = testEvent.offsetHeight || 20; // Fallback to 20px if measurement fails
    eventLogContainer.removeChild(testEvent);
    
    // Get container height (accounting for padding)
    const containerHeight = eventLogContainer.clientHeight;
    const containerPadding = 16; // 8px top + 8px bottom
    const availableHeight = containerHeight - containerPadding;
    
    // Calculate maximum rows that fit
    const maxRows = Math.floor(availableHeight / actualLineHeight);
    
    // Use maximum rows, but ensure at least 50
    eventLogPageSize = Math.max(50, maxRows);
}

function eventLogPrevPage() {
    if (eventLogCurrentPage > 1) {
        eventLogCurrentPage--;
        displayEventLogPage();
    }
}

function eventLogNextPage() {
    const eventsToPage = eventLogFilteredEvents.length > 0 ? eventLogFilteredEvents : eventLogAllEvents;
    const totalPages = Math.ceil(eventsToPage.length / eventLogPageSize);
    if (eventLogCurrentPage < totalPages) {
        eventLogCurrentPage++;
        displayEventLogPage();
    }
}

// Store filtered/sorted events for pagination
let eventLogFilteredEvents = [];

function applyEventLogFilters() {
    // Get filter values
    const severityFilter = document.getElementById('eventFilter')?.value || '';
    const searchText = document.getElementById('eventLogSearch')?.value.toLowerCase() || '';
    const sortValue = document.getElementById('eventLogSort')?.value || 'id_desc';
    
    // Filter events
    let filtered = eventLogAllEvents.filter(event => {
        // Severity filter
        if (severityFilter && event.severity !== severityFilter) {
            return false;
        }
        
        // Search filter
        if (searchText) {
            const searchableText = `${event.id || ''} ${event.severity || ''} ${event.message || ''} ${event.created_at || ''}`.toLowerCase();
            if (!searchableText.includes(searchText)) {
                return false;
            }
        }
        
        return true;
    });
    
    // Sort events
    filtered.sort((a, b) => {
        switch(sortValue) {
            case 'id_desc':
                return (b.id || 0) - (a.id || 0);
            case 'id_asc':
                return (a.id || 0) - (b.id || 0);
            case 'severity_asc':
                return (a.severity || '').localeCompare(b.severity || '');
            case 'severity_desc':
                return (b.severity || '').localeCompare(a.severity || '');
            default:
                return (b.id || 0) - (a.id || 0);
        }
    });
    
    // Store filtered events
    eventLogFilteredEvents = filtered;
    
    // Reset to first page and display
    eventLogCurrentPage = 1;
    displayEventLogPage();
}

function displayEventLogPage() {
    const container = document.getElementById('eventLog');
    if (!container) return;
    
    container.innerHTML = '';
    
    // Use filtered events for pagination
    const eventsToPage = eventLogFilteredEvents.length > 0 ? eventLogFilteredEvents : eventLogAllEvents;
    
    // Calculate pagination
    const startIndex = (eventLogCurrentPage - 1) * eventLogPageSize;
    const endIndex = startIndex + eventLogPageSize;
    const pageEvents = eventsToPage.slice(startIndex, endIndex);
    const totalPages = Math.ceil(eventsToPage.length / eventLogPageSize);
    
    // Display events for current page
    pageEvents.forEach(event => {
        const item = document.createElement('div');
        item.className = `event ${event.severity}`;
        
        // Format timestamp if available
        let timestampDisplay = '';
        if (event.created_at) {
            try {
                const date = new Date(event.created_at);
                // Use configured timezone or default to UTC
                const timezone = window.eventLogTimezone || 'UTC';
                
                // Format date in the configured timezone
                const formatter = new Intl.DateTimeFormat('en-US', {
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit',
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit',
                    hour12: false,
                    timeZone: timezone
                });
                
                const parts = formatter.formatToParts(date);
                const year = parts.find(p => p.type === 'year').value;
                const month = parts.find(p => p.type === 'month').value;
                const day = parts.find(p => p.type === 'day').value;
                const hours = parts.find(p => p.type === 'hour').value;
                const minutes = parts.find(p => p.type === 'minute').value;
                const seconds = parts.find(p => p.type === 'second').value;
                
                timestampDisplay = `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
            } catch (e) {
                // Fallback to original format if timezone formatting fails
                const date = new Date(event.created_at);
                const year = date.getFullYear();
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const day = String(date.getDate()).padStart(2, '0');
                const hours = String(date.getHours()).padStart(2, '0');
                const minutes = String(date.getMinutes()).padStart(2, '0');
                const seconds = String(date.getSeconds()).padStart(2, '0');
                timestampDisplay = `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
            }
        }
        
        const idDisplay = event.id ? `[ID:${event.id}]` : '';
        const timestampPart = timestampDisplay ? `<span class="text-muted">${timestampDisplay}</span> ` : '';
        item.innerHTML = `<span class="text-muted">${idDisplay}</span> ${timestampPart}<strong>${event.severity.toUpperCase()}</strong>: ${event.message}`;
        container.appendChild(item);
    });
    
    // Update pagination controls
    const prevBtn = document.getElementById('eventLogPrevBtn');
    const nextBtn = document.getElementById('eventLogNextBtn');
    const pageInfo = document.getElementById('eventLogPageInfo');
    const logInfo = document.getElementById('eventLogInfo');
    
    if (prevBtn) prevBtn.disabled = eventLogCurrentPage <= 1;
    if (nextBtn) nextBtn.disabled = eventLogCurrentPage >= totalPages;
    if (pageInfo) pageInfo.textContent = `Page ${eventLogCurrentPage} of ${totalPages}`;
    if (logInfo) {
        const totalFiltered = eventsToPage.length;
        const totalAll = eventLogAllEvents.length;
        if (totalFiltered < totalAll) {
            logInfo.textContent = `Showing ${startIndex + 1}-${Math.min(endIndex, totalFiltered)} of ${totalFiltered} events (${totalAll} total)`;
        } else {
            logInfo.textContent = `Showing ${startIndex + 1}-${Math.min(endIndex, totalFiltered)} of ${totalFiltered} events`;
        }
    }
}

// Theme toggle
document.getElementById('themeToggle').addEventListener('click', function() {
    const currentTheme = document.body.getAttribute('data-theme');
    const newTheme = currentTheme === 'light' ? 'dark' : 'light';
    document.body.setAttribute('data-theme', newTheme);
    document.getElementById('themeIcon').className = newTheme === 'light' ? 'bi bi-sun' : 'bi bi-moon';
    localStorage.setItem('theme', newTheme);
});

// Load saved theme
const savedTheme = localStorage.getItem('theme') || 'light';
document.body.setAttribute('data-theme', savedTheme);
document.getElementById('themeIcon').className = savedTheme === 'light' ? 'bi bi-sun' : 'bi bi-moon';

// User info
function loadUserInfo() {
    // Get user info from session (injected in index.php)
    const userName = document.getElementById('userName');
    const userRoleBadge = document.getElementById('userRoleBadge');
    
    if (userName) {
        // Check if admin status is in data attribute
        const isAdminAttr = userName.getAttribute('data-is-admin');
        if (isAdminAttr !== null) {
            userIsAdmin = isAdminAttr === '1';
        }
        
        // Update role badge
        if (userRoleBadge) {
            if (userIsAdmin) {
                userRoleBadge.textContent = 'Admin';
                userRoleBadge.className = 'badge me-2 bg-success';
                userRoleBadge.style.display = '';
            } else {
                userRoleBadge.textContent = 'Read Only';
                userRoleBadge.className = 'badge me-2 bg-secondary';
                userRoleBadge.style.display = '';
            }
        }
        
        // Update UI based on admin status
        updateUIForAdminStatus();
    }
    
    // Also check session on page load
    // The userName element should already have the name from index.php
}

function updateUIForAdminStatus() {
    // Hide/disable write operation buttons for non-admin users
    const writeButtons = [
        { selector: 'button[onclick="runProcessing()"]', tooltip: 'Admin only: Run Processing' },
        { selector: 'button[onclick="resetDatabase()"]', tooltip: 'Admin only: Reset Database' },
        { selector: 'button[onclick="deduplicateNotifications()"]', tooltip: 'Admin only: Deduplicate' },
        { selector: '#runCronBtn', tooltip: 'Admin only: Run Cron' },
        { selector: 'button[onclick="sendSelectedNotifications()"]', tooltip: 'Admin only: Send Notifications' },
        { selector: 'button[onclick="retroactiveQueue()"]', tooltip: 'Admin only: Retroactive Queue' }
    ];
    
    writeButtons.forEach(btn => {
        const elements = document.querySelectorAll(btn.selector);
        elements.forEach(el => {
            if (!userIsAdmin) {
                el.disabled = true;
                el.style.opacity = '0.5';
                el.style.cursor = 'not-allowed';
                if (btn.tooltip) {
                    el.title = btn.tooltip;
                }
            } else {
                el.disabled = false;
                el.style.opacity = '1';
                el.style.cursor = 'pointer';
                el.title = '';
            }
        });
    });
    
    // Also hide admin-only sections
    const adminSections = [
        'button[onclick="showAddMailboxModal()"]',
        'button[onclick="showAddRelayProviderModal()"]',
        '#testModeToggle',
        '#notificationModeToggle',
        'button[onclick="saveTestModeSettings()"]',
        'button[onclick="saveNotificationTemplate()"]',
        'button[onclick="backupConfig()"]',
        'button[onclick="restoreConfig()"]'
    ];
    
    adminSections.forEach(selector => {
        const elements = document.querySelectorAll(selector);
        elements.forEach(el => {
            if (!userIsAdmin) {
                el.disabled = true;
                el.style.opacity = '0.5';
                el.style.cursor = 'not-allowed';
            } else {
                el.disabled = false;
                el.style.opacity = '1';
                el.style.cursor = 'pointer';
            }
        });
    });
}

// Settings
async function loadSettings() {
    try {
        const response = await fetch('/api/settings.php?action=get');
        const data = await response.json();
        if (data.success) {
            const settings = data.data;
            document.getElementById('testModeToggle').checked = settings.test_mode === '1';
            document.getElementById('testModeOverrideEmail').value = settings.test_mode_override_email || '';
            document.getElementById('notificationModeToggle').checked = settings.notification_mode !== 'queue';
            
            if (settings.test_mode === '1') {
                document.getElementById('testModeOverride').style.display = 'block';
            }

            // Load BCC monitoring settings
            document.getElementById('bccMonitoringToggle').checked = settings.bcc_monitoring_enabled === '1';
            document.getElementById('bccMonitoringEmailsInput').value = settings.bcc_monitoring_emails || '';

            if (settings.bcc_monitoring_enabled === '1') {
                document.getElementById('bccMonitoringEmails').style.display = 'block';
            }

            // Load timezone setting
            const timezone = settings.event_log_timezone || 'UTC';
            document.getElementById('timezoneSelect').value = timezone;
            window.eventLogTimezone = timezone; // Store globally for timestamp formatting
        }
    } catch (error) {
        console.error('Error loading settings:', error);
    }
}

async function saveTestModeSettings() {
    const testMode = document.getElementById('testModeToggle').checked;
    const overrideEmail = document.getElementById('testModeOverrideEmail').value;
    
    try {
        await fetch('/api/settings.php?action=set', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ key: 'test_mode', value: testMode ? '1' : '0' })
        });
        
        await fetch('/api/settings.php?action=set', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ key: 'test_mode_override_email', value: overrideEmail })
        });
        
        alert('Settings saved');
    } catch (error) {
        console.error('Error saving settings:', error);
        alert('Error saving settings');
    }
}

document.getElementById('testModeToggle').addEventListener('change', function() {
    document.getElementById('testModeOverride').style.display = this.checked ? 'block' : 'none';
    saveTestModeSettings();
});

async function saveBccMonitoringSettings() {
    const enabled = document.getElementById('bccMonitoringToggle').checked;
    const emails = document.getElementById('bccMonitoringEmailsInput').value.trim();

    // Validate email addresses if provided
    if (enabled && emails) {
        const emailList = emails.split(',').map(e => e.trim()).filter(e => e);
        for (const email of emailList) {
            if (!email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
                alert('Please enter valid email addresses separated by commas');
                return;
            }
        }
    }

    try {
        // Save enabled state
        await fetch('/api/settings.php?action=set', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ key: 'bcc_monitoring_enabled', value: enabled ? '1' : '0' })
        });

        // Save email addresses
        await fetch('/api/settings.php?action=set', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ key: 'bcc_monitoring_emails', value: emails })
        });

        alert('Settings saved');
        addEventLogMessage('success', 'BCC monitoring settings saved');
    } catch (error) {
        console.error('Error saving BCC monitoring settings:', error);
        addEventLogMessage('error', 'Failed to save BCC monitoring settings');
        alert('Error: ' + error.message);
    }
}

async function sendTestEmail() {
    const input = document.getElementById('testEmailInput');
    const btn = document.getElementById('sendTestEmailBtn');
    const email = (input && input.value) ? input.value.trim() : '';
    if (!email) {
        alert('Please enter a test email address');
        return;
    }
    if (!email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
        alert('Please enter a valid email address');
        return;
    }
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Sending…';
    }
    try {
        const response = await fetch('/api/settings.php?action=send-test', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email })
        });
        const data = await response.json();
        if (data.success) {
            addEventLogMessage('success', 'Test email sent to ' + email);
            alert('Test email sent. Check your inbox.');
        } else {
            addEventLogMessage('error', 'Send test email failed: ' + (data.error || 'Unknown error'));
            alert('Failed to send test email: ' + (data.error || 'Unknown error'));
        }
    } catch (error) {
        console.error('Error sending test email:', error);
        addEventLogMessage('error', 'Failed to send test email: ' + error.message);
        alert('Error: ' + error.message);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.textContent = 'Send Test Email';
        }
    }
}

async function saveTimezoneSetting() {
    const timezone = document.getElementById('timezoneSelect').value;
    
    try {
        const response = await fetch('/api/settings.php?action=set', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ key: 'event_log_timezone', value: timezone })
        });
        
        const data = await response.json();
        if (data.success) {
            window.eventLogTimezone = timezone; // Update global timezone
            alert('Timezone saved successfully');
            addEventLogMessage('success', 'Timezone setting saved');
            // Reload event log to apply new timezone
            loadEventLog();
        } else {
            throw new Error(data.error || 'Failed to save timezone');
        }
    } catch (error) {
        console.error('Error saving timezone:', error);
        addEventLogMessage('error', 'Failed to save timezone setting');
        alert('Error: ' + error.message);
    }
}

document.getElementById('notificationModeToggle').addEventListener('change', async function() {
    const isRealtime = this.checked;
    const mode = isRealtime ? 'realtime' : 'queue';
    
    try {
        const response = await fetch('/api/settings.php?action=set', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ key: 'notification_mode', value: mode })
        });
        
        const data = await response.json();
        if (data.success) {
            alert('Settings saved');
            addEventLogMessage('success', `Notification mode changed to: ${mode}`);
        } else {
            throw new Error(data.error || 'Failed to save settings');
        }
    } catch (error) {
        console.error('Error saving notification mode:', error);
        alert('Error saving settings: ' + error.message);
        // Revert the toggle
        this.checked = !isRealtime;
    }
});

// Notification Template
async function loadNotificationTemplate() {
    try {
        const response = await fetch('/api/settings.php?action=template');
        const data = await response.json();
        if (data.success && data.data) {
            document.getElementById('notificationTemplateBody').value = data.data.body || '';
        }
    } catch (error) {
        console.error('Error loading notification template:', error);
    }
}

async function saveNotificationTemplate() {
    const body = document.getElementById('notificationTemplateBody').value;
    
    if (!body.trim()) {
        alert('Please enter a notification message body');
        return;
    }
    
    try {
        // Get current subject (use default if not available)
        const templateResponse = await fetch('/api/settings.php?action=template');
        const templateData = await templateResponse.json();
        const subject = templateData.success && templateData.data ? templateData.data.subject : 'Email Bounce Notification';
        
        const response = await fetch('/api/settings.php?action=template', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ subject: subject, body: body })
        });
        
        const data = await response.json();
        if (data.success) {
            alert('Notification template saved successfully');
        } else {
            throw new Error(data.error || 'Failed to save template');
        }
    } catch (error) {
        console.error('Error saving notification template:', error);
        alert('Error saving template: ' + error.message);
    }
}

// Mailboxes
async function loadMailboxes() {
    try {
        const response = await fetch('/api/mailboxes.php?action=list');
        if (!response.ok) {
            console.error('Failed to load mailboxes:', response.status, response.statusText);
            return;
        }
        const data = await response.json();
        if (data.success) {
            displayMailboxes(data.data || []);
        } else {
            console.error('Error loading mailboxes:', data.error || 'Unknown error');
        }
    } catch (error) {
        console.error('Error loading mailboxes:', error);
    }
}

function displayMailboxes(mailboxes) {
    const container = document.getElementById('mailboxList');
    if (!container) {
        console.error('mailboxList container not found');
        return;
    }
    container.innerHTML = '';
    
    if (!mailboxes || mailboxes.length === 0) {
        container.innerHTML = '<div class="list-group-item text-muted text-center">No mailboxes configured</div>';
        return;
    }
    
    mailboxes.forEach(mailbox => {
        const item = document.createElement('div');
        item.className = 'list-group-item mailbox-item';
        item.innerHTML = `
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <strong>${mailbox.name}</strong>
                    <br>
                    <small class="text-muted">${mailbox.email}</small>
                    ${mailbox.relay_provider_name ? `<br><small class="text-info">Relay: ${mailbox.relay_provider_name}</small>` : ''}
                    ${mailbox.last_processed ? `<br><small class="text-muted">Last processed: ${new Date(mailbox.last_processed).toLocaleString()}</small>` : ''}
                </div>
                <div>
                    <span class="badge ${mailbox.is_enabled == 1 ? 'bg-success' : 'bg-secondary'}">${mailbox.is_enabled == 1 ? 'Enabled' : 'Disabled'}</span>
                    <div class="btn-group btn-group-sm mt-2">
                        <button class="btn btn-sm btn-outline-primary" onclick="editMailbox(${mailbox.id})">Edit</button>
                        <button class="btn btn-sm btn-outline-danger" onclick="deleteMailbox(${mailbox.id})">Delete</button>
                    </div>
                </div>
            </div>
        `;
        container.appendChild(item);
    });
}

// Relay Providers
async function loadRelayProviders() {
    try {
        const response = await fetch('/api/relay-providers.php?action=list');
        if (!response.ok) {
            console.error('Failed to load relay providers:', response.status, response.statusText);
            return;
        }
        const data = await response.json();
        if (data.success) {
            displayRelayProviders(data.data || []);
            updateRelayProviderSelect(data.data || []);
        } else {
            console.error('Error loading relay providers:', data.error || 'Unknown error');
        }
    } catch (error) {
        console.error('Error loading relay providers:', error);
    }
}

function displayRelayProviders(providers) {
    const container = document.getElementById('relayProviderList');
    if (!container) {
        console.error('relayProviderList container not found');
        return;
    }
    
    container.innerHTML = '';
    
    if (!providers || providers.length === 0) {
        container.innerHTML = '<div class="list-group-item text-muted text-center">No relay providers configured</div>';
        return;
    }
    
    providers.forEach(provider => {
        const item = document.createElement('div');
        item.className = 'list-group-item';
        item.innerHTML = `
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <strong>${provider.name}</strong>
                    <br>
                    <small class="text-muted">${provider.smtp_host}:${provider.smtp_port} (${provider.smtp_encryption})</small>
                    <br>
                    <small class="text-muted">From: ${provider.smtp_from_email}</small>
                </div>
                <div>
                    <span class="badge ${provider.is_active == 1 ? 'bg-success' : 'bg-secondary'}">${provider.is_active == 1 ? 'Active' : 'Inactive'}</span>
                    <div class="btn-group btn-group-sm mt-2">
                        <button class="btn btn-sm btn-outline-primary" onclick="editRelayProvider(${provider.id})">Edit</button>
                        <button class="btn btn-sm btn-outline-danger" onclick="deleteRelayProvider(${provider.id})">Delete</button>
                    </div>
                </div>
            </div>
        `;
        container.appendChild(item);
    });
}

function updateRelayProviderSelect(providers) {
    const select = document.getElementById('mailboxRelayProvider');
    if (!select) return;
    
    // Keep the "None" option
    select.innerHTML = '<option value="">-- None (use default) --</option>';
    
    providers.forEach(provider => {
        if (provider.is_active == 1) {
            const option = document.createElement('option');
            option.value = provider.id;
            option.textContent = provider.name;
            select.appendChild(option);
        }
    });
}

function showAddRelayProviderModal() {
    document.getElementById('relayProviderModalTitle').textContent = 'Add Relay Provider';
    document.getElementById('relayProviderForm').reset();
    document.getElementById('relayProviderId').value = '';
    document.getElementById('relayProviderPassword').required = true;
    new bootstrap.Modal(document.getElementById('relayProviderModal')).show();
}

async function editRelayProvider(id) {
    try {
        const response = await fetch(`/api/relay-providers.php?action=get&id=${id}`);
        const data = await response.json();
        if (data.success) {
            const provider = data.data;
            document.getElementById('relayProviderModalTitle').textContent = 'Edit Relay Provider';
            document.getElementById('relayProviderId').value = provider.id;
            document.getElementById('relayProviderName').value = provider.name;
            document.getElementById('relayProviderHost').value = provider.smtp_host;
            document.getElementById('relayProviderPort').value = provider.smtp_port;
            document.getElementById('relayProviderEncryption').value = provider.smtp_encryption;
            document.getElementById('relayProviderUsername').value = provider.smtp_username;
            document.getElementById('relayProviderPassword').value = '';
            document.getElementById('relayProviderPassword').required = false;
            document.getElementById('relayProviderFromEmail').value = provider.smtp_from_email;
            document.getElementById('relayProviderFromName').value = provider.smtp_from_name;
            document.getElementById('relayProviderEnabled').checked = provider.is_active == 1;
            new bootstrap.Modal(document.getElementById('relayProviderModal')).show();
        }
    } catch (error) {
        console.error('Error loading relay provider:', error);
    }
}

// Expose edit functions to global scope for onclick handlers
window.editRelayProvider = editRelayProvider;

async function testRelayProvider() {
    const id = document.getElementById('relayProviderId').value;
    if (!id) {
        alert('Please save the relay provider first');
        return;
    }
    
    try {
        const response = await fetch(`/api/relay-providers.php?action=test&id=${id}`);
        const data = await response.json();
        if (data.success) {
            alert('Connection successful!');
        } else {
            alert('Connection failed: ' + data.error);
        }
    } catch (error) {
        alert('Error testing connection: ' + error.message);
    }
}

async function saveRelayProvider() {
    const form = document.getElementById('relayProviderForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    const data = {
        id: document.getElementById('relayProviderId').value || null,
        name: document.getElementById('relayProviderName').value,
        smtp_host: document.getElementById('relayProviderHost').value,
        smtp_port: parseInt(document.getElementById('relayProviderPort').value),
        smtp_encryption: document.getElementById('relayProviderEncryption').value,
        smtp_username: document.getElementById('relayProviderUsername').value,
        smtp_from_email: document.getElementById('relayProviderFromEmail').value,
        smtp_from_name: document.getElementById('relayProviderFromName').value,
        is_active: document.getElementById('relayProviderEnabled').checked ? 1 : 0
    };
    
    // Only include password if provided (for updates)
    const password = document.getElementById('relayProviderPassword').value;
    if (password) {
        data.smtp_password = password;
    }
    
    try {
        const url = data.id ? '/api/relay-providers.php?action=update' : '/api/relay-providers.php?action=create';
        const method = data.id ? 'PUT' : 'POST';
        
        const response = await fetch(url, {
            method: method,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        if (result.success) {
            alert('Relay provider saved successfully');
            addEventLogMessage('success', `Relay provider ${data.id ? 'updated' : 'created'} successfully`);
            bootstrap.Modal.getInstance(document.getElementById('relayProviderModal')).hide();
            loadRelayProviders();
        } else {
            alert('Error: ' + result.error);
        }
    } catch (error) {
        alert('Error saving relay provider: ' + error.message);
    }
}

async function deleteRelayProvider(id) {
    if (!confirm('Are you sure you want to delete this relay provider?')) {
        return;
    }
    
    try {
        const response = await fetch(`/api/relay-providers.php?action=delete&id=${id}`, { method: 'DELETE' });
        const data = await response.json();
        if (data.success) {
            alert('Relay provider deleted successfully');
            addEventLogMessage('success', 'Relay provider deleted successfully');
            loadRelayProviders();
        } else {
            alert('Error: ' + data.error);
        }
    } catch (error) {
        alert('Error deleting relay provider: ' + error.message);
    }
}

function showAddMailboxModal() {
    document.getElementById('mailboxModalTitle').textContent = 'Add Mailbox';
    document.getElementById('mailboxForm').reset();
    document.getElementById('mailboxId').value = '';
    loadRelayProviders(); // Refresh relay provider list
    new bootstrap.Modal(document.getElementById('mailboxModal')).show();
}

async function editMailbox(id) {
    try {
        const response = await fetch(`/api/mailboxes.php?action=get&id=${id}`);
        const data = await response.json();
        if (data.success) {
            const mailbox = data.data;
            document.getElementById('mailboxModalTitle').textContent = 'Edit Mailbox';
            document.getElementById('mailboxId').value = mailbox.id;
            document.getElementById('mailboxName').value = mailbox.name;
            document.getElementById('mailboxEmail').value = mailbox.email;
            document.getElementById('mailboxServer').value = mailbox.imap_server;
            document.getElementById('mailboxPort').value = mailbox.imap_port;
            document.getElementById('mailboxProtocol').value = mailbox.imap_protocol;
            document.getElementById('mailboxUsername').value = mailbox.imap_username;
            document.getElementById('mailboxPassword').value = ''; // Don't show password
            document.getElementById('mailboxInbox').value = mailbox.folder_inbox;
            document.getElementById('mailboxProcessed').value = mailbox.folder_processed;
            document.getElementById('mailboxProblem').value = mailbox.folder_problem;
            document.getElementById('mailboxSkipped').value = mailbox.folder_skipped;
            document.getElementById('mailboxRelayProvider').value = mailbox.relay_provider_id || '';
            document.getElementById('mailboxEnabled').checked = mailbox.is_enabled == 1;
            loadRelayProviders(); // Refresh relay provider list
            new bootstrap.Modal(document.getElementById('mailboxModal')).show();
        }
    } catch (error) {
        console.error('Error loading mailbox:', error);
    }
}

// Expose edit functions to global scope for onclick handlers
window.editMailbox = editMailbox;

async function testMailboxConnection() {
    const id = document.getElementById('mailboxId').value;
    if (!id) {
        alert('Please save the mailbox first');
        return;
    }
    
    try {
        const response = await fetch(`/api/mailboxes.php?action=test&id=${id}`);
        const data = await response.json();
        if (data.success) {
            alert('Connection successful!');
        } else {
            alert('Connection failed: ' + data.error);
        }
    } catch (error) {
        alert('Error testing connection: ' + error.message);
    }
}

async function browseFolders(folderType) {
    const id = document.getElementById('mailboxId').value;
    if (!id) {
        alert('Please enter server details and save first');
        return;
    }
    
    try {
        const response = await fetch(`/api/mailboxes.php?action=folders&id=${id}`);
        const data = await response.json();
        if (data.success) {
            const container = document.getElementById('folderList');
            container.innerHTML = '';
            data.data.forEach(folder => {
                const item = document.createElement('button');
                item.className = 'list-group-item list-group-item-action';
                item.textContent = folder;
                item.onclick = function() {
                    document.getElementById(`mailbox${folderType.charAt(0).toUpperCase() + folderType.slice(1)}`).value = folder;
                    bootstrap.Modal.getInstance(document.getElementById('folderModal')).hide();
                };
                container.appendChild(item);
            });
            new bootstrap.Modal(document.getElementById('folderModal')).show();
        }
    } catch (error) {
        alert('Error loading folders: ' + error.message);
    }
}

async function saveMailbox() {
    const form = document.getElementById('mailboxForm');
    // Remove required from password field if editing
    const passwordField = document.getElementById('mailboxPassword');
    const isEdit = document.getElementById('mailboxId').value;
    if (isEdit) {
        passwordField.removeAttribute('required');
    } else {
        passwordField.setAttribute('required', 'required');
    }
    
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    const data = {
        id: document.getElementById('mailboxId').value || null,
        name: document.getElementById('mailboxName').value,
        email: document.getElementById('mailboxEmail').value,
        imap_server: document.getElementById('mailboxServer').value,
        imap_port: parseInt(document.getElementById('mailboxPort').value),
        imap_protocol: document.getElementById('mailboxProtocol').value,
        imap_username: document.getElementById('mailboxUsername').value,
        folder_inbox: document.getElementById('mailboxInbox').value,
        folder_processed: document.getElementById('mailboxProcessed').value,
        folder_problem: document.getElementById('mailboxProblem').value,
        folder_skipped: document.getElementById('mailboxSkipped').value,
        relay_provider_id: document.getElementById('mailboxRelayProvider').value || null,
        is_enabled: document.getElementById('mailboxEnabled').checked ? 1 : 0
    };
    
    // Only include password if provided (for new mailboxes or when changing password)
    const password = document.getElementById('mailboxPassword').value;
    if (password) {
        data.imap_password = password;
    }
    
    try {
        const url = data.id ? '/api/mailboxes.php?action=update' : '/api/mailboxes.php?action=create';
        const method = data.id ? 'PUT' : 'POST';
        
        const response = await fetch(url, {
            method: method,
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        if (result.success) {
            alert('Mailbox saved successfully');
            addEventLogMessage('success', `Mailbox ${data.id ? 'updated' : 'created'} successfully`);
            bootstrap.Modal.getInstance(document.getElementById('mailboxModal')).hide();
            loadMailboxes();
        } else {
            alert('Error: ' + result.error);
        }
    } catch (error) {
        alert('Error saving mailbox: ' + error.message);
    }
}

async function deleteMailbox(id) {
    if (!confirm('Are you sure you want to delete this mailbox?')) {
        return;
    }
    
    try {
        const response = await fetch(`/api/mailboxes.php?action=delete&id=${id}`, { method: 'DELETE' });
        const data = await response.json();
        if (data.success) {
            alert('Mailbox deleted successfully');
            addEventLogMessage('success', 'Mailbox deleted successfully');
            loadMailboxes();
        } else {
            alert('Error: ' + data.error);
        }
    } catch (error) {
        alert('Error deleting mailbox: ' + error.message);
    }
}

async function runProcessing() {
    if (!userIsAdmin) {
        alert('Only administrators can run processing.');
        return;
    }
    
    const runBtn = document.querySelector('button[onclick="runProcessing()"]');
    const originalText = runBtn ? runBtn.innerHTML : '';
    
    try {
        // Disable button and show loading state
        if (runBtn) {
            runBtn.disabled = true;
            runBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Processing...';
        }
        
        // Show user feedback
        addEventLogMessage('info', 'Starting mailbox processing...');
        
        // Check mailboxes first
        const listResponse = await fetch('/api/mailboxes.php?action=list');
        if (!listResponse.ok) {
            throw new Error(`HTTP error! status: ${listResponse.status}`);
        }
        
        const listData = await listResponse.json();
        if (!listData.success) {
            throw new Error(listData.error || 'Failed to get mailbox list');
        }
        
        if (listData.data.length === 0) {
            alert('No mailboxes configured');
            if (runBtn) {
                runBtn.disabled = false;
                runBtn.innerHTML = originalText;
            }
            return;
        }
        
        const enabledMailboxes = listData.data.filter(m => m.is_enabled == 1);
        if (enabledMailboxes.length === 0) {
            alert('No enabled mailboxes to process');
            if (runBtn) {
                runBtn.disabled = false;
                runBtn.innerHTML = originalText;
            }
            return;
        }
        
        // Process mailboxes synchronously - wait for results
        addEventLogMessage('info', `Processing ${enabledMailboxes.length} enabled mailbox(es)...`);
        
        const processResponse = await fetch('/api/mailboxes.php?action=process', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' }
        });
        
        if (!processResponse.ok) {
            throw new Error(`HTTP error! status: ${processResponse.status}`);
        }
        
        const processData = await processResponse.json();
        
        if (processData.success) {
            const totalProcessed = processData.total_processed || 0;
            const totalSkipped = processData.total_skipped || 0;
            const totalProblems = processData.total_problems || 0;
            
            addEventLogMessage('success', `Processing completed: ${totalProcessed} processed, ${totalSkipped} skipped, ${totalProblems} problems`);
            
            // Show detailed results if available
            if (processData.results && processData.results.length > 0) {
                processData.results.forEach(result => {
                    if (result.error) {
                        addEventLogMessage('error', `${result.mailbox_name}: ${result.error}`);
                    } else {
                        addEventLogMessage('info', `${result.mailbox_name}: ${result.processed} processed, ${result.skipped} skipped, ${result.problems} problems`);
                    }
                });
            }
            
            // Refresh all data to show new bounces and notifications
            await Promise.all([
                loadMailboxes(),
                loadDashboard(),
                loadNotificationQueue(),
                loadEventLog()
            ]);
        } else {
            throw new Error(processData.error || 'Processing failed');
        }
        
    } catch (error) {
        console.error('Error running processing:', error);
        addEventLogMessage('error', 'Error during processing: ' + error.message);
        alert('Error: ' + error.message);
    } finally {
        // Always re-enable button
        if (runBtn) {
            runBtn.disabled = false;
            runBtn.innerHTML = originalText;
        }
    }
}

// Helper function to add messages to event log immediately (before server sync)
function addEventLogMessage(severity, message) {
    const container = document.getElementById('eventLog');
    if (container) {
        const item = document.createElement('div');
        item.className = `event ${severity}`;
        // No timestamp - will be replaced by server data on next poll
        item.innerHTML = `<span class="text-muted">[PENDING]</span> <strong>${severity.toUpperCase()}</strong>: ${message}`;
        container.insertBefore(item, container.firstChild);
        
        // Keep only last 100 items
        while (container.children.length > 100) {
            container.removeChild(container.lastChild);
        }
    }
}

// Reset database - clears all data except users, relays, and mailboxes
async function resetDatabase() {
    if (!userIsAdmin) {
        alert('Only administrators can reset the database.');
        return;
    }
    
    if (!confirm('Are you sure you want to reset the database? This will delete ALL bounces, notifications, domains, and events. Users, relay providers, and mailbox configurations will be preserved.')) {
        return;
    }
    
    if (!confirm('This action cannot be undone. Are you absolutely sure?')) {
        return;
    }
    
    const btn = document.querySelector('button[onclick="resetDatabase()"]');
    const originalText = btn ? btn.innerHTML : '';
    
    try {
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Resetting...';
        }
        
        addEventLogMessage('warning', 'Resetting database...');
        
        const response = await fetch('/api/mailboxes.php?action=reset-database', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' }
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        if (data.success) {
            addEventLogMessage('success', 'Database reset successfully');
            // Reload all data
            await Promise.all([
                loadDashboard(),
                loadMailboxes(),
                loadEventLog(),
                loadNotificationQueue()
            ]);
        } else {
            throw new Error(data.error || 'Unknown error');
        }
    } catch (error) {
        console.error('Error resetting database:', error);
        addEventLogMessage('error', 'Database reset failed: ' + error.message);
        alert('Error: ' + error.message);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    }
}

// Retroactively queue notifications from existing bounces
async function retroactiveQueue() {
    if (!userIsAdmin) {
        alert('Only administrators can queue notifications from existing bounces.');
        return;
    }
    
    const btn = document.querySelector('button[onclick="retroactiveQueue()"]');
    const originalText = btn ? btn.innerHTML : '';
    
    try {
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Processing...';
        }
        
        addEventLogMessage('info', 'Starting retroactive notification queueing...');
        
        const response = await fetch('/api/mailboxes.php?action=retroactive-queue', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' }
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        if (data.success) {
            addEventLogMessage('success', `Queued ${data.queued} notifications from ${data.bounces_processed} existing bounces`);
            await loadNotificationQueue();
            await loadDashboard();
        } else {
            throw new Error(data.error || 'Unknown error');
        }
    } catch (error) {
        console.error('Error in retroactive queue:', error);
        addEventLogMessage('error', 'Retroactive queue failed: ' + error.message);
        alert('Error: ' + error.message);
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    }
}

// Dashboard
async function loadDashboard() {
    try {
        const response = await fetch('/api/dashboard.php');
        const data = await response.json();
        if (data.success) {
            displayDashboard(data.data);
            updateHeaderStats(data.data.stats);
        }
    } catch (error) {
        console.error('Error loading dashboard:', error);
    }
}

// Chart instances
let smtpTimelineChart = null;
let domainTimelineChart = null;
let smtpChartMinDate = null;
let smtpChartMaxDate = null;
let domainChartMinDate = null;
let domainChartMaxDate = null;

// Color palette for charts - distinct colors for maximum legibility
const chartColors = [
    '#3b82f6', // Blue
    '#ef4444', // Red
    '#10b981', // Green
    '#f59e0b', // Amber
    '#8b5cf6', // Purple
    '#ec4899', // Pink
    '#06b6d4', // Cyan
    '#f97316', // Orange
    '#84cc16', // Lime
    '#6366f1', // Indigo
    '#14b8a6', // Teal
    '#f43f5e', // Rose
    '#a855f7', // Violet
    '#22c55e', // Emerald
    '#eab308', // Yellow
    '#0ea5e9', // Sky
];

// Generate distinct colors for multiple series
function getColorForIndex(index) {
    return chartColors[index % chartColors.length];
}

// Generate color with transparency
function getColorWithAlpha(color, alpha = 0.3) {
    // Convert hex to rgba
    const r = parseInt(color.slice(1, 3), 16);
    const g = parseInt(color.slice(3, 5), 16);
    const b = parseInt(color.slice(5, 7), 16);
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

// Get theme-aware text color
function getTextColor() {
    const theme = document.body.getAttribute('data-theme');
    return theme === 'dark' ? '#f1f5f9' : '#0f172a';
}

// Get theme-aware grid color
function getGridColor() {
    const theme = document.body.getAttribute('data-theme');
    return theme === 'dark' ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.05)';
}

// Render SMTP Codes Timeline Chart
function renderSmtpTimelineChart(timelineData, minDate, maxDate) {
    const ctx = document.getElementById('smtpTimelineChart');
    if (!ctx || !timelineData || timelineData.length === 0) {
        if (smtpTimelineChart) {
            smtpTimelineChart.destroy();
            smtpTimelineChart = null;
        }
        return;
    }

    // Store date range for zoom reset
    smtpChartMinDate = minDate;
    smtpChartMaxDate = maxDate;

    // Group data by SMTP code
    const codeData = {};
    timelineData.forEach(item => {
        if (!codeData[item.smtp_code]) {
            codeData[item.smtp_code] = {};
        }
        codeData[item.smtp_code][item.bounce_day] = parseInt(item.bounce_count);
    });

    // Get all unique dates
    const allDates = new Set();
    timelineData.forEach(item => allDates.add(item.bounce_day));
    const sortedDates = Array.from(allDates).sort();

    // Create datasets for each SMTP code
    const datasets = [];
    const codes = Object.keys(codeData).sort();
    codes.forEach((code, index) => {
        const color = getColorForIndex(index);
        const data = sortedDates.map(date => ({
            x: date,
            y: codeData[code][date] || 0
        }));

        datasets.push({
            label: `SMTP ${code}`,
            data: data,
            borderColor: color,
            backgroundColor: getColorWithAlpha(color, 0.2),
            fill: true,
            tension: 0.4, // Smooth curve
            pointRadius: 0,
            pointHoverRadius: 4,
            borderWidth: 2
        });
    });

    // Destroy existing chart if it exists
    if (smtpTimelineChart) {
        smtpTimelineChart.destroy();
    }

    smtpTimelineChart = new Chart(ctx, {
        type: 'line',
        data: {
            datasets: datasets
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            interaction: {
                mode: 'index',
                intersect: false
            },
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    labels: {
                        usePointStyle: true,
                        padding: 10,
                        font: {
                            size: 11
                        },
                        color: getTextColor()
                    }
                },
                tooltip: {
                    mode: 'index',
                    intersect: false
                },
                zoom: {
                    zoom: {
                        wheel: {
                            enabled: false
                        },
                        pinch: {
                            enabled: false
                        },
                        mode: 'x',
                        limits: {
                            x: { min: 'original', max: 'original' }
                        }
                    },
                    pan: {
                        enabled: false,
                        mode: 'x'
                    }
                }
            },
            scales: {
                x: {
                    type: 'time',
                    time: {
                        unit: 'day',
                        displayFormats: {
                            day: 'MMM dd'
                        }
                    },
                    min: minDate,
                    max: maxDate,
                    title: {
                        display: true,
                        text: 'Date',
                        color: getTextColor()
                    },
                    ticks: {
                        color: getTextColor()
                    },
                    grid: {
                        color: getGridColor()
                    }
                },
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Bounce Count',
                        color: getTextColor()
                    },
                    ticks: {
                        color: getTextColor()
                    },
                    grid: {
                        color: getGridColor()
                    }
                }
            }
        }
    });
}

// Render Domains Timeline Chart
function renderDomainTimelineChart(timelineData, minDate, maxDate) {
    const ctx = document.getElementById('domainTimelineChart');
    if (!ctx || !timelineData || timelineData.length === 0) {
        if (domainTimelineChart) {
            domainTimelineChart.destroy();
            domainTimelineChart = null;
        }
        return;
    }

    // Store date range for zoom reset
    domainChartMinDate = minDate;
    domainChartMaxDate = maxDate;

    // Group data by domain
    const domainData = {};
    timelineData.forEach(item => {
        if (!domainData[item.recipient_domain]) {
            domainData[item.recipient_domain] = {};
        }
        domainData[item.recipient_domain][item.bounce_day] = parseInt(item.bounce_count);
    });

    // Get all unique dates
    const allDates = new Set();
    timelineData.forEach(item => allDates.add(item.bounce_day));
    const sortedDates = Array.from(allDates).sort();

    // Create datasets for each domain (limit to top 15 for legibility)
    const domains = Object.keys(domainData).sort((a, b) => {
        const totalA = Object.values(domainData[a]).reduce((sum, count) => sum + count, 0);
        const totalB = Object.values(domainData[b]).reduce((sum, count) => sum + count, 0);
        return totalB - totalA;
    }).slice(0, 15);

    const datasets = [];
    domains.forEach((domain, index) => {
        const color = getColorForIndex(index);
        const data = sortedDates.map(date => ({
            x: date,
            y: domainData[domain][date] || 0
        }));

        datasets.push({
            label: domain,
            data: data,
            borderColor: color,
            backgroundColor: getColorWithAlpha(color, 0.2),
            fill: true,
            tension: 0.4, // Smooth curve
            pointRadius: 0,
            pointHoverRadius: 4,
            borderWidth: 2
        });
    });

    // Destroy existing chart if it exists
    if (domainTimelineChart) {
        domainTimelineChart.destroy();
    }

    domainTimelineChart = new Chart(ctx, {
        type: 'line',
        data: {
            datasets: datasets
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            interaction: {
                mode: 'index',
                intersect: false
            },
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    labels: {
                        usePointStyle: true,
                        padding: 10,
                        font: {
                            size: 11
                        },
                        color: getTextColor()
                    }
                },
                tooltip: {
                    mode: 'index',
                    intersect: false
                },
                zoom: {
                    zoom: {
                        wheel: {
                            enabled: false
                        },
                        pinch: {
                            enabled: false
                        },
                        mode: 'x',
                        limits: {
                            x: { min: 'original', max: 'original' }
                        }
                    },
                    pan: {
                        enabled: false,
                        mode: 'x'
                    }
                }
            },
            scales: {
                x: {
                    type: 'time',
                    time: {
                        unit: 'day',
                        displayFormats: {
                            day: 'MMM dd'
                        }
                    },
                    min: minDate,
                    max: maxDate,
                    title: {
                        display: true,
                        text: 'Date',
                        color: getTextColor()
                    },
                    ticks: {
                        color: getTextColor()
                    },
                    grid: {
                        color: getGridColor()
                    }
                },
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Bounce Count',
                        color: getTextColor()
                    },
                    ticks: {
                        color: getTextColor()
                    },
                    grid: {
                        color: getGridColor()
                    }
                }
            }
        }
    });
}

// Zoom functions for SMTP chart
function zoomSmtpChart(action) {
    if (!smtpTimelineChart) return;

    const chart = smtpTimelineChart;
    const xScale = chart.scales.x;
    const currentMin = xScale.min;
    const currentMax = xScale.max;
    const range = currentMax - currentMin;
    const center = (currentMin + currentMax) / 2;

    if (action === 'in') {
        // Zoom in by 50%
        const newRange = range * 0.5;
        const newMin = center - newRange / 2;
        const newMax = center + newRange / 2;
        
        // Ensure we don't zoom beyond original bounds
        const finalMin = Math.max(newMin, new Date(smtpChartMinDate).getTime());
        const finalMax = Math.min(newMax, new Date(smtpChartMaxDate).getTime());
        
        xScale.options.min = new Date(finalMin).toISOString();
        xScale.options.max = new Date(finalMax).toISOString();
    } else if (action === 'out') {
        // Zoom out by 100% (double the range)
        const newRange = range * 2;
        const newMin = center - newRange / 2;
        const newMax = center + newRange / 2;
        
        // Clamp to original bounds
        const originalMin = new Date(smtpChartMinDate).getTime();
        const originalMax = new Date(smtpChartMaxDate).getTime();
        
        const finalMin = Math.max(newMin, originalMin);
        const finalMax = Math.min(newMax, originalMax);
        
        xScale.options.min = new Date(finalMin).toISOString();
        xScale.options.max = new Date(finalMax).toISOString();
    } else if (action === 'reset') {
        // Reset to original bounds
        xScale.options.min = smtpChartMinDate;
        xScale.options.max = smtpChartMaxDate;
    }

    chart.update('none');
}

// Zoom functions for Domain chart
function zoomDomainChart(action) {
    if (!domainTimelineChart) return;

    const chart = domainTimelineChart;
    const xScale = chart.scales.x;
    const currentMin = xScale.min;
    const currentMax = xScale.max;
    const range = currentMax - currentMin;
    const center = (currentMin + currentMax) / 2;

    if (action === 'in') {
        // Zoom in by 50%
        const newRange = range * 0.5;
        const newMin = center - newRange / 2;
        const newMax = center + newRange / 2;
        
        // Ensure we don't zoom beyond original bounds
        const finalMin = Math.max(newMin, new Date(domainChartMinDate).getTime());
        const finalMax = Math.min(newMax, new Date(domainChartMaxDate).getTime());
        
        xScale.options.min = new Date(finalMin).toISOString();
        xScale.options.max = new Date(finalMax).toISOString();
    } else if (action === 'out') {
        // Zoom out by 100% (double the range)
        const newRange = range * 2;
        const newMin = center - newRange / 2;
        const newMax = center + newRange / 2;
        
        // Clamp to original bounds
        const originalMin = new Date(domainChartMinDate).getTime();
        const originalMax = new Date(domainChartMaxDate).getTime();
        
        const finalMin = Math.max(newMin, originalMin);
        const finalMax = Math.min(newMax, originalMax);
        
        xScale.options.min = new Date(finalMin).toISOString();
        xScale.options.max = new Date(finalMax).toISOString();
    } else if (action === 'reset') {
        // Reset to original bounds
        xScale.options.min = domainChartMinDate;
        xScale.options.max = domainChartMaxDate;
    }

    chart.update('none');
}

function displayDashboard(data) {
    // Top domains - enhanced display
    const domainsContainer = document.getElementById('topDomains');
    domainsContainer.innerHTML = '';
    
    if (data.domains.length === 0) {
        domainsContainer.innerHTML = '<p class="text-muted mb-0">No domains found</p>';
    } else {
        data.domains.forEach((domain, index) => {
            const item = document.createElement('div');
            item.className = 'mb-2 rounded domain-item p-2';
            item.setAttribute('data-domain-index', index);
            
            // Convert trust score from 0-100 to 1-10 scale for display
            // 0-100 stored internally, 1-10 displayed (1 = least trusted, 10 = most trusted)
            let trustScore10;
            if (domain.trust_score === null || domain.trust_score === undefined) {
                trustScore10 = 5; // Default neutral score
            } else {
                // Convert: 0-100 -> 1-10
                // 0 -> 1, 50 -> 5, 100 -> 10
                trustScore10 = Math.max(1, Math.min(10, Math.round((domain.trust_score / 100) * 10)));
                // Handle edge case: if stored as 0, it means worst trust (1/10)
                if (domain.trust_score === 0) {
                    trustScore10 = 1;
                }
            }
            const trustClass = trustScore10 >= 7 ? 'high' : trustScore10 >= 4 ? 'medium' : 'low';
            
            // Format last bounce date
            let lastBounceText = 'Never';
            if (domain.last_bounce_date) {
                const lastBounce = new Date(domain.last_bounce_date);
                const now = new Date();
                const daysAgo = Math.floor((now - lastBounce) / (1000 * 60 * 60 * 24));
                if (daysAgo === 0) {
                    lastBounceText = 'Today';
                } else if (daysAgo === 1) {
                    lastBounceText = 'Yesterday';
                } else if (daysAgo < 7) {
                    lastBounceText = `${daysAgo} days ago`;
                } else {
                    lastBounceText = lastBounce.toLocaleDateString();
                }
            }
            
            // Calculate bounce rate indicators
            const permanentFailures = domain.permanent_failures || 0;
            const temporaryFailures = domain.temporary_failures || 0;
            const totalFailures = permanentFailures + temporaryFailures;
            const permanentRate = domain.bounce_count > 0 ? ((permanentFailures / domain.bounce_count) * 100).toFixed(0) : 0;
            
            // Check if domain is invalid
            const isInvalid = domain.is_valid === false;
            const invalidBadge = isInvalid ? '<span class="badge bg-danger me-1" title="Invalid domain"><i class="bi bi-x-circle"></i> Invalid</span>' : '';
            
            // Build detailed information for accordion
            const recentBounces = domain.recent_bounces || [];
            const smtpCodes = domain.smtp_codes || [];
            const bounceTimeline = domain.bounce_timeline || [];
            
            let detailsHtml = '';
            if (recentBounces.length > 0 || smtpCodes.length > 0 || bounceTimeline.length > 0) {
                detailsHtml = '<div class="domain-details-list" style="display: none;">';
                detailsHtml += '<div class="p-2 pt-0 mt-2 border-top">';
                
                // Recent bounces section
                if (recentBounces.length > 0) {
                    detailsHtml += '<div class="mb-3">';
                    detailsHtml += '<small class="d-block mb-2 fw-bold"><i class="bi bi-clock-history"></i> Recent Bounces:</small>';
                    recentBounces.forEach(bounce => {
                        const bounceDate = new Date(bounce.bounce_date);
                        const statusColor = bounce.deliverability_status === 'permanent_failure' ? 'danger' : 
                                          bounce.deliverability_status === 'temporary_failure' ? 'warning' : 'secondary';
                        detailsHtml += `
                            <div class="small mb-1 p-2 rounded notification-item">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="flex-grow-1">
                                        <span class="fw-bold">${bounce.original_to || 'N/A'}</span>
                                        ${bounce.original_subject ? `<div class="text-muted">${bounce.original_subject}</div>` : ''}
                                        ${bounce.smtp_code ? `<span class="badge bg-${statusColor} ms-2">${bounce.smtp_code}</span>` : ''}
                                    </div>
                                    <small class="text-muted">${bounceDate.toLocaleDateString()} ${bounceDate.toLocaleTimeString()}</small>
                                </div>
                                ${bounce.smtp_reason ? `<div class="text-muted mt-1"><small>${bounce.smtp_reason}</small></div>` : ''}
                                ${bounce.smtp_description ? `<div class="text-muted"><small><i>${bounce.smtp_description}</i></small></div>` : ''}
                            </div>
                        `;
                    });
                    detailsHtml += '</div>';
                }
                
                // SMTP codes breakdown
                if (smtpCodes.length > 0) {
                    detailsHtml += '<div class="mb-3">';
                    detailsHtml += '<small class="d-block mb-2 fw-bold"><i class="bi bi-list-ul"></i> SMTP Codes:</small>';
                    smtpCodes.forEach(code => {
                        const codeColor = code.smtp_code >= 550 && code.smtp_code <= 559 ? 'danger' : 
                                       code.smtp_code >= 450 && code.smtp_code <= 459 ? 'warning' : 'info';
                        detailsHtml += `
                            <div class="small mb-1 p-2 rounded notification-item">
                                <span class="badge bg-${codeColor} me-2">${code.smtp_code}</span>
                                <span class="fw-bold">${code.count} occurrence${code.count !== 1 ? 's' : ''}</span>
                                ${code.description ? `<div class="text-muted mt-1">${code.description}</div>` : ''}
                                ${code.recommendation ? `<div class="text-muted"><small><i class="bi bi-lightbulb"></i> ${code.recommendation}</small></div>` : ''}
                            </div>
                        `;
                    });
                    detailsHtml += '</div>';
                }
                
                // Bounce timeline (last 30 days)
                if (bounceTimeline.length > 0) {
                    detailsHtml += '<div class="mb-2">';
                    detailsHtml += '<small class="d-block mb-2 fw-bold"><i class="bi bi-graph-up"></i> Bounce Timeline (Last 30 Days):</small>';
                    bounceTimeline.slice(0, 10).forEach(day => {
                        const dayDate = new Date(day.bounce_day);
                        detailsHtml += `
                            <div class="small mb-1 p-2 rounded notification-item">
                                <div class="d-flex justify-content-between">
                                    <span class="fw-bold">${dayDate.toLocaleDateString()}</span>
                                    <span>${day.bounce_count} bounce${day.bounce_count !== 1 ? 's' : ''}</span>
                                </div>
                                <div class="text-muted">
                                    ${day.permanent_count > 0 ? `<span class="text-danger">${day.permanent_count} permanent</span>` : ''}
                                    ${day.temporary_count > 0 ? `<span class="text-warning ms-2">${day.temporary_count} temporary</span>` : ''}
                                </div>
                            </div>
                        `;
                    });
                    if (bounceTimeline.length > 10) {
                        detailsHtml += `<small class="text-muted">... and ${bounceTimeline.length - 10} more days</small>`;
                    }
                    detailsHtml += '</div>';
                }
                
                // Invalid domain email addresses (if applicable)
                if (isInvalid) {
                    const toAddresses = domain.associated_to_addresses || [];
                    const ccAddresses = domain.associated_cc_addresses || [];
                    const emailPairs = domain.email_pairs || [];
                    const allEmails = [...new Set([...toAddresses, ...ccAddresses])];
                    
                    if (allEmails.length > 0 || emailPairs.length > 0) {
                        detailsHtml += '<div class="mt-2 p-2 rounded" style="background-color: rgba(220, 53, 69, 0.1); border: 1px solid rgba(220, 53, 69, 0.3);">';
                        detailsHtml += '<small class="fw-bold text-warning d-block mb-1"><i class="bi bi-exclamation-triangle"></i> Associated Email Addresses:</small>';
                        
                        if (emailPairs.length > 0) {
                            emailPairs.forEach(pair => {
                                detailsHtml += `<div class="small mb-1">`;
                                detailsHtml += `<span class="fw-bold">TO:</span> ${pair.to}`;
                                if (pair.cc) {
                                    detailsHtml += ` <span class="fw-bold ms-2">CC:</span> ${pair.cc}`;
                                }
                                detailsHtml += `</div>`;
                            });
                        } else {
                            if (toAddresses.length > 0) {
                                detailsHtml += `<div class="small mb-1"><span class="fw-bold">TO addresses:</span> ${toAddresses.join(', ')}</div>`;
                            }
                            if (ccAddresses.length > 0) {
                                detailsHtml += `<div class="small mb-1"><span class="fw-bold">CC addresses:</span> ${ccAddresses.join(', ')}</div>`;
                            }
                        }
                        detailsHtml += '</div>';
                    }
                }
                
                detailsHtml += '</div></div>';
            }
            
            item.innerHTML = `
                <div class="domain-header" style="cursor: pointer;">
                    <div class="d-flex justify-content-between align-items-start mb-1">
                        <div class="flex-grow-1">
                            <div class="fw-bold ${isInvalid ? 'text-danger' : ''}">
                                ${domain.domain}
                                ${isInvalid ? '<i class="bi bi-exclamation-triangle-fill text-danger ms-1"></i>' : ''}
                                ${detailsHtml ? '<i class="bi bi-chevron-down ms-2 domain-chevron" style="font-size: 0.75rem; transition: transform 0.3s;"></i>' : ''}
                            </div>
                            <small class="text-muted">Last bounce: ${lastBounceText}</small>
                            ${isInvalid ? `<small class="d-block text-warning"><i class="bi bi-info-circle"></i> ${domain.validation_reason || 'Invalid domain'}</small>` : ''}
                        </div>
                        <div class="text-end">
                            <span class="badge bg-secondary me-1" title="Total bounces">${domain.bounce_count}</span>
                            ${invalidBadge}
                            <span class="badge badge-trust ${trustClass}" title="Trust score (1-10)">${trustScore10}/10</span>
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-1">
                        ${permanentFailures > 0 ? `<small class="text-muted"><i class="bi bi-exclamation-triangle"></i> ${permanentFailures} permanent</small>` : ''}
                        ${temporaryFailures > 0 ? `<small class="text-muted"><i class="bi bi-clock"></i> ${temporaryFailures} temporary</small>` : ''}
                        ${permanentRate > 50 ? `<small class="text-warning"><i class="bi bi-exclamation-circle"></i> ${permanentRate}% permanent rate</small>` : ''}
                    </div>
                </div>
                ${detailsHtml}
            `;
            
            // Add click handler for accordion
            if (detailsHtml) {
                const header = item.querySelector('.domain-header');
                const detailsList = item.querySelector('.domain-details-list');
                const chevron = item.querySelector('.domain-chevron');
                
                header.addEventListener('click', function() {
                    const isExpanded = detailsList.style.display !== 'none';
                    
                    if (isExpanded) {
                        // Collapse
                        detailsList.style.maxHeight = detailsList.scrollHeight + 'px';
                        detailsList.offsetHeight; // Force reflow
                        detailsList.style.maxHeight = '0';
                        setTimeout(() => {
                            detailsList.style.display = 'none';
                        }, 300);
                        chevron.style.transform = 'rotate(0deg)';
                    } else {
                        // Expand
                        detailsList.style.display = 'block';
                        detailsList.style.maxHeight = '0';
                        detailsList.offsetHeight; // Force reflow
                        detailsList.style.maxHeight = detailsList.scrollHeight + 'px';
                        chevron.style.transform = 'rotate(180deg)';
                    }
                });
            }
            
            domainsContainer.appendChild(item);
        });
    }
    
    // All SMTP codes - enhanced display with matching color scheme
    const codesContainer = document.getElementById('topSmtpCodes');
    codesContainer.innerHTML = '';
    
    if (data.smtpCodes.length === 0) {
        codesContainer.innerHTML = '<p class="text-muted mb-0">No SMTP codes found</p>';
        return;
    }
    
    // Determine severity color based on SMTP code
    const getCodeColor = (code) => {
        if (!code) return 'secondary';
        const codeNum = parseInt(code);
        if (codeNum >= 550 && codeNum <= 559) return 'danger'; // Permanent failure
        if (codeNum >= 450 && codeNum <= 459) return 'warning'; // Temporary failure
        if (codeNum >= 250 && codeNum <= 259) return 'success'; // Success
        return 'info'; // Other codes
    };
    
    data.smtpCodes.forEach((code, index) => {
        const item = document.createElement('div');
        item.className = 'mb-2 rounded smtp-code-item p-2';
        item.setAttribute('data-code-index', index);
        
        const description = code.description || 'No description available';
        const codeColor = getCodeColor(code.smtp_code);
        const affectedDomains = code.affected_domains || 0;
        const domains = code.domains || [];
        
        // Format dates
        let firstSeenText = 'N/A';
        let lastSeenText = 'N/A';
        if (code.first_seen) {
            const firstSeen = new Date(code.first_seen);
            firstSeenText = firstSeen.toLocaleDateString();
        }
        if (code.last_seen) {
            const lastSeen = new Date(code.last_seen);
            const now = new Date();
            const daysAgo = Math.floor((now - lastSeen) / (1000 * 60 * 60 * 24));
            if (daysAgo === 0) {
                lastSeenText = 'Today';
            } else if (daysAgo === 1) {
                lastSeenText = 'Yesterday';
            } else if (daysAgo < 7) {
                lastSeenText = `${daysAgo} days ago`;
            } else {
                lastSeenText = lastSeen.toLocaleDateString();
            }
        }
        
        // Build domains list HTML
        let domainsHtml = '';
        if (domains.length > 0) {
            domainsHtml = '<div class="smtp-domains-list" style="display: none;">';
            domainsHtml += '<div class="p-2 pt-0 mt-2 border-top">';
            domainsHtml += '<small class="d-block mb-2 fw-bold"><i class="bi bi-globe"></i> Affected Domains:</small>';
            domains.forEach(domain => {
                const lastBounce = domain.last_bounce ? new Date(domain.last_bounce).toLocaleDateString() : 'N/A';
                domainsHtml += `
                    <div class="small mb-1 p-2 rounded notification-item">
                        <span class="fw-bold">${domain.recipient_domain}</span>
                        <span class="ms-2">(${domain.bounce_count} bounce${domain.bounce_count !== 1 ? 's' : ''})</span>
                        <span class="ms-2 text-muted">Last: ${lastBounce}</span>
                    </div>
                `;
            });
            domainsHtml += '</div></div>';
        }
        
        item.innerHTML = `
            <div class="smtp-code-header" style="cursor: pointer;">
                <div class="d-flex justify-content-between align-items-start mb-1">
                    <div class="flex-grow-1">
                        <div class="fw-bold">
                            <span class="badge bg-${codeColor} me-2">${code.smtp_code || 'N/A'}</span>
                            ${description}
                            ${domains.length > 0 ? '<i class="bi bi-chevron-down ms-2 smtp-chevron" style="font-size: 0.75rem; transition: transform 0.3s;"></i>' : ''}
                        </div>
                        ${code.recommendation ? `<small class="text-muted d-block mt-1"><i class="bi bi-lightbulb"></i> ${code.recommendation}</small>` : ''}
                    </div>
                    <span class="badge bg-${codeColor} ms-2" title="Occurrences">${code.count}</span>
                </div>
                <div class="d-flex gap-3 mt-1">
                    <small class="text-muted"><i class="bi bi-globe"></i> ${affectedDomains} domain(s)</small>
                    <small class="text-muted"><i class="bi bi-calendar"></i> Last: ${lastSeenText}</small>
                </div>
            </div>
            ${domainsHtml}
        `;
        
        // Add click handler for accordion
        if (domains.length > 0) {
            const header = item.querySelector('.smtp-code-header');
            const domainsList = item.querySelector('.smtp-domains-list');
            const chevron = item.querySelector('.smtp-chevron');
            
            header.addEventListener('click', function() {
                const isExpanded = domainsList.style.display !== 'none';
                
                if (isExpanded) {
                    // Collapse
                    domainsList.style.maxHeight = domainsList.scrollHeight + 'px';
                    // Force reflow
                    domainsList.offsetHeight;
                    domainsList.style.maxHeight = '0';
                    setTimeout(() => {
                        domainsList.style.display = 'none';
                    }, 300);
                    chevron.style.transform = 'rotate(0deg)';
                } else {
                    // Expand
                    domainsList.style.display = 'block';
                    domainsList.style.maxHeight = '0';
                    // Force reflow
                    domainsList.offsetHeight;
                    domainsList.style.maxHeight = domainsList.scrollHeight + 'px';
                    chevron.style.transform = 'rotate(180deg)';
                }
            });
        }
        
        codesContainer.appendChild(item);
    });
    
    // Render timeline charts
    if (data.timeline) {
        renderSmtpTimelineChart(data.timeline.smtpCodes, data.timeline.minDate, data.timeline.maxDate);
        renderDomainTimelineChart(data.timeline.domains, data.timeline.minDate, data.timeline.maxDate);
    }
}

function updateHeaderStats(stats) {
    const headerStats = document.getElementById('headerStats');
    if (!headerStats) return;
    
    headerStats.innerHTML = `
        <span class="badge bg-info">Total Bounces: ${stats.totalBounces}</span>
        <span class="badge bg-warning">Queued Notifications: ${stats.queuedNotifications || 0}</span>
        <span class="badge bg-secondary">Domains: ${stats.totalDomains}</span>
        <span class="badge bg-success">Mailboxes: ${stats.activeMailboxes}</span>
    `;
}

// Event Log
async function loadEventLog() {
    const refreshBtn = document.getElementById('refreshLogBtn');
    // Store original HTML - use a default if button doesn't exist yet
    const originalHtml = refreshBtn ? refreshBtn.innerHTML : '<i class="bi bi-arrow-clockwise"></i> Refresh';
    
    try {
        if (refreshBtn) {
            // Show loading state on refresh button
            refreshBtn.disabled = true;
            refreshBtn.innerHTML = '<i class="bi bi-arrow-clockwise"></i> <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';
        }
        
        // Load all events (filtering/sorting is done client-side)
        // Load 1000 events for pagination
        const url = '/api/events.php?limit=1000';
        const response = await fetch(url);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        // Check if response has content
        const text = await response.text();
        if (!text || text.trim() === '') {
            // Empty response - initialize with empty array
            displayEventLog([]);
            return;
        }
        
        // Try to parse JSON
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error('Invalid JSON response from events API:', text.substring(0, 200));
            throw new Error('Invalid response from server: ' + e.message);
        }
        
        if (data.success) {
            displayEventLog(data.data || []);
        } else {
            throw new Error(data.error || 'Failed to load events');
        }
    } catch (error) {
        console.error('Error loading events:', error);
        // Still restore button even on error
    } finally {
        // Always restore button state, even if there was an error
        if (refreshBtn) {
            refreshBtn.disabled = false;
            refreshBtn.innerHTML = originalHtml;
        }
    }
}

function displayEventLog(events) {
    // Store all events for pagination
    eventLogAllEvents = events;
    eventLogTotalEvents = events.length;
    
    // Apply filters and display
    applyEventLogFilters();
}

// Removed toggleLogPause - no longer needed without auto-refresh
// Event filter listener is set up in DOMContentLoaded

// Notification Queue
async function loadNotificationQueue() {
    try {
        const response = await fetch('/api/notifications.php?action=queue&status=pending');
        const data = await response.json();
        if (data.success) {
            displayNotificationQueue(data.data);
        }
    } catch (error) {
        console.error('Error loading notifications:', error);
    }
}

// Store original notifications for filtering/sorting
let allNotifications = [];
let badAddressesData = []; // Store bad addresses for CSV export

function displayNotificationQueue(notifications) {
    // Store all notifications for filtering/sorting
    allNotifications = notifications;
    applyNotificationQueueFilters();
}

function applyNotificationQueueFilters() {
    // Get both containers (dashboard and notifications view)
    const dashboardContainer = document.getElementById('notificationQueue');
    const viewContainer = document.getElementById('notificationQueueView');
    
    // Update both containers to keep them in sync
    const containersToUpdate = [];
    if (dashboardContainer) containersToUpdate.push(dashboardContainer);
    if (viewContainer) containersToUpdate.push(viewContainer);
    
    if (containersToUpdate.length === 0) return;
    
    if (allNotifications.length === 0) {
        const emptyMessage = '<p class="text-muted">No pending notifications</p>';
        containersToUpdate.forEach(container => {
            container.innerHTML = emptyMessage;
        });
        return;
    }
    
    // Get the correct filter and sort elements (prefer the visible view's controls)
    let filterInput = document.getElementById('notificationQueueFilterView');
    let sortSelect = document.getElementById('notificationQueueSortView');
    
    // Check if notifications view is visible
    const notificationsViewVisible = viewContainer && viewContainer.closest('.view-container')?.style.display !== 'none';
    if (!notificationsViewVisible) {
        filterInput = document.getElementById('notificationQueueFilter') || filterInput;
        sortSelect = document.getElementById('notificationQueueSort') || sortSelect;
    }
    
    // Get filter and sort values
    const filterText = filterInput?.value.toLowerCase() || '';
    const sortValue = sortSelect?.value || 'created_desc';
    
    // Filter notifications
    let filtered = allNotifications.filter(n => {
        if (!filterText) return true;
        return (
            n.recipient_email?.toLowerCase().includes(filterText) ||
            n.original_to?.toLowerCase().includes(filterText) ||
            n.recipient_domain?.toLowerCase().includes(filterText) ||
            n.smtp_code?.toLowerCase().includes(filterText)
        );
    });
    
    // Sort notifications
    filtered.sort((a, b) => {
        switch(sortValue) {
            case 'created_desc':
                return new Date(b.created_at) - new Date(a.created_at);
            case 'created_asc':
                return new Date(a.created_at) - new Date(b.created_at);
            case 'recipient_asc':
                return (a.recipient_email || '').localeCompare(b.recipient_email || '');
            case 'recipient_desc':
                return (b.recipient_email || '').localeCompare(a.recipient_email || '');
            case 'domain_asc':
                return (a.recipient_domain || '').localeCompare(b.recipient_domain || '');
            case 'domain_desc':
                return (b.recipient_domain || '').localeCompare(a.recipient_domain || '');
            default:
                return 0;
        }
    });
    
    // Display filtered and sorted notifications (update both containers if they exist)
    const buttonsHtml = `
        <div class="d-flex gap-2 mb-2">
            <button class="btn btn-secondary btn-sm" onclick="selectAllNotifications()">Select All</button>
            <button class="btn btn-outline-secondary btn-sm" onclick="deselectAllNotifications()">Deselect All</button>
            <button class="btn btn-primary btn-sm" onclick="sendSelectedNotifications()">Send Selected</button>
        </div>
    `;
    
    const htmlContent = `
        ${buttonsHtml}
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="selectAllNotifications"></th>
                        <th>Original CC (Notify)</th>
                        <th>Original To</th>
                        <th>Domain</th>
                        <th>SMTP Code</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    ${filtered.length === 0 ? '<tr><td colspan="6" class="text-center text-muted">No notifications match the filter</td></tr>' : ''}
                    ${filtered.map(n => `
                        <tr>
                            <td><input type="checkbox" class="notification-checkbox" value="${n.id}"></td>
                            <td>${n.recipient_email}</td>
                            <td>${n.original_to}</td>
                            <td>${n.recipient_domain}</td>
                            <td>
                                ${n.smtp_code || 'N/A'}
                                ${n.smtp_description ? `<br><small class="text-muted">${n.smtp_description}</small>` : ''}
                            </td>
                            <td>${new Date(n.created_at).toLocaleString()}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        </div>
        ${buttonsHtml}
        ${filterText ? `<small class="text-muted d-block mt-2">Showing ${filtered.length} of ${allNotifications.length} notifications</small>` : ''}
    `;
    
    // Update all containers
    containersToUpdate.forEach(container => {
        container.innerHTML = htmlContent;
    });
    
    // Select all checkbox (attach event listener - use querySelectorAll to handle multiple instances)
    document.querySelectorAll('#selectAllNotifications').forEach(selectAll => {
        // Remove existing listeners by cloning
        const newSelectAll = selectAll.cloneNode(true);
        selectAll.parentNode.replaceChild(newSelectAll, selectAll);
        newSelectAll.addEventListener('change', function(e) {
            document.querySelectorAll('.notification-checkbox').forEach(cb => {
                cb.checked = e.target.checked;
            });
        });
    });
}

function selectAllNotifications() {
    document.getElementById('selectAllNotifications').checked = true;
    document.querySelectorAll('.notification-checkbox').forEach(cb => {
        cb.checked = true;
    });
}

function deselectAllNotifications() {
    document.getElementById('selectAllNotifications').checked = false;
    document.querySelectorAll('.notification-checkbox').forEach(cb => {
        cb.checked = false;
    });
}

async function sendSelectedNotifications() {
    if (!userIsAdmin) {
        alert('Only administrators can send notifications.');
        return;
    }
    
    const selected = Array.from(document.querySelectorAll('.notification-checkbox:checked')).map(cb => parseInt(cb.value));
    if (selected.length === 0) {
        alert('Please select at least one notification');
        return;
    }
    
    try {
        const response = await fetch('/api/notifications.php?action=send', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ ids: selected })
        });
        
        const data = await response.json();
        if (data.success) {
            alert(`Sent ${selected.length} notification(s)`);
            loadNotificationQueue();
        } else {
            alert('Error: ' + data.error);
        }
    } catch (error) {
        alert('Error sending notifications: ' + error.message);
    }
}

// Deduplicate notifications
async function deduplicateNotifications() {
    if (!userIsAdmin) {
        alert('Only administrators can deduplicate notifications.');
        return;
    }
    
    if (!confirm('This will remove duplicate notifications (same CC recipient + TO address pair), keeping only the newest one for each pair. Continue?')) {
        return;
    }
    
    try {
        const response = await fetch('/api/notifications.php?action=deduplicate', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' }
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        
        if (data.success) {
            // Show results in a dialog
            const merged = data.merged || 0;
            const deleted = data.deleted || 0;
            
            let message = 'Deduplication completed successfully.\n\n';
            if (merged > 0) {
                message += `${merged} notification(s) were merged.\n`;
                message += `${deleted} duplicate(s) were removed.\n\n`;
                message += 'The newest notification was kept for each duplicate CC+TO pair.';
            } else {
                message += 'No duplicate notifications found.';
            }
            
            alert(message);
            
            // Refresh the notification queue to show updated data
            await loadNotificationQueue();
            loadDashboard();
        } else {
            throw new Error(data.error || 'Deduplication failed');
        }
    } catch (error) {
        console.error('Error deduplicating notifications:', error);
        alert('Error: ' + error.message);
    }
}

// User Management
document.getElementById('userManagementBtn').addEventListener('click', async function() {
    try {
        const response = await fetch('/api/users.php?action=list');
        const data = await response.json();
        if (data.success) {
            displayUsers(data.data);
            new bootstrap.Modal(document.getElementById('userModal')).show();
        }
    } catch (error) {
        alert('Error loading users: ' + error.message);
    }
});

function displayUsers(users) {
    const tbody = document.getElementById('userTableBody');
    tbody.innerHTML = users.map(user => `
        <tr>
            <td>${user.email}</td>
            <td>${user.name}</td>
            <td>${user.provider}</td>
            <td><input type="checkbox" ${user.is_admin == 1 ? 'checked' : ''} onchange="updateUser(${user.id}, 'is_admin', this.checked)"></td>
            <td><input type="checkbox" ${user.is_active == 1 ? 'checked' : ''} onchange="updateUser(${user.id}, 'is_active', this.checked)"></td>
            <td>
                <button class="btn btn-sm btn-danger" onclick="deleteUser(${user.id})">Delete</button>
            </td>
        </tr>
    `).join('');
}

async function updateUser(id, field, value) {
    try {
        const data = { id, [field]: value ? 1 : 0 };
        const response = await fetch('/api/users.php?action=update', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        const result = await response.json();
        if (result.success) {
            alert('User updated successfully');
            addEventLogMessage('success', `User ${field} updated successfully`);
        } else {
            alert('Error updating user: ' + (result.error || 'Unknown error'));
        }
    } catch (error) {
        alert('Error updating user: ' + error.message);
    }
}

async function deleteUser(id) {
    if (!confirm('Are you sure you want to delete this user?')) {
        return;
    }
    
    try {
        const response = await fetch(`/api/users.php?action=delete&id=${id}`, { method: 'DELETE' });
        const data = await response.json();
        if (data.success) {
            alert('User deleted successfully');
            addEventLogMessage('success', 'User deleted successfully');
            document.getElementById('userManagementBtn').click(); // Reload
        } else {
            alert('Error: ' + data.error);
        }
    } catch (error) {
        alert('Error deleting user: ' + error.message);
    }
}

// Help System
function showHelp() {
    const helpContent = document.getElementById('helpContent');
    if (!helpContent) return;
    
    helpContent.innerHTML = `
        <div class="help-content">
            <h4><i class="bi bi-info-circle"></i> About Bounce Monitor</h4>
            <p>Bounce Monitor is a comprehensive web application for monitoring email bounce mailboxes, parsing bounce messages, managing bounce notifications, and tracking domain trust scores.</p>
            
            <hr>
            
            <h5><i class="bi bi-speedometer2"></i> Dashboard</h5>
            <p>The Dashboard provides an overview of your bounce monitoring system with real-time statistics and key information.</p>
            
            <h6>Statistics Header</h6>
            <ul>
                <li><strong>Total Bounces</strong>: Total number of bounce messages processed</li>
                <li><strong>Queued Notifications</strong>: Number of pending notifications waiting to be sent</li>
                <li><strong>Domains</strong>: Total number of unique recipient domains</li>
                <li><strong>Mailboxes</strong>: Number of active mailboxes being monitored</li>
            </ul>
            
            <h6>Timeline Charts</h6>
            <p>The Dashboard includes two interactive timeline charts:</p>
            <ul>
                <li><strong>SMTP Codes Timeline</strong>: Shows daily bounce counts grouped by SMTP error code over time. Each SMTP code is displayed with a distinct color.</li>
                <li><strong>Domains Timeline</strong>: Shows daily bounce counts for the top 15 domains by bounce volume. Each domain is displayed with a distinct color.</li>
            </ul>
            <p><strong>Chart Controls</strong>:</p>
            <ul>
                <li><strong>Zoom In</strong>: Reduce the date range by 50% to focus on a specific time period</li>
                <li><strong>Zoom Out</strong>: Expand the date range by 100% to see more data</li>
                <li><strong>Reset</strong>: Restore the chart to show the full date range (from oldest to newest bounce)</li>
            </ul>
            <p>Charts automatically scale to show all available data by default. Use the zoom controls to focus on specific time periods.</p>
            
            <h6>Domains Panel</h6>
            <p>Shows all recipient domains that have received bounces:</p>
            <ul>
                <li><strong>Domain Name</strong>: The recipient domain</li>
                <li><strong>Trust Score</strong>: Score from 1-10 (1 = least trusted, 10 = most trusted)</li>
                <li><strong>Bounce Count</strong>: Total number of bounces for this domain</li>
                <li><strong>Last Bounce</strong>: When the last bounce occurred</li>
                <li><strong>Permanent/Temporary Failures</strong>: Breakdown of failure types</li>
                <li><strong>Invalid Domain Badge</strong>: Red badge indicates domain does not resolve via DNS</li>
            </ul>
            <p><strong>Click any domain</strong> to expand and view:</p>
            <ul>
                <li>Recent bounces with details</li>
                <li>SMTP codes breakdown</li>
                <li>Bounce timeline (last 30 days)</li>
                <li>Associated email addresses (for invalid domains)</li>
            </ul>
            
            <h6>SMTP Codes Panel</h6>
            <p>Shows all SMTP error codes encountered:</p>
            <ul>
                <li><strong>SMTP Code</strong>: The error code (e.g., 550, 451)</li>
                <li><strong>Description</strong>: What the code means</li>
                <li><strong>Recommendation</strong>: Suggested action</li>
                <li><strong>Occurrences</strong>: How many times this code was seen</li>
                <li><strong>Affected Domains</strong>: Number of domains with this code</li>
                <li><strong>Last Seen</strong>: When this code was last encountered</li>
            </ul>
            <p><strong>Click any SMTP code</strong> to expand and view all affected domains with bounce counts.</p>
            
            <h6>Notification Queue (Dashboard)</h6>
            <p>Shows pending notifications that need to be sent:</p>
            <ul>
                <li><strong>Filter</strong>: Search notifications by recipient, domain, or SMTP code</li>
                <li><strong>Sort</strong>: Sort by date, recipient, or domain</li>
                <li><strong>Apply</strong>: Apply filter and sort settings</li>
                <li><strong>Deduplicate</strong>: Remove duplicate notifications (admin only)</li>
                <li><strong>Select All/Deselect All</strong>: Select or deselect all notifications</li>
                <li><strong>Send Selected</strong>: Send selected notifications (admin only)</li>
            </ul>
            
            <hr>
            
            <h5><i class="bi bi-envelope"></i> Notification Queue Page</h5>
            <p>Dedicated page for managing pending notifications. Same features as Dashboard notification queue but with more space for viewing.</p>
            <p><strong>Note</strong>: All write operations (Send, Deduplicate) require admin privileges.</p>
            
            <hr>
            
            <h5><i class="bi bi-list-ul"></i> Event Log</h5>
            <p>Comprehensive log of all system events and activities.</p>
            
            <h6>Features</h6>
            <ul>
                <li><strong>Search</strong>: Search events by text content</li>
                <li><strong>Severity Filter</strong>: Filter by info, success, warning, error, or debug</li>
                <li><strong>Sort</strong>: Sort by date or severity</li>
                <li><strong>Refresh</strong>: Manually refresh the event log</li>
                <li><strong>Pagination</strong>: Navigate through events (50+ per page)</li>
            </ul>
            
            <h6>Event Types</h6>
            <ul>
                <li><strong>Info</strong>: General information messages</li>
                <li><strong>Success</strong>: Successful operations</li>
                <li><strong>Warning</strong>: Warning messages</li>
                <li><strong>Error</strong>: Error messages</li>
                <li><strong>Debug</strong>: Debug information (detailed troubleshooting)</li>
            </ul>
            
            <hr>
            
            <h5><i class="bi bi-gear"></i> Control Panel</h5>
            <p>Administrative controls and configuration (admin only for write operations).</p>
            
            <h6>Settings</h6>
            <ul>
                <li><strong>Test Mode</strong>: When enabled, all notifications go to override email instead of original recipients</li>
                <li><strong>Override Email</strong>: Email address to receive test notifications</li>
                <li><strong>Real-time Notifications</strong>: When enabled, notifications are sent immediately after processing. When disabled, notifications are queued for manual sending</li>
                <li><strong>BCC Monitoring</strong>: When enabled, all outbound notifications (in production mode) will be BCC'd to the specified email addresses. This allows monitoring of notification delivery without affecting the original recipients. Supports multiple comma-separated email addresses.</li>
            </ul>
            
            <h6>Mailbox Management</h6>
            <ul>
                <li><strong>Add Mailbox</strong>: Add a new IMAP mailbox to monitor</li>
                <li><strong>Edit Mailbox</strong>: Click on a mailbox in the list to edit</li>
                <li><strong>Test Connection</strong>: Test IMAP connection before saving</li>
                <li><strong>Browse Folders</strong>: Select folders from the IMAP server</li>
                <li><strong>Run Processing</strong>: Manually process all enabled mailboxes (admin only)</li>
            </ul>
            
            <h6>Notification Template</h6>
            <p>Customize the email template sent to original CC recipients when bounces occur.</p>
            <p><strong>Available Placeholders</strong>:</p>
            <ul>
                <li><code>{{original_to}}</code> - Original TO address</li>
                <li><code>{{original_cc}}</code> - Original CC addresses</li>
                <li><code>{{original_subject}}</code> - Original email subject</li>
                <li><code>{{bounce_date}}</code> - Bounce date and time</li>
                <li><code>{{smtp_code}}</code> - SMTP error code</li>
                <li><code>{{smtp_reason}}</code> - SMTP error reason</li>
                <li><code>{{recipient_domain}}</code> - Recipient domain</li>
                <li><code>{{recommendation}}</code> - SMTP code recommendation</li>
            </ul>
            
            <h6>Relay Providers</h6>
            <p>Configure SMTP relay providers for sending notifications:</p>
            <ul>
                <li><strong>Add Relay Provider</strong>: Add a new SMTP relay</li>
                <li><strong>Edit Relay Provider</strong>: Click on a relay in the list to edit</li>
                <li><strong>Test Connection</strong>: Test SMTP connection before saving</li>
                <li><strong>Assign to Mailbox</strong>: Each mailbox can use a specific relay provider</li>
            </ul>
            
            <h6>Backup & Restore</h6>
            <ul>
                <li><strong>Backup Configuration</strong>: Download configuration as JSON file (includes users, mailboxes, relay providers, templates, settings)</li>
                <li><strong>Restore from Backup</strong>: Upload a backup JSON file to restore configuration</li>
                <li><strong>Note</strong>: Backup excludes bounce data and logs</li>
            </ul>
            
            <h6>Database Operations</h6>
            <ul>
                <li><strong>Queue Notifications from Existing Bounces</strong>: Retroactively queue notifications for bounces that already exist (admin only)</li>
                <li><strong>Reset Database</strong>: Clear all bounces, notifications, domains, and events (keeps users, relays, mailboxes) (admin only)</li>
            </ul>
            
            <hr>
            
            <h5><i class="bi bi-play-circle"></i> Header Buttons</h5>
            
            <h6>Dashboard</h6>
            <p>Switch to the Dashboard view showing statistics, domains, SMTP codes, and notification queue.</p>
            
            <h6>Notification Queue</h6>
            <p>Switch to the dedicated Notification Queue page for managing pending notifications.</p>
            
            <h6>Event Log</h6>
            <p>Switch to the Event Log view showing all system events and activities.</p>
            
            <h6>Control Panel</h6>
            <p>Switch to the Control Panel for administrative tasks and configuration.</p>
            
            <h6>RUN CRON</h6>
            <p>Manually execute the cron script to process mailboxes and send notifications (admin only). This is equivalent to running <code>notify-cron.php</code> from the command line.</p>
            
            <h6>Help</h6>
            <p>Open this help documentation.</p>
            
            <h6>Theme Toggle</h6>
            <p>Switch between light and dark themes. Your preference is saved.</p>
            
            <h6>User Menu</h6>
            <ul>
                <li><strong>User Management</strong>: Manage users, grant admin privileges, enable/disable users (admin only)</li>
                <li><strong>Logout</strong>: Sign out of the application</li>
            </ul>
            
            <hr>
            
            <h5><i class="bi bi-shield-check"></i> User Roles & Permissions</h5>
            
            <h6>Administrator</h6>
            <p>Full access to all features:</p>
            <ul>
                <li>Run processing</li>
                <li>Send notifications</li>
                <li>Deduplicate notifications</li>
                <li>Reset database</li>
                <li>Manage users</li>
                <li>Configure mailboxes and relay providers</li>
                <li>Backup/restore configuration</li>
                <li>Modify settings and templates</li>
            </ul>
            
            <h6>Read-only User</h6>
            <p>Can view all data but cannot perform write operations:</p>
            <ul>
                <li>View dashboard</li>
                <li>View event log</li>
                <li>View notification queue</li>
                <li>Filter and sort data</li>
                <li><strong>Cannot</strong> run processing, send notifications, or modify configuration</li>
            </ul>
            
            <p><strong>Note</strong>: The first user to log in automatically becomes an administrator. All subsequent users are read-only until approved by an admin.</p>
            
            <hr>
            
            <h5><i class="bi bi-clock-history"></i> How It Works</h5>
            
            <h6>Processing Flow</h6>
            <ol>
                <li><strong>Connect to Mailbox</strong>: System connects to configured IMAP mailboxes</li>
                <li><strong>Read Messages</strong>: Reads messages from the INBOX folder</li>
                <li><strong>Identify Bounces</strong>: Determines if message is a legitimate bounce (not auto-reply, OOO, etc.)</li>
                <li><strong>Parse Bounce</strong>: Extracts bounce information (SMTP code, reason, recipient, etc.)</li>
                <li><strong>Store Bounce</strong>: Saves bounce record to database</li>
                <li><strong>Calculate Trust Score</strong>: Updates domain trust score based on bounce</li>
                <li><strong>Queue Notification</strong>: If CC addresses found, queues notification to original CC recipients</li>
                <li><strong>Move Message</strong>: Moves processed message to PROCESSED, PROBLEM, or SKIPPED folder</li>
            </ol>
            
            <h6>Notification Flow</h6>
            <ol>
                <li><strong>Queue</strong>: Notifications are queued when bounces are processed</li>
                <li><strong>Real-time Mode</strong>: Notifications sent immediately after processing</li>
                <li><strong>Queue Mode</strong>: Notifications queued for manual review and sending</li>
                <li><strong>Send</strong>: Admin selects notifications and sends them</li>
                <li><strong>Template</strong>: Email template is used with placeholders filled in</li>
                <li><strong>Delivery</strong>: Notification sent via configured SMTP relay provider</li>
            </ol>
            
            <h6>Trust Score Calculation</h6>
            <p>Trust scores (1-10) are calculated based on:</p>
            <ul>
                <li><strong>DNS Reputation</strong>: MX records, SPF, DMARC, domain resolution</li>
                <li><strong>Bounce Patterns</strong>: Historical bounce count, permanent vs temporary failures</li>
                <li><strong>SMTP Codes</strong>: Specific error codes affect score differently</li>
                <li><strong>Recency</strong>: Recent bounces have more impact</li>
                <li><strong>Spam Score</strong>: Spam indicators reduce trust</li>
            </ul>
            
            <hr>
            
            <h5><i class="bi bi-question-circle"></i> Common Questions</h5>
            
            <h6>Why are some domains marked as invalid?</h6>
            <p>Domains are validated via DNS lookups. If a domain doesn't resolve (no A, AAAA, or MX records), it's marked as invalid. This often indicates typos in email addresses.</p>
            
            <h6>What does trust score mean?</h6>
            <p>Trust score (1-10) indicates how trustworthy a recipient domain is. Lower scores indicate more bounce problems. Scores are calculated automatically based on bounce history and DNS reputation.</p>
            
            <h6>Why are notifications queued instead of sent?</h6>
            <p>If "Real-time Notifications" is disabled in Control Panel, notifications are queued for manual review. Enable it to send notifications immediately after processing.</p>
            
            <h6>How do I test without sending real notifications?</h6>
            <p>Enable "Test Mode" in Control Panel and set an override email address. All notifications will go to that address instead of original recipients.</p>
            
            <h6>What's the difference between permanent and temporary failures?</h6>
            <p>Permanent failures (550-559) indicate the email address doesn't exist or is blocked. Temporary failures (450-459) indicate temporary issues like mailbox full or server problems.</p>
            
            <h6>How often should I run processing?</h6>
            <p>Set up a cron job to run <code>notify-cron.php</code> every 5-15 minutes for automated processing. You can also use the "RUN CRON" button for manual execution.</p>
            
            <hr>
            
            <h5><i class="bi bi-book"></i> Additional Resources</h5>
            <ul>
                <li>See README.md in the repository for detailed installation and configuration instructions</li>
                <li>Check the Event Log for detailed error messages and troubleshooting information</li>
                <li>Review SMTP code descriptions in the Dashboard for recommendations</li>
            </ul>
        </div>
    `;
    
    const helpModal = new bootstrap.Modal(document.getElementById('helpModal'));
    helpModal.show();
}

// Bad Addresses
async function loadBadAddresses() {
    const container = document.getElementById('badAddressesList');
    if (!container) return;
    
    try {
        container.innerHTML = '<p class="text-muted text-center"><span class="spinner-border spinner-border-sm me-2"></span>Loading bad addresses...</p>';
        
        const response = await fetch('/api/bad-addresses.php');
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        if (data.success) {
            displayBadAddresses(data.data || [], data.total || 0);
        } else {
            throw new Error(data.error || 'Failed to load bad addresses');
        }
    } catch (error) {
        console.error('Error loading bad addresses:', error);
        container.innerHTML = `<div class="alert alert-danger">Error loading bad addresses: ${error.message}</div>`;
    }
}

function displayBadAddresses(addresses, total) {
    const container = document.getElementById('badAddressesList');
    if (!container) return;
    
    // Store addresses globally for CSV export
    badAddressesData = addresses;
    
    if (addresses.length === 0) {
        container.innerHTML = '<p class="text-muted text-center">No bad addresses found</p>';
        return;
    }
    
    let html = `
        <div class="mb-3">
            <p class="text-muted mb-0"><strong>Total:</strong> ${total} unique email address${total !== 1 ? 'es' : ''} with bounces</p>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-striped">
                <thead>
                    <tr>
                        <th style="width: 5%;">#</th>
                        <th style="width: 40%;">Email Address</th>
                        <th style="width: 10%;" class="text-center">Bounce Count</th>
                        <th style="width: 15%;">First Bounce</th>
                        <th style="width: 15%;">Last Bounce</th>
                        <th style="width: 15%;">SMTP Codes</th>
                    </tr>
                </thead>
                <tbody>
    `;
    
    addresses.forEach((address, index) => {
        const firstBounce = address.first_bounce ? new Date(address.first_bounce).toLocaleDateString() : 'N/A';
        const lastBounce = address.last_bounce ? new Date(address.last_bounce).toLocaleDateString() : 'N/A';
        const smtpCodes = Array.isArray(address.smtp_codes) && address.smtp_codes.length > 0
            ? address.smtp_codes.filter(code => code && code.trim() !== '').join(', ')
            : 'N/A';
        
        // Color code based on bounce count
        let bounceCountClass = '';
        if (address.bounce_count >= 10) {
            bounceCountClass = 'text-danger fw-bold';
        } else if (address.bounce_count >= 5) {
            bounceCountClass = 'text-warning fw-bold';
        } else {
            bounceCountClass = 'text-info';
        }
        
        html += `
            <tr>
                <td>${index + 1}</td>
                <td><code>${escapeHtml(address.original_to)}</code></td>
                <td class="text-center ${bounceCountClass}">${address.bounce_count}</td>
                <td>${firstBounce}</td>
                <td>${lastBounce}</td>
                <td><small>${escapeHtml(smtpCodes)}</small></td>
            </tr>
        `;
    });
    
    html += `
                </tbody>
            </table>
        </div>
    `;
    
    container.innerHTML = html;
}

function exportBadAddressesCSV() {
    if (!badAddressesData || badAddressesData.length === 0) {
        alert('No data to export. Please load bad addresses first.');
        return;
    }
    
    // CSV headers
    const headers = [
        'Email Address',
        'Bounce Count',
        'First Bounce',
        'Last Bounce',
        'SMTP Codes',
        'Domains'
    ];
    
    // Convert data to CSV rows
    const rows = badAddressesData.map(address => {
        const firstBounce = address.first_bounce ? new Date(address.first_bounce).toISOString() : '';
        const lastBounce = address.last_bounce ? new Date(address.last_bounce).toISOString() : '';
        const smtpCodes = Array.isArray(address.smtp_codes) && address.smtp_codes.length > 0
            ? address.smtp_codes.filter(code => code && code.trim() !== '').join('; ')
            : '';
        const domains = Array.isArray(address.domains) && address.domains.length > 0
            ? address.domains.filter(domain => domain && domain.trim() !== '').join('; ')
            : '';
        
        // Escape CSV values (handle commas, quotes, newlines)
        const escapeCsvValue = (value) => {
            if (value === null || value === undefined) return '';
            const str = String(value);
            if (str.includes(',') || str.includes('"') || str.includes('\n')) {
                return '"' + str.replace(/"/g, '""') + '"';
            }
            return str;
        };
        
        return [
            escapeCsvValue(address.original_to),
            escapeCsvValue(address.bounce_count),
            escapeCsvValue(firstBounce),
            escapeCsvValue(lastBounce),
            escapeCsvValue(smtpCodes),
            escapeCsvValue(domains)
        ].join(',');
    });
    
    // Combine headers and rows
    const csvContent = [headers.join(','), ...rows].join('\n');
    
    // Create BOM for UTF-8 to ensure proper encoding in Excel
    const BOM = '\uFEFF';
    const blob = new Blob([BOM + csvContent], { type: 'text/csv;charset=utf-8;' });
    
    // Generate filename with current datetime
    const now = new Date();
    const dateStr = now.toISOString().replace(/[:.]/g, '-').slice(0, -5); // Format: YYYY-MM-DDTHH-MM-SS
    const filename = `bounces-${dateStr}.csv`;
    
    // Create download link
    const link = document.createElement('a');
    const url = URL.createObjectURL(blob);
    link.setAttribute('href', url);
    link.setAttribute('download', filename);
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Run Cron Script
async function runCron() {
    if (!userIsAdmin) {
        alert('Only administrators can run the cron script.');
        return;
    }
    
    const runCronBtn = document.getElementById('runCronBtn');
    const originalText = runCronBtn ? runCronBtn.innerHTML : '';
    
    try {
        console.log('[DEBUG] runCron: Starting cron execution');
        addEventLogMessage('info', '[DEBUG] runCron: Starting cron script execution...');
        
        // Disable button and show loading state
        if (runCronBtn) {
            runCronBtn.disabled = true;
            runCronBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Running...';
        }
        
        console.log('[DEBUG] runCron: Calling /api/cron.php?action=run');
        const response = await fetch('/api/cron.php?action=run', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' }
        });
        
        console.log('[DEBUG] runCron: Response status:', response.status, response.statusText);
        addEventLogMessage('info', '[DEBUG] runCron: HTTP response status: ' + response.status);
        
        if (!response.ok) {
            const errorText = await response.text();
            console.error('[DEBUG] runCron: Error response body:', errorText);
            addEventLogMessage('error', '[DEBUG] runCron: HTTP error response: ' + errorText);
            throw new Error(`HTTP error! status: ${response.status}, body: ${errorText}`);
        }
        
        const responseText = await response.text();
        console.log('[DEBUG] runCron: Response body:', responseText);
        addEventLogMessage('info', '[DEBUG] runCron: Response body: ' + responseText.substring(0, 200));
        
        let data;
        try {
            data = JSON.parse(responseText);
        } catch (e) {
            console.error('[DEBUG] runCron: JSON parse error:', e, 'Response text:', responseText);
            addEventLogMessage('error', '[DEBUG] runCron: Failed to parse JSON: ' + e.message);
            throw new Error('Invalid JSON response: ' + e.message);
        }
        
        console.log('[DEBUG] runCron: Parsed data:', data);
        
        if (data.success) {
            addEventLogMessage('success', '[DEBUG] runCron: Cron script started successfully - checking execution...');
            // Wait longer before refreshing to see if script actually runs
            // Also refresh event log immediately to see backend debug messages
            setTimeout(() => {
                console.log('[DEBUG] runCron: First refresh - checking for execution logs');
                loadEventLog();
            }, 1000);
            
            // Refresh again after longer delay to see results
            setTimeout(() => {
                console.log('[DEBUG] runCron: Second refresh - checking for results');
                loadDashboard();
                loadNotificationQueue();
                loadEventLog();
            }, 5000);
        } else {
            addEventLogMessage('error', '[DEBUG] runCron: Error starting cron script: ' + (data.error || 'Unknown error'));
            alert('Error: ' + (data.error || 'Failed to start cron script'));
        }
    } catch (error) {
        console.error('[DEBUG] runCron: Exception caught:', error);
        addEventLogMessage('error', '[DEBUG] runCron: Exception: ' + error.message);
        alert('Error running cron script: ' + error.message);
    } finally {
        // Re-enable button after a delay
        setTimeout(() => {
            if (runCronBtn) {
                runCronBtn.disabled = false;
                runCronBtn.innerHTML = originalText;
            }
        }, 3000);
    }
}

// Backup Configuration
async function backupConfig() {
    try {
        const response = await fetch('/api/backup.php?action=export');
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        // Get the filename from Content-Disposition header or use default
        const contentDisposition = response.headers.get('Content-Disposition');
        let filename = 'bounce-ng-backup.json';
        if (contentDisposition) {
            const filenameMatch = contentDisposition.match(/filename="(.+)"/);
            if (filenameMatch) {
                filename = filenameMatch[1];
            }
        }
        
        // Get the JSON data
        const blob = await response.blob();
        
        // Create download link
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
        
        alert('Configuration backup downloaded successfully');
        addEventLogMessage('success', 'Configuration backup downloaded successfully');
    } catch (error) {
        console.error('Error backing up configuration:', error);
        addEventLogMessage('error', 'Error backing up configuration: ' + error.message);
        alert('Error backing up configuration: ' + error.message);
    }
}

// Restore Configuration
async function restoreConfig() {
    const fileInput = document.getElementById('restoreFile');
    const file = fileInput.files[0];
    
    if (!file) {
        alert('Please select a backup file to restore');
        return;
    }
    
    if (!confirm('Are you sure you want to restore configuration from this backup? This will overwrite existing users, mailboxes, relays, templates, and settings.')) {
        return;
    }
    
    try {
        // Read file as text
        const fileContent = await file.text();
        
        // Validate JSON
        try {
            JSON.parse(fileContent);
        } catch (e) {
            throw new Error('Invalid JSON file: ' + e.message);
        }
        
        // Send to server
        const response = await fetch('/api/backup.php?action=import', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: fileContent
        });
        
        const data = await response.json();
        
        if (data.success) {
            addEventLogMessage('success', 'Configuration restored successfully');
            alert('Configuration restored successfully! The page will reload.');
            
            // Clear file input
            fileInput.value = '';
            
            // Reload page after a short delay
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        } else {
            throw new Error(data.error || 'Failed to restore configuration');
        }
    } catch (error) {
        console.error('Error restoring configuration:', error);
        addEventLogMessage('error', 'Error restoring configuration: ' + error.message);
        alert('Error restoring configuration: ' + error.message);
    }
}

