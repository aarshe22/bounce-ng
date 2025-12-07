// Global state
let currentMailboxId = null;
let eventPollInterval = null;

// Initialize app
document.addEventListener('DOMContentLoaded', function() {
    loadUserInfo();
    loadSettings();
    loadRelayProviders();
    loadMailboxes();
    loadDashboard();
    loadEventLog();
    loadNotificationQueue();
    
    // Add click handler for refresh button
    const refreshBtn = document.getElementById('refreshLogBtn');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', loadEventLog);
    }
    
    // Add change handler for event filter
    const eventFilter = document.getElementById('eventFilter');
    if (eventFilter) {
        eventFilter.addEventListener('change', loadEventLog);
    }
    
    // No auto-refresh for event log - user can manually refresh or it auto-refreshes during processing
    // Poll dashboard every 10 seconds
    setInterval(loadDashboard, 10000);
    setInterval(loadNotificationQueue, 5000);
});

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
        
        const response = await fetch('/api/mailboxes.php?action=list');
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        if (!data.success) {
            throw new Error(data.error || 'Failed to get mailbox list');
        }
        
        if (data.data.length === 0) {
            alert('No mailboxes configured');
            if (runBtn) {
                runBtn.disabled = false;
                runBtn.innerHTML = originalText;
            }
            return;
        }
        
        const enabledMailboxes = data.data.filter(m => m.is_enabled == 1);
        if (enabledMailboxes.length === 0) {
            alert('No enabled mailboxes to process');
            if (runBtn) {
                runBtn.disabled = false;
                runBtn.innerHTML = originalText;
            }
            return;
        }
        
        // Start auto-refresh during processing for real-time updates
        // Clear any existing interval first
        if (eventPollInterval) {
            clearInterval(eventPollInterval);
        }
        // Poll frequently during processing to see real-time progress
        eventPollInterval = setInterval(() => {
            loadEventLog();
        }, 1000); // Poll every 1 second during processing
        
        // Process all mailboxes - TRUE fire-and-forget
        // Send requests and immediately continue - don't wait for ANY response
        enabledMailboxes.forEach((mailbox) => {
            addEventLogMessage('info', `Starting processing for mailbox: ${mailbox.name}...`);
            
            // TRUE fire-and-forget: send request, don't wait, don't handle response
            // Processing happens server-side in background
            fetch('/api/mailboxes.php?action=process', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ mailbox_id: mailbox.id }),
                // No signal, no timeout handling - just send and forget
            })
            .then(() => {
                // Response received (or not) - doesn't matter, processing continues server-side
                addEventLogMessage('info', `✓ Processing ${mailbox.name} started in background`);
            })
            .catch(() => {
                // Any error is fine - request was sent, processing continues server-side
                addEventLogMessage('info', `✓ Processing ${mailbox.name} started in background (request sent)`);
            });
            
            // Show message immediately - don't wait
            addEventLogMessage('info', `✓ Processing ${mailbox.name} request sent (running in background)`);
        });
        
        addEventLogMessage('info', 'All processing requests sent. Watch event log for real-time progress...');
        
        // Keep button disabled - processing happens in background
        // Re-enable after a short delay (processing continues server-side)
        setTimeout(() => {
            if (runBtn) {
                runBtn.disabled = false;
                runBtn.innerHTML = originalText;
            }
            
            // Stop auto-refresh after processing starts (user can manually refresh)
            // Keep it running for a bit longer to catch initial progress, then stop
            setTimeout(() => {
                if (eventPollInterval) {
                    clearInterval(eventPollInterval);
                    eventPollInterval = null;
                }
            }, 30000); // Stop auto-refresh after 30 seconds (processing may still be running)
            
            // Refresh data
            loadMailboxes();
            loadDashboard();
            loadEventLog();
            loadNotificationQueue();
        }, 3000); // Re-enable button after 3 seconds (processing continues in background)
        
    } catch (error) {
        console.error('Error running processing:', error);
        addEventLogMessage('error', 'Error starting processing: ' + error.message);
        
        // Re-enable button on error
        if (runBtn) {
            runBtn.disabled = false;
            runBtn.innerHTML = originalText;
        }
        
        // Stop auto-refresh on error (no polling needed)
        if (eventPollInterval) {
            clearInterval(eventPollInterval);
            eventPollInterval = null;
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
    // Top domains
    const domainsContainer = document.getElementById('topDomains');
    domainsContainer.innerHTML = '';
    data.domains.slice(0, 10).forEach(domain => {
        const item = document.createElement('div');
        item.className = 'mb-2';
        const trustClass = domain.trust_score >= 70 ? 'high' : domain.trust_score >= 40 ? 'medium' : 'low';
        item.innerHTML = `
            <div class="d-flex justify-content-between">
                <span>${domain.domain}</span>
                <div>
                    <span class="badge bg-secondary me-1">${domain.bounce_count}</span>
                    <span class="badge badge-trust ${trustClass}">${domain.trust_score}</span>
                </div>
            </div>
        `;
        domainsContainer.appendChild(item);
    });
    
    // All SMTP codes (sorted by count descending)
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
    
    data.smtpCodes.forEach(code => {
        const item = document.createElement('div');
        item.className = 'mb-2 p-2 rounded';
        const description = code.description || 'No description available';
        const codeColor = getCodeColor(code.smtp_code);
        const bgClass = codeColor === 'danger' ? 'bg-danger bg-opacity-25' : 
                       codeColor === 'warning' ? 'bg-warning bg-opacity-25' : 
                       codeColor === 'success' ? 'bg-success bg-opacity-25' : 
                       'bg-info bg-opacity-25';
        item.className += ' ' + bgClass;
        
        item.innerHTML = `
            <div class="d-flex justify-content-between align-items-start">
                <div class="flex-grow-1">
                    <div class="fw-bold text-white">${code.smtp_code || 'N/A'}</div>
                    <small class="text-white-50">${description}</small>
                </div>
                <span class="badge bg-${codeColor} ms-2">${code.count}</span>
            </div>
        `;
        codesContainer.appendChild(item);
    });
}

function updateHeaderStats(stats) {
    document.getElementById('headerStats').innerHTML = `
        <span class="badge bg-info me-2">Bounces: ${stats.totalBounces}</span>
        <span class="badge bg-primary me-2">Domains: ${stats.totalDomains}</span>
        <span class="badge bg-success">Mailboxes: ${stats.activeMailboxes}</span>
    `;
}

// Event Log
async function loadEventLog() {
    const refreshBtn = document.getElementById('refreshLogBtn');
    const originalHtml = refreshBtn ? refreshBtn.innerHTML : null;
    
    try {
        if (refreshBtn) {
            // Show loading state on refresh button
            refreshBtn.disabled = true;
            refreshBtn.innerHTML = '<i class="bi bi-arrow-clockwise"></i> <span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';
        }
        
        const filter = document.getElementById('eventFilter').value;
        const url = filter ? `/api/events.php?limit=100&severity=${filter}` : '/api/events.php?limit=100';
        const response = await fetch(url);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        if (data.success) {
            displayEventLog(data.data);
        } else {
            throw new Error(data.error || 'Failed to load events');
        }
    } catch (error) {
        console.error('Error loading events:', error);
        // Still restore button even on error
    } finally {
        // Always restore button state, even if there was an error
        if (refreshBtn && originalHtml) {
            refreshBtn.disabled = false;
            refreshBtn.innerHTML = originalHtml;
        }
    }
}

function displayEventLog(events) {
    const container = document.getElementById('eventLog');
    container.innerHTML = '';
    
    // Display events in database ID order (newest first, as returned by ORDER BY id DESC)
    // Show timestamps but sort by ID (not timestamp) to ensure consistent ordering
    events.forEach(event => {
        const item = document.createElement('div');
        item.className = `event ${event.severity}`;
        
        // Format timestamp if available
        let timestampDisplay = '';
        if (event.created_at) {
            try {
                const date = new Date(event.created_at);
                // Format as: YYYY-MM-DD HH:MM:SS
                const year = date.getFullYear();
                const month = String(date.getMonth() + 1).padStart(2, '0');
                const day = String(date.getDate()).padStart(2, '0');
                const hours = String(date.getHours()).padStart(2, '0');
                const minutes = String(date.getMinutes()).padStart(2, '0');
                const seconds = String(date.getSeconds()).padStart(2, '0');
                timestampDisplay = `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
            } catch (e) {
                // If date parsing fails, use raw value
                timestampDisplay = event.created_at;
            }
        }
        
        const idDisplay = event.id ? `[ID:${event.id}]` : '';
        const timestampPart = timestampDisplay ? `<span class="text-muted">${timestampDisplay}</span> ` : '';
        item.innerHTML = `<span class="text-muted">${idDisplay}</span> ${timestampPart}<strong>${event.severity.toUpperCase()}</strong>: ${event.message}`;
        container.appendChild(item);
    });
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

function displayNotificationQueue(notifications) {
    const container = document.getElementById('notificationQueue');
    if (notifications.length === 0) {
        container.innerHTML = '<p class="text-muted">No pending notifications</p>';
        return;
    }
    
    container.innerHTML = `
        <div class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="selectAllNotifications"></th>
                        <th>Recipient</th>
                        <th>Original To</th>
                        <th>Domain</th>
                        <th>SMTP Code</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    ${notifications.map(n => `
                        <tr>
                            <td><input type="checkbox" class="notification-checkbox" value="${n.id}"></td>
                            <td>${n.recipient_email}</td>
                            <td>${n.original_to}</td>
                            <td>${n.recipient_domain}</td>
                            <td>${n.smtp_code || 'N/A'}</td>
                            <td>${new Date(n.created_at).toLocaleString()}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
            <div class="d-flex gap-2 mt-2">
                <button class="btn btn-secondary btn-sm" onclick="selectAllNotifications()">Select All</button>
                <button class="btn btn-outline-secondary btn-sm" onclick="deselectAllNotifications()">Deselect All</button>
                <button class="btn btn-primary btn-sm" onclick="sendSelectedNotifications()">Send Selected</button>
            </div>
        </div>
    `;
    
    document.getElementById('selectAllNotifications').addEventListener('change', function() {
        document.querySelectorAll('.notification-checkbox').forEach(cb => {
            cb.checked = this.checked;
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

