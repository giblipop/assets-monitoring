# Employee asset lookup and issuance

From the signed-in application shell, press **Ctrl+F** to open employee asset
lookup. The search uses employee names already present in the signed-in
account's inventory; a new employee can also be entered manually. Selecting a
name displays matching asset records across the account's visible inventory
modules.

Choose **Issue selected assets** to pick one or more visible modules and search
for available assets by asset code, reference number, serial number, or model.
After selecting an asset, its form displays the columns that are blank for
that specific record in that module. Required empty fields are marked and must
be completed before confirmation. Text, numeric, date, and enum fields use
inputs appropriate to their database types; employee name, status, and issue
date are managed by the issuance action rather than duplicated in the form.
The issue action accepts modules with an `id` or `asset_code` identifier, a
text status column that can store `Issued`, and an assignee column named
`name`, `assigned_to`, `issued_to`, or `employee_name`.

Only assets whose status indicates stock or availability can be issued.
Issuance sets the assignee field and status to `Issued` and fills the issue-date
field when the module has one. Values submitted for dynamic form fields are
accepted only when the corresponding database column is still blank for that
asset. All selected updates run in one database transaction; if any selected
asset is no longer available, a required value is missing, or a submitted
column is no longer blank, the transaction is rolled back. A successful issue
is recorded in activity history.

The lookup and issuance API always uses the authenticated account's selected
inventory and navigation databases. It does not search the shared account
directory or another account's records. Issuance requests require the active
session and its CSRF token.
