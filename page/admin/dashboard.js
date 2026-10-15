(() => {
    const API = '../admin_api.php';
    const message = document.getElementById('message');
    const refreshButton = document.getElementById('refresh');
    let csrf = '';
    let snapshot = null;
    let busy = false;

    const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[char]);

    const formatNumber = value => Number(value || 0).toLocaleString();

    function formatSize(bytes) {
        const size = Number(bytes || 0);
        if (size < 1024) return `${formatNumber(size)} B`;
        if (size < 1048576) return `${(size / 1024).toFixed(1)} KB`;
        return `${(size / 1048576).toFixed(1)} MB`;
    }

    function formatDate(value) {
        if (!value) return '—';
        const date = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString();
    }

    function showMessage(text) {
        message.textContent = text;
        message.classList.toggle('visible', Boolean(text));
    }

    async function request(url, options = {}) {
        const response = await fetch(url, { credentials: 'same-origin', ...options });
        let result;
        try {
            result = await response.json();
        } catch {
            throw new Error('The server returned an invalid response. Please refresh and try again.');
        }
        if (!response.ok) {
            if (response.status === 401) location.replace('../login.html');
            throw new Error(result.message || 'The request failed.');
        }
        return result;
    }

    function renderSystem(system) {
        document.getElementById('database-count').textContent = formatNumber(system.databaseCount);
        document.getElementById('table-count').textContent = formatNumber(system.tableCount);
        document.getElementById('row-estimate').textContent = `Approx. stored rows: ${formatNumber(system.estimatedRows)}`;
        document.getElementById('active-count').textContent = formatNumber(system.accountSummary.active);
        document.getElementById('account-total').textContent =
            `${formatNumber(system.accountSummary.total)} total · ${formatNumber(system.accountSummary.disabled)} disabled`;
        document.getElementById('session-count').textContent = formatNumber(system.activeSessions);
        document.getElementById('login-count').textContent =
            `${formatNumber(system.successfulLogins24h)} successful sign-ins in the last 24 hours`;
        document.getElementById('mysql-version').textContent = system.mysqlVersion;
        document.getElementById('php-version').textContent = system.phpVersion;
        document.getElementById('server-time').textContent = formatDate(system.serverTime);
        document.getElementById('database-size').textContent = `${formatSize(system.sizeBytes)} approx.`;
        document.getElementById('updated-at').textContent = new Date().toLocaleTimeString();
        const status = document.getElementById('connection-status');
        status.textContent = 'Connected';
        status.classList.remove('offline');
    }

    function renderStorage(databases) {
        const host = document.getElementById('storage-analysis');
        const databaseSizes = databases.map(database => ({
            name: database.name,
            size: database.tables.reduce((total, table) => total + (Number(table.sizeBytes) || 0), 0)
        })).sort((left, right) => right.size - left.size);
        const maxDatabaseSize = Math.max(1, ...databaseSizes.map(database => database.size));
        const tables = databases.flatMap(database => database.tables.map(table => ({
            database: database.name,
            name: table.name,
            size: Number(table.sizeBytes) || 0
        }))).sort((left, right) => right.size - left.size).slice(0, 6);
        const maxTableSize = Math.max(1, ...tables.map(table => table.size));
        const rows = (items, max, label) => items.length
            ? items.map(item => `<div class="storage-row"><span class="storage-name" title="${escapeHtml(item.name)}">${escapeHtml(item.name)}</span><span class="storage-track" role="img" aria-label="${escapeHtml(item.name)} uses ${formatSize(item.size)}"><span class="storage-fill" style="display:block;width:${Math.max(item.size ? 2 : 0, item.size / max * 100)}%"></span></span><span class="storage-value">${formatSize(item.size)}</span>${label === 'Largest tables' ? `<span class="storage-name">${escapeHtml(item.database)}</span>` : ''}</div>`).join('')
            : '<div class="storage-empty">No storage metadata is available.</div>';
        host.innerHTML = `
            <article class="storage-card"><div class="storage-heading"><span>Storage by database</span><small>Estimated allocation</small></div>
                ${rows(databaseSizes, maxDatabaseSize, 'Databases')}
            </article>
            <article class="storage-card"><div class="storage-heading"><span>Largest table structures</span><small>Top ${tables.length}</small></div>
                ${rows(tables, maxTableSize, 'Largest tables')}
            </article>`;
    }

    function canArchiveTable(databaseName, tableName, type) {
        if (type !== 'BASE TABLE') return false;
        const tenantInventory = /^(?:assets_user_inventory_[1-9][0-9]*|[A-Z0-9_]+_INVENTORY)$/i.test(databaseName);
        const tenantControl = /^(?:assets_user_control_[1-9][0-9]*|[A-Z0-9_]+_USER_CONTROL)$/i.test(databaseName);
        if (!tenantInventory && !tenantControl) return false;
        const protectedTables = tenantInventory
            ? ['users', 'loginhistory', 'activitylogs', 'remembereddevices', 'accountlockouts', 'passwordresetrequests',
                'accountrecoveryattempts', 'workspacemembers', 'workspaceinvitations', 'workspacepreferences',
                'workspaceinvitationattempts', 'adminrecyclebin', 'adminauditlog']
            : ['navigation_menu', 'system_recycle_bin'];
        return !protectedTables.includes(tableName.toLocaleLowerCase());
    }

    function getDatabaseMatches() {
        const search = document.getElementById('database-search').value.trim().toLocaleLowerCase();
        const filtered = [];
        snapshot.databases.forEach(database => {
            const databaseMatches = database.name.toLocaleLowerCase().includes(search);
            const tables = search && !databaseMatches
                ? database.tables.filter(table => table.name.toLocaleLowerCase().includes(search))
                : database.tables;
            if (!search || databaseMatches || tables.length) filtered.push({ ...database, visibleTables: tables });
        });
        return filtered;
    }

    function renderDatabases() {
        const host = document.getElementById('databases');
        const databases = getDatabaseMatches();
        document.getElementById('database-result-count').textContent =
            `${databases.length} of ${snapshot.databases.length} databases`;
        if (!databases.length) {
            host.innerHTML = '<div class="empty">No databases or tables match that search.</div>';
            return;
        }
        host.innerHTML = databases.map(database => {
            const tables = database.visibleTables.map(table =>
                `<div class="table-item"><span class="table-dot"></span><span class="table-name" title="${escapeHtml(table.name)}">${escapeHtml(table.name)}</span><span class="table-type">${escapeHtml(table.type === 'VIEW' ? 'VIEW' : 'TABLE')}</span><span class="table-meta hide-mobile">${escapeHtml(table.engine || '—')}</span><span class="table-meta">${table.rows === null ? '—' : formatNumber(table.rows)} rows</span><span class="table-meta hide-mobile">${table.lastChangedAt ? `Changed ${escapeHtml(formatDate(table.lastChangedAt))}` : ''}</span>${canArchiveTable(database.name, table.name, table.type) ? `<button class="row-action" type="button" data-archive-table="${escapeHtml(database.name)}" data-table-name="${escapeHtml(table.name)}" aria-label="Archive table ${escapeHtml(database.name)}.${escapeHtml(table.name)}" title="Archive table">⌫</button>` : ''}</div>`
            ).join('');
            const size = database.tables.reduce((total, table) => total + (Number(table.sizeBytes) || 0), 0);
            const expanded = document.getElementById('database-search').value.trim()
                && database.visibleTables.length !== database.tables.length;
            const tenantInventory = /^(?:assets_user_inventory_[1-9][0-9]*|[A-Z0-9_]+_INVENTORY)$/i.test(database.name);
            const removable = tenantInventory;
            return `<article class="db-card${expanded ? ' expanded' : ''}${removable ? ' workspace-removable' : ''}" data-database="${escapeHtml(database.name.toLocaleLowerCase())}">
                <div class="db-heading">
                    <button class="db-summary" type="button" aria-expanded="${Boolean(expanded)}">
                        <span class="db-symbol" aria-hidden="true">▤</span>
                        <span class="db-name"><strong>${escapeHtml(database.name)}</strong><small>${database.tables.length} tables &amp; views · ${database.lastUsedAt ? `Workspace last used ${escapeHtml(formatDate(database.lastUsedAt))}` : 'Last used unavailable'}</small></span>
                        <span class="db-stats"><span>${formatNumber(database.tables.length)} objects</span>${size ? `<span>${formatSize(size)}</span>` : ''}</span>
                        <span class="db-arrow" aria-hidden="true">⌄</span>
                    </button>
                    ${removable ? `<button class="row-action archive-action" type="button" data-archive-workspace="${escapeHtml(database.name)}" aria-label="Archive workspace database ${escapeHtml(database.name)}" title="Archive workspace">⌫</button>` : ''}
                </div>
                <div class="db-tables">${tables || '<div class="empty">No tables in this database.</div>'}</div>
            </article>`;
        }).join('');
        host.querySelectorAll('.db-summary').forEach(button => {
            button.addEventListener('click', () => {
                const card = button.closest('.db-card');
                const expanded = card.classList.toggle('expanded');
                button.setAttribute('aria-expanded', String(expanded));
            });
        });
        host.querySelectorAll('[data-archive-workspace]').forEach(button => {
            button.addEventListener('click', () => runRecycleAction({
                operation: 'archive_workspace',
                databaseName: button.dataset.archiveWorkspace
            }, button));
        });
        host.querySelectorAll('[data-archive-table]').forEach(button => {
            button.addEventListener('click', () => runRecycleAction({
                operation: 'archive_table',
                databaseName: button.dataset.archiveTable,
                tableName: button.dataset.tableName
            }, button));
        });
    }

    async function runRecycleAction(payload, button) {
        if (button.disabled) return;
        button.disabled = true;
        try {
            const result = await request(`${API}?action=recycle_action`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify(payload)
            });
            await load();
            showMessage(result.message || 'Action completed.');
        } catch (error) {
            showMessage(error.message);
        } finally {
            button.disabled = false;
        }
    }

    function filteredUsers() {
        const search = document.getElementById('user-search').value.trim().toLocaleLowerCase();
        const status = document.getElementById('status-filter').value.toLocaleLowerCase();
        return snapshot.users.filter(user => {
            const values = Object.values(user).map(value => String(value ?? '').toLocaleLowerCase());
            return (!search || values.some(value => value.includes(search)))
                && (!status || String(user.Status ?? '').toLocaleLowerCase() === status);
        });
    }

    function renderUsers() {
        const users = filteredUsers();
        const host = document.getElementById('users');
        document.getElementById('user-result-count').textContent =
            `${formatNumber(users.length)} shown · ${formatNumber(snapshot.users.length)} loaded`;
        document.getElementById('users-note').textContent = snapshot.usersTruncated
            ? 'Showing up to 500 accounts. Current passwords cannot be recovered; issue a one-time temporary password instead.'
            : 'Current passwords cannot be recovered; issue a one-time temporary password instead.';
        if (!users.length) {
            host.innerHTML = '<div class="empty">No accounts match the selected search and filters.</div>';
            return;
        }
        const fields = [
            ['Username', 'Username'],
            ['FirstName', 'First name'],
            ['LastName', 'Last name'],
            ['Department', 'Department'],
            ['WorkArea', 'Work area'],
            ['Status', 'Status'],
            ['DateCreated', 'Created'],
            ['LastLoginAt', 'Last login'],
            ['database', 'Database'],
            ['__actions', 'Actions']
        ];
        host.innerHTML = `<table class="users-table"><thead><tr>${fields.map(([, label]) => `<th>${escapeHtml(label)}</th>`).join('')}</tr></thead><tbody>${
            users.map(user => `<tr class="user-row">${fields.map(([field]) => {
                if (field === 'Status') {
                    const state = String(user.Status ?? 'unknown').toLocaleLowerCase();
                    return `<td><span class="badge ${state === 'active' || state === 'disabled' ? state : ''}">${escapeHtml(user.Status || 'Unknown')}</span></td>`;
                }
                if (field === 'Username') {
                    return `<td><span class="user-name">${escapeHtml(user.Username || '—')}</span></td>`;
                }
                if (field === 'DateCreated') return `<td>${escapeHtml(formatDate(user.DateCreated))}</td>`;
                if (field === 'LastLoginAt') return `<td>${escapeHtml(formatDate(user.LastLoginAt))}</td>`;
                if (field === '__actions') {
                    return `<td>${user.isPrimaryAccount === true && String(user.Status).toLowerCase() === 'active' && Number(user.UserID) > 0
                        ? `<button class="button" type="button" data-reset-user="${Number(user.UserID)}" data-username="${escapeHtml(user.Username)}">Reset password</button>`
                        : '—'}</td>`;
                }
                return `<td>${escapeHtml(user[field] ?? '—')}</td>`;
            }).join('')}</tr>`).join('')
        }</tbody></table>`;
        host.querySelectorAll('[data-reset-user]').forEach(button => {
            button.addEventListener('click', () => resetUserPassword(button));
        });
    }

    async function resetUserPassword(button) {
        button.disabled = true;
        try {
            const result = await request(`${API}?action=user_reset`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify({
                    userId: Number(button.dataset.resetUser),
                    username: button.dataset.username
                })
            });
            document.getElementById('temporary-password-user').textContent = result.username;
            document.getElementById('temporary-password-value').textContent = result.temporaryPassword;
            document.getElementById('temporary-password-panel').hidden = false;
            showMessage(result.message);
        } catch (error) {
            showMessage(error.message);
        } finally {
            button.disabled = false;
        }
    }

    function renderLogins(logins) {
        const host = document.getElementById('logins');
        document.getElementById('login-result-count').textContent = `${logins.length} recent events`;
        if (!logins.length) {
            host.innerHTML = '<div class="empty">No sign-in history is available.</div>';
            return;
        }
        host.innerHTML = `<table><thead><tr><th>Account</th><th>Result</th><th>Time</th><th>Device</th><th>Last activity</th></tr></thead><tbody>${
            logins.map(login => {
                const success = Number(login.Success) === 1;
                const current = Number(login.IsCurrentSession) === 1;
                const account = login.Username || 'Unmatched / unknown account';
                const device = [login.DeviceType, login.OperatingSystem, login.Browser].filter(Boolean).join(' · ') || '—';
                return `<tr class="login-row"><td>${escapeHtml(account)}</td><td><span class="badge ${success ? 'active' : 'disabled'}">${success ? 'Success' : 'Failed'}</span>${current ? ' <span class="badge current">Session</span>' : ''}</td><td>${escapeHtml(formatDate(login.LoginDateTime))}</td><td>${escapeHtml(device)}</td><td>${escapeHtml(formatDate(login.LastActivity))}</td></tr>`;
            }).join('')
        }</tbody></table>`;
    }

    function renderActivities(activities) {
        const host = document.getElementById('activities');
        document.getElementById('activity-result-count').textContent = `${activities.length} recent records`;
        if (!activities.length) {
            host.innerHTML = '<div class="empty">No recorded activity is available.</div>';
            return;
        }
        host.innerHTML = `<table><thead><tr><th>Action</th><th>Module</th><th>Account</th><th>Time</th></tr></thead><tbody>${
            activities.map(activity =>
                `<tr class="activity-row"><td><span class="activity-icon">↳</span> ${escapeHtml(activity.Action)}</td><td>${escapeHtml(activity.Module)}</td><td>${escapeHtml(activity.Username || 'System / unknown')}</td><td>${escapeHtml(formatDate(activity.DateTime))}</td></tr>`
            ).join('')
        }</tbody></table>`;
    }

    function renderAdminAudit(events) {
        const host = document.getElementById('admin-audit');
        document.getElementById('admin-audit-count').textContent = `${events.length} recent actions`;
        if (!events.length) {
            host.innerHTML = '<div class="empty">No admin archive actions recorded.</div>';
            return;
        }
        host.innerHTML = `<table><thead><tr><th>Administrator</th><th>Action</th><th>Item</th><th>Time</th></tr></thead><tbody>${
            events.map(event =>
                `<tr><td>${escapeHtml(event.AdminName)}</td><td>${escapeHtml(event.Action)}</td><td>${escapeHtml(event.Item)}</td><td>${escapeHtml(formatDate(event.CreatedAt))}</td></tr>`
            ).join('')
        }</tbody></table>`;
    }

    async function loadRecycleBin() {
        const result = await request(`${API}?action=recycle_bin`);
        const host = document.getElementById('recycle-items');
        const items = result.items || [];
        document.getElementById('recycle-summary').textContent = `${items.length} encrypted recovery archives`;
        if (!items.length) {
            host.innerHTML = '<div class="empty">The admin recycle bin is empty.</div>';
            return;
        }
        host.innerHTML = items.map(item => {
            const label = item.itemType === 'database'
                ? `Workspace ${item.databaseName.replace(/^assets_user_inventory_/i, '').replace(/_INVENTORY$/i, '').replace(/_/g, ' ')}`
                : `${item.databaseName}.${item.tableName}`;
            const actionButtons = item.archiveAvailable
                ? `<button class="button primary" type="button" data-restore-id="${item.id}">Restore</button><button class="button danger" type="button" data-purge-id="${item.id}">Permanently delete</button>`
                : '<span class="badge disabled">Archive missing</span>';
            return `<article class="recycle-card"><span class="db-symbol" aria-hidden="true">↶</span><div class="recycle-main"><strong>${escapeHtml(label)}</strong><small>${escapeHtml(item.itemType)} · archived ${escapeHtml(formatDate(item.archivedAt))} · ${formatSize(item.sizeBytes)}</small></div><div class="recycle-actions">${actionButtons}</div></article>`;
        }).join('');
        host.querySelectorAll('[data-restore-id]').forEach(button => {
            button.addEventListener('click', () => runRecycleAction({
                operation: 'restore',
                id: Number(button.dataset.restoreId)
            }, button));
        });
        host.querySelectorAll('[data-purge-id]').forEach(button => {
            button.addEventListener('click', () => runRecycleAction({
                operation: 'purge',
                id: Number(button.dataset.purgeId)
            }, button));
        });
    }

    async function loadRecoveryStatus() {
        const panel = document.getElementById('database-recovery');
        const status = document.getElementById('database-recovery-status');
        const list = document.getElementById('database-recovery-list');
        const restoreButton = document.getElementById('restore-missing-databases');
        panel.hidden = false;
        try {
            const result = await request(`${API}?action=recovery_status`);
            if (!result.available) {
                status.textContent = result.message || 'A valid full-system JSON backup is unavailable.';
                list.textContent = '';
                restoreButton.disabled = true;
                return;
            }
            const missing = result.missingDatabases || [];
            const unrecoverable = result.unrecoverableDatabases || [];
            const archived = result.intentionallyArchivedDatabases || [];
            const unpaired = result.unpairedDatabases || [];
            const sourceByDatabase = new Map(
                (result.recoverySources || []).map(source => [source.database, source])
            );
            const notes = [
                unrecoverable.length ? `Not in an available backup: ${unrecoverable.join(', ')}` : '',
                archived.length ? `Intentionally archived (not restored automatically): ${archived.join(', ')}` : '',
                unpaired.length ? `Workspace pair absent; may be a legacy shared-database account or an untracked removal: ${unpaired.join(', ')}` : ''
            ].filter(Boolean);
            if (missing.length) {
                status.textContent = 'A matching full-system or workspace backup is available for the missing database(s).';
                list.textContent = `Missing from MySQL: ${missing.map(name => {
                    const source = sourceByDatabase.get(name);
                    return source
                        ? `${name} (backup ${formatDate(source.created_at)})`
                        : name;
                }).join(', ')}${notes.length ? `. ${notes.join('. ')}` : ''}`;
                restoreButton.disabled = false;
            } else if (unrecoverable.length || archived.length || unpaired.length) {
                status.textContent = `Newest full-system backup: ${formatDate(result.backupCreatedAt)}.`;
                list.textContent = notes.join('. ');
                restoreButton.disabled = true;
            } else {
                status.textContent = `Newest full-system backup: ${formatDate(result.backupCreatedAt)}.`;
                list.textContent = 'No missing core or partial workspace databases need recovery. Existing databases are never overwritten.';
                restoreButton.disabled = true;
            }
        } catch (error) {
            status.textContent = error.message;
            list.textContent = 'Recovery status could not be verified.';
            restoreButton.disabled = true;
        }
    }

    function exportDatabases() {
        if (!snapshot) return;
        const columns = ['Database', 'Object', 'Type', 'Engine', 'Approx rows', 'Approx size bytes', 'Database last used', 'Last structural change'];
        const csvSafe = value => {
            const text = String(value ?? '');
            return `"${(/^[\t\r ]*[=+\-@]/.test(text) ? `'${text}` : text).replace(/"/g, '""')}"`;
        };
        const rows = snapshot.databases.flatMap(database => database.tables.length
            ? database.tables.map(table => [
                database.name, table.name, table.type, table.engine, table.rows, table.sizeBytes,
                database.lastUsedAt, table.lastChangedAt
            ])
            : [[database.name, '', '', '', '', '', database.lastUsedAt, '']]);
        const content = [columns, ...rows].map(row => row.map(csvSafe).join(',')).join('\r\n');
        const url = URL.createObjectURL(new Blob(['\uFEFF', content], { type: 'text/csv;charset=utf-8' }));
        const link = document.createElement('a');
        link.href = url;
        link.download = `assets-monitoring-database-report-${new Date().toISOString().slice(0, 10)}.csv`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    }

    function render(snapshotResult) {
        snapshot = snapshotResult;
        renderSystem(snapshot.system);
        renderStorage(snapshot.databases);
        renderDatabases();
        renderUsers();
        renderLogins(snapshot.recentLogins);
        renderActivities(snapshot.recentActivity);
        renderAdminAudit(snapshot.recentAdminEvents || []);
    }

    async function load() {
        if (busy) return;
        busy = true;
        refreshButton.disabled = true;
        refreshButton.textContent = '…';
        try {
            const result = await request(`${API}?action=overview`);
            render(result);
            await loadRecycleBin();
            showMessage('');
        } catch (error) {
            showMessage(error.message);
            const status = document.getElementById('connection-status');
            status.textContent = 'Connection issue';
            status.classList.add('offline');
        } finally {
            busy = false;
            refreshButton.disabled = false;
            refreshButton.textContent = '↻';
        }
    }

    function csvSafe(value) {
        let text = String(value ?? '');
        if (/^[\t\r ]*[=+\-@]/.test(text)) text = `'${text}`;
        return `"${text.replace(/"/g, '""')}"`;
    }

    function exportUsers() {
        const users = filteredUsers();
        if (!users.length) {
            showMessage('There are no user accounts in the current filter to export.');
            return;
        }
        const columns = [
            ['Username', 'Username'],
            ['FirstName', 'First name'],
            ['LastName', 'Last name'],
            ['Department', 'Department'],
            ['WorkArea', 'Work area'],
            ['Status', 'Status'],
            ['DateCreated', 'Created'],
            ['LastLoginAt', 'Last login'],
            ['database', 'Database']
        ];
        const csv = [
            columns.map(([, label]) => csvSafe(label)).join(','),
            ...users.map(user => columns.map(([field]) => csvSafe(user[field])).join(','))
        ].join('\r\n');
        const url = URL.createObjectURL(new Blob(['\uFEFF', csv], { type: 'text/csv;charset=utf-8' }));
        const link = document.createElement('a');
        link.href = url;
        link.download = `assets-monitoring-users-${new Date().toISOString().slice(0, 10)}.csv`;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
        showMessage('');
    }

    function toggleSidebar(forceOpen) {
        const expanded = typeof forceOpen === 'boolean'
            ? forceOpen
            : !document.body.classList.contains('sidebar-expanded');
        document.body.classList.toggle('sidebar-expanded', expanded);
        const button = document.getElementById('sidebar-toggle');
        button.setAttribute('aria-expanded', String(expanded));
        button.setAttribute('aria-label', expanded ? 'Collapse navigation' : 'Expand navigation');
        button.dataset.tooltip = expanded ? 'Collapse navigation (Ctrl+B)' : 'Expand navigation (Ctrl+B)';
        try {
            sessionStorage.setItem('assets-admin-sidebar-expanded', expanded ? '1' : '0');
        } catch {
            // Sidebar preference is optional when browser storage is unavailable.
        }
    }

    const pageViews = {
        overview: 'System overview',
        databases: 'Database explorer',
        users: 'User directory',
        activity: 'Recent activity',
        'recycle-bin': 'Admin recycle bin'
    };
    const requestedView = new URLSearchParams(location.search).get('view');
    const activeView = Object.hasOwn(pageViews, requestedView) ? requestedView : 'overview';
    document.querySelectorAll('[data-admin-page]').forEach(section => {
        section.hidden = section.dataset.adminPage !== activeView;
    });
    document.querySelectorAll('[data-view-link]').forEach(link => {
        if (link.dataset.viewLink === activeView) link.setAttribute('aria-current', 'page');
        else link.removeAttribute('aria-current');
    });
    document.title = `${pageViews[activeView]} · System Admin`;
    document.getElementById('page-title').textContent = pageViews[activeView];

    document.getElementById('sidebar-toggle').addEventListener('click', () => toggleSidebar());
    document.getElementById('sidebar-help').addEventListener('click', () => {
        showMessage('Eligible tenant tables and workspaces can be archived with one click and restored from the recycle bin. Permanently deleting an archive cannot be undone. Admin actions require an active signed-in session.');
        if (activeView !== 'overview') location.assign('dashboard.html?view=overview');
    });
    document.addEventListener('keydown', event => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLocaleLowerCase() === 'b'
            && !event.target.closest('input, textarea, select, [contenteditable="true"]')) {
            event.preventDefault();
            toggleSidebar();
        }
    });
    try {
        toggleSidebar(sessionStorage.getItem('assets-admin-sidebar-expanded') === '1');
    } catch {
        toggleSidebar(false);
    }

    document.getElementById('database-search').addEventListener('input', renderDatabases);
    document.getElementById('user-search').addEventListener('input', renderUsers);
    document.getElementById('status-filter').addEventListener('change', renderUsers);
    document.getElementById('export-users').addEventListener('click', exportUsers);
    document.getElementById('export-databases').addEventListener('click', exportDatabases);
    document.getElementById('refresh-recycle').addEventListener('click', async () => {
        try {
            await loadRecycleBin();
        } catch (error) {
            showMessage(error.message);
        }
    });
    document.getElementById('copy-temporary-password').addEventListener('click', async () => {
        const password = document.getElementById('temporary-password-value').textContent;
        try {
            await navigator.clipboard.writeText(password);
            showMessage('Temporary password copied to the clipboard.');
        } catch (error) {
            showMessage('Clipboard access is unavailable. Select and copy the temporary password manually.');
        }
    });
    document.getElementById('dismiss-temporary-password').addEventListener('click', () => {
        document.getElementById('temporary-password-value').textContent = '';
        document.getElementById('temporary-password-user').textContent = '';
        document.getElementById('temporary-password-panel').hidden = true;
    });
    document.getElementById('refresh').addEventListener('click', load);
    document.getElementById('restore-missing-databases').addEventListener('click', async event => {
        const button = event.currentTarget;
        if (button.disabled || !await window.appDialogs.confirm(
            'Restore every missing application database from the newest full-system backup? Existing databases will not be changed.',
            { title: 'Restore missing databases', confirmText: 'Restore databases', danger: true }
        )) return;
        button.disabled = true;
        try {
            const result = await request(`${API}?action=recover_missing_databases`, {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrf }
            });
            showMessage(result.message || 'Database recovery completed.');
            await loadRecoveryStatus();
            await load();
        } catch (error) {
            showMessage(error.message);
            await loadRecoveryStatus();
        }
    });
    document.getElementById('expand-all').addEventListener('click', () => {
        document.querySelectorAll('.db-card:not([hidden])').forEach(card => {
            card.classList.add('expanded');
            card.querySelector('.db-summary').setAttribute('aria-expanded', 'true');
        });
    });
    document.getElementById('collapse-all').addEventListener('click', () => {
        document.querySelectorAll('.db-card.expanded').forEach(card => {
            card.classList.remove('expanded');
            card.querySelector('.db-summary').setAttribute('aria-expanded', 'false');
        });
    });
    document.getElementById('logout').addEventListener('click', async () => {
        try {
            await request(`${API}?action=logout`, {
                method: 'POST',
                headers: { 'X-CSRF-Token': csrf }
            });
            location.replace('../login.html');
        } catch (error) {
            showMessage(error.message);
        }
    });

    request(`${API}?action=csrf`)
        .then(async result => {
            csrf = result.csrf;
            await loadRecoveryStatus();
            return load();
        })
        .catch(error => {
            showMessage(error.message);
            const status = document.getElementById('connection-status');
            status.textContent = 'Connection issue';
            status.classList.add('offline');
        });
})();
