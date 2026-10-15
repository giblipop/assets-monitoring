<?php
function inventory_is_system_table(string $table): bool
{
    static $systemTables = [
        'users',
        'loginhistory',
        'activitylogs',
        'remembereddevices',
        'accountlockouts',
        'passwordresetrequests',
        'system_recycle_bin',
        'navigation_trash',
    ];

    return in_array(strtolower($table), $systemTables, true);
}
