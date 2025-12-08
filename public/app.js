// Global state
let currentMailboxId = null;
let eventPollInterval = null;

// Event log pagination state
let eventLogCurrentPage = 1;
let eventLogPageSize = 50; // Will be calculated based on viewport
let eventLogTotalEvents = 0;
let eventLogAllEvents = []; // Store all events for client-side pagination

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
});

// View switching functions
function switchView(viewName) {
    // Hide all views
    document.querySelectorAll('.view-container').forEach(view => {
        view.style.display = 'none';
    });
    
    // Remove active class from all buttons
    document.querySelectorAll('#viewControlsBtn, #viewDashboardBtn, #viewEventsBtn, #viewNotificationsBtn').forEach(btn => {
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
                const year = date.getFullYear();
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const day = String(date.getDate()).padStart(2, '0');
                const hours = String(date.getHours()).padStart(2, '0');
                const minutes = String(date.getMinutes()).padStart(2, '0');
                const seconds = String(date.getSeconds()).padStart(2, '0');
                timestampDisplay = `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
            } catch (e) {
                timestampDisplay = event.created_at;
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
    // User info should be in session, but we'll get it from API if needed
    const userName = document.getElementById('userName');
    if (userName) {
        userName.textContent = 'User'; // Will be set from session
    }
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

document.getElementById('notificationModeToggle').addEventListener('change', function() {
    fetch('/api/settings.php?action=set', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ key: 'notification_mode', value: this.checked ? 'realtime' : 'queue' })
    });
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
            loadMailboxes();
        } else {
            alert('Error: ' + data.error);
        }
    } catch (error) {
        alert('Error deleting mailbox: ' + error.message);
    }
}

async function runProcessing() {
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

function displayDashboard(data) {
    // Top domains - enhanced display
    const domainsContainer = document.getElementById('topDomains');
    domainsContainer.innerHTML = '';
    
    if (data.domains.length === 0) {
        domainsContainer.innerHTML = '<p class="text-white-50 mb-0">No domains found</p>';
    } else {
        data.domains.forEach((domain, index) => {
            const item = document.createElement('div');
            item.className = 'mb-2 rounded bg-white bg-opacity-10 domain-item';
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
                detailsHtml += '<div class="p-2 pt-0 mt-2 border-top border-white border-opacity-25">';
                
                // Recent bounces section
                if (recentBounces.length > 0) {
                    detailsHtml += '<div class="mb-3">';
                    detailsHtml += '<small class="text-dark d-block mb-2 fw-bold"><i class="bi bi-clock-history"></i> Recent Bounces:</small>';
                    recentBounces.forEach(bounce => {
                        const bounceDate = new Date(bounce.bounce_date);
                        const statusColor = bounce.deliverability_status === 'permanent_failure' ? 'danger' : 
                                          bounce.deliverability_status === 'temporary_failure' ? 'warning' : 'secondary';
                        detailsHtml += `
                            <div class="small mb-1 p-1 bg-white bg-opacity-5 rounded">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="flex-grow-1">
                                        <span class="text-dark fw-bold">${bounce.original_to || 'N/A'}</span>
                                        ${bounce.original_subject ? `<div class="text-dark text-opacity-75">${bounce.original_subject}</div>` : ''}
                                        ${bounce.smtp_code ? `<span class="badge bg-${statusColor} ms-2">${bounce.smtp_code}</span>` : ''}
                                    </div>
                                    <small class="text-dark">${bounceDate.toLocaleDateString()} ${bounceDate.toLocaleTimeString()}</small>
                                </div>
                                ${bounce.smtp_reason ? `<div class="text-dark text-opacity-75 mt-1"><small>${bounce.smtp_reason}</small></div>` : ''}
                                ${bounce.smtp_description ? `<div class="text-dark text-opacity-75"><small><i>${bounce.smtp_description}</i></small></div>` : ''}
                            </div>
                        `;
                    });
                    detailsHtml += '</div>';
                }
                
                // SMTP codes breakdown
                if (smtpCodes.length > 0) {
                    detailsHtml += '<div class="mb-3">';
                    detailsHtml += '<small class="text-dark d-block mb-2 fw-bold"><i class="bi bi-list-ul"></i> SMTP Codes:</small>';
                    smtpCodes.forEach(code => {
                        const codeColor = code.smtp_code >= 550 && code.smtp_code <= 559 ? 'danger' : 
                                       code.smtp_code >= 450 && code.smtp_code <= 459 ? 'warning' : 'info';
                        detailsHtml += `
                            <div class="small mb-1 p-1 bg-white bg-opacity-5 rounded">
                                <span class="badge bg-${codeColor} me-2">${code.smtp_code}</span>
                                <span class="text-dark fw-bold">${code.count} occurrence${code.count !== 1 ? 's' : ''}</span>
                                ${code.description ? `<div class="text-dark text-opacity-75 mt-1">${code.description}</div>` : ''}
                                ${code.recommendation ? `<div class="text-dark text-opacity-75"><small><i class="bi bi-lightbulb"></i> ${code.recommendation}</small></div>` : ''}
                            </div>
                        `;
                    });
                    detailsHtml += '</div>';
                }
                
                // Bounce timeline (last 30 days)
                if (bounceTimeline.length > 0) {
                    detailsHtml += '<div class="mb-2">';
                    detailsHtml += '<small class="text-dark d-block mb-2 fw-bold"><i class="bi bi-graph-up"></i> Bounce Timeline (Last 30 Days):</small>';
                    bounceTimeline.slice(0, 10).forEach(day => {
                        const dayDate = new Date(day.bounce_day);
                        detailsHtml += `
                            <div class="small mb-1 p-1 bg-white bg-opacity-5 rounded">
                                <div class="d-flex justify-content-between">
                                    <span class="text-dark fw-bold">${dayDate.toLocaleDateString()}</span>
                                    <span class="text-dark">${day.bounce_count} bounce${day.bounce_count !== 1 ? 's' : ''}</span>
                                </div>
                                <div class="text-dark text-opacity-75">
                                    ${day.permanent_count > 0 ? `<span class="text-danger">${day.permanent_count} permanent</span>` : ''}
                                    ${day.temporary_count > 0 ? `<span class="text-warning ms-2">${day.temporary_count} temporary</span>` : ''}
                                </div>
                            </div>
                        `;
                    });
                    if (bounceTimeline.length > 10) {
                        detailsHtml += `<small class="text-dark text-opacity-75">... and ${bounceTimeline.length - 10} more days</small>`;
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
                        detailsHtml += '<div class="mt-2 p-2 bg-danger bg-opacity-25 rounded">';
                        detailsHtml += '<small class="fw-bold text-warning d-block mb-1"><i class="bi bi-exclamation-triangle"></i> Associated Email Addresses:</small>';
                        
                        if (emailPairs.length > 0) {
                            emailPairs.forEach(pair => {
                                detailsHtml += `<div class="small text-dark mb-1">`;
                                detailsHtml += `<span class="fw-bold">TO:</span> ${pair.to}`;
                                if (pair.cc) {
                                    detailsHtml += ` <span class="fw-bold ms-2">CC:</span> ${pair.cc}`;
                                }
                                detailsHtml += `</div>`;
                            });
                        } else {
                            if (toAddresses.length > 0) {
                                detailsHtml += `<div class="small mb-1"><span class="fw-bold text-dark">TO addresses:</span> <span class="text-dark">${toAddresses.join(', ')}</span></div>`;
                            }
                            if (ccAddresses.length > 0) {
                                detailsHtml += `<div class="small mb-1"><span class="fw-bold text-dark">CC addresses:</span> <span class="text-dark">${ccAddresses.join(', ')}</span></div>`;
                            }
                        }
                        detailsHtml += '</div>';
                    }
                }
                
                detailsHtml += '</div></div>';
            }
            
            item.innerHTML = `
                <div class="p-2 domain-header" style="cursor: pointer;">
                    <div class="d-flex justify-content-between align-items-start mb-1">
                        <div class="flex-grow-1">
                            <div class="fw-bold text-white ${isInvalid ? 'text-danger' : ''}">
                                ${domain.domain}
                                ${isInvalid ? '<i class="bi bi-exclamation-triangle-fill text-danger ms-1"></i>' : ''}
                                ${detailsHtml ? '<i class="bi bi-chevron-down ms-2 domain-chevron" style="font-size: 0.75rem; transition: transform 0.3s;"></i>' : ''}
                            </div>
                            <small class="text-white-50">Last bounce: ${lastBounceText}</small>
                            ${isInvalid ? `<small class="d-block text-warning"><i class="bi bi-info-circle"></i> ${domain.validation_reason || 'Invalid domain'}</small>` : ''}
                        </div>
                        <div class="text-end">
                            <span class="badge bg-secondary me-1" title="Total bounces">${domain.bounce_count}</span>
                            ${invalidBadge}
                            <span class="badge badge-trust ${trustClass}" title="Trust score (1-10)">${trustScore10}/10</span>
                        </div>
                    </div>
                    <div class="d-flex gap-2 mt-1">
                        ${permanentFailures > 0 ? `<small class="text-white-50"><i class="bi bi-exclamation-triangle"></i> ${permanentFailures} permanent</small>` : ''}
                        ${temporaryFailures > 0 ? `<small class="text-white-50"><i class="bi bi-clock"></i> ${temporaryFailures} temporary</small>` : ''}
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
        codesContainer.innerHTML = '<p class="text-white-50 mb-0">No SMTP codes found</p>';
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
        item.className = 'mb-2 rounded bg-white bg-opacity-10 smtp-code-item';
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
            domainsHtml += '<div class="p-2 pt-0 mt-2 border-top border-white border-opacity-25">';
            domainsHtml += '<small class="text-dark d-block mb-2"><i class="bi bi-globe"></i> Affected Domains:</small>';
            domains.forEach(domain => {
                const lastBounce = domain.last_bounce ? new Date(domain.last_bounce).toLocaleDateString() : 'N/A';
                domainsHtml += `
                    <div class="small mb-1 p-1 bg-white bg-opacity-5 rounded">
                        <span class="text-dark fw-bold">${domain.recipient_domain}</span>
                        <span class="text-dark ms-2">(${domain.bounce_count} bounce${domain.bounce_count !== 1 ? 's' : ''})</span>
                        <span class="text-dark ms-2">Last: ${lastBounce}</span>
                    </div>
                `;
            });
            domainsHtml += '</div></div>';
        }
        
        item.innerHTML = `
            <div class="p-2 smtp-code-header" style="cursor: pointer;">
                <div class="d-flex justify-content-between align-items-start mb-1">
                    <div class="flex-grow-1">
                        <div class="fw-bold text-white">
                            <span class="badge bg-${codeColor} me-2">${code.smtp_code || 'N/A'}</span>
                            ${description}
                            ${domains.length > 0 ? '<i class="bi bi-chevron-down ms-2 smtp-chevron" style="font-size: 0.75rem; transition: transform 0.3s;"></i>' : ''}
                        </div>
                        ${code.recommendation ? `<small class="text-white-50 d-block mt-1"><i class="bi bi-lightbulb"></i> ${code.recommendation}</small>` : ''}
                    </div>
                    <span class="badge bg-${codeColor} ms-2" title="Occurrences">${code.count}</span>
                </div>
                <div class="d-flex gap-3 mt-1">
                    <small class="text-white-50"><i class="bi bi-globe"></i> ${affectedDomains} domain(s)</small>
                    <small class="text-white-50"><i class="bi bi-calendar"></i> Last: ${lastSeenText}</small>
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
}

function updateHeaderStats(stats) {
    document.getElementById('headerStats').innerHTML = `
        <span class="badge bg-info me-2">Total Bounces: ${stats.totalBounces}</span>
        <span class="badge bg-warning me-2">Queued Notifications: ${stats.queuedNotifications || 0}</span>
        <span class="badge bg-primary me-2">Domains: ${stats.totalDomains}</span>
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
        if (!result.success) {
            alert('Error updating user');
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
            document.getElementById('userManagementBtn').click(); // Reload
        } else {
            alert('Error: ' + data.error);
        }
    } catch (error) {
        alert('Error deleting user: ' + error.message);
    }
}

// Run Cron Script
async function runCron() {
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

