# Admin dashboard setup

1. Open PowerShell in the project folder:

   ```text
   C:\xampp\htdocs\assets-monitoring
   ```

2. Run the setup helper:

   ```powershell
   .\page\admin\setup-admin.ps1
   ```

3. Choose and confirm a private admin password when prompted. The password is entered without being displayed. The script saves only its password hash in `page\.htaccess`; Apache applies it automatically, so no Apache configuration edits or restart are needed.

Then sign in at `page/login.html` with username `kradmin` and the password you chose. The helper will stop rather than overwrite an existing `page\.htaccess`.

The admin sidebar opens separate Overview, Databases, Users, Activity, and Recycle Bin pages. The dashboard includes database/table search, a storage analyzer, last successful login details, workspace-usage timestamps, and CSV reports. Database archive controls are limited to tenant workspaces; eligible tenant tables can be archived individually. Core authentication/audit tables, core databases, views, and tables with dependent views or triggers are protected.

Workspace databases use the account's normalized first and last name, for example `YURI_LAYGO_INVENTORY` and `YURI_LAYGO_USER_CONTROL`. If names would collide, the later workspace gets a numeric suffix. Existing numeric tenant databases are copied to the named schemas and validated before the legacy schemas are removed when that workspace is opened or an admin workspace operation runs. A full-system JSON backup is created before a migration.

All user workspace URLs serve the shared application files; the per-user folders hold workspace backups, not separate UI copies. The signed-in application checks the shared UI version every minute and when a browser tab becomes active, then reloads changed UI while preserving the selected section. Users with a tab that was already open before the update checker was deployed need one refresh or sign-in to load the checker; after that, updates are automatic. Keep the shared `Tracking` and `page` application files deployed together; there is no per-user UI deployment step.

Archive, restore, and purge actions are one click while signed in; the admin API still requires the authenticated admin session and CSRF token. Eligible workspace/table removals first create a compressed AES-GCM encrypted recovery file outside the web root. Restoring an archive returns it to its original location. Permanently deleting an archive cannot be undone. Admin actions are recorded in the audit list. The admin password is retained only in the server-side PHP session for archive encryption/decryption; it is not sent again by the browser and the session expires after 20 minutes of inactivity.

The Overview page checks the newest valid full-system JSON backup and offers an explicit **Restore missing databases** action. It restores only missing core databases and incomplete tenant pairs, prefers a newer matching per-workspace backup, and never overwrites a database that already exists. Tenant pairs where both databases are absent are not automatically recreated because they may have been intentionally archived or may belong to a legacy account using shared tables. The status panel identifies known archived workspaces. Existing-table creation in workspace navigation settings offers **Use existing**, **Archive existing and create new**, or **Cancel**. Recycle-bin archiving is limited to tables that can be safely captured, and archived data is retained for 30 days.

JSON restore points include the database schema definitions, table rows, and scheduled events. They do not include views, triggers, stored routines, or arbitrary files outside the database backup format; backups from before these objects were added cannot recreate them. Keep separate file-level backups for uploaded or workspace files.

Passwords are stored as one-way hashes and cannot be displayed or recovered, including by an administrator. On the Users page, **Reset password** issues a random temporary password, updates the account credentials, and requires the user to choose a new password at their next sign-in. The temporary password is shown once in the page and is not stored in the audit log.

Storage sizes and InnoDB row totals are estimates supplied by MySQL metadata. Workspace last-used time is derived from successful session activity and recorded application actions; non-workspace databases and individual tables may not have reliable usage timestamps. Table create/update timestamps are shown only as structural-change information, not as last-used time. Only schemas visible to the configured MySQL account can be listed. Password hashes, account recovery data, network addresses, and sensitive activity details are not displayed.
