<?php
declare(strict_types=1);
require_once __DIR__ . '/../Tracking/connector/config.php';
require_once __DIR__ . '/../Tracking/connector/database_backup.php';
require_once __DIR__ . '/tenant_provisioner.php';

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$https,'httponly'=>true,'samesite'=>'Lax']);
session_start();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    register_shutdown_function('asset_backup_after_write');
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

function respond(array $body, int $status=200): void { http_response_code($status); echo json_encode($body, JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function csrf_check(): void { $token=$_SERVER['HTTP_X_CSRF_TOKEN']??''; if (!$token || !hash_equals($_SESSION['csrf']??'', $token)) respond(['success'=>false,'message'=>'Your session expired. Refresh and try again.'], 403); }
function body(): array { $d=json_decode(file_get_contents('php://input'),true); return is_array($d)?$d:[]; }
function client_ip(): string { $ip=$_SERVER['REMOTE_ADDR']??''; return filter_var($ip,FILTER_VALIDATE_IP)?$ip:''; }
function valid_new_password(string $password): bool { return strlen($password) >= 4 && strlen($password) <= 72; }
function generate_account_id(PDO $pdo): string {
    do {
        $accountId = (string)random_int(100000000, 999999999);
        $statement = $pdo->prepare('SELECT 1 FROM Users WHERE AccountID = ?');
        $statement->execute([$accountId]);
    } while ($statement->fetchColumn());
    return $accountId;
}
function generate_temporary_password(): string {
    $groups = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!@#$%*-_'];
    $password = [];
    foreach ($groups as $group) {
        $password[] = $group[random_int(0, strlen($group) - 1)];
    }
    $all = implode('', $groups);
    while (count($password) < 24) {
        $password[] = $all[random_int(0, strlen($all) - 1)];
    }
    for ($i = count($password) - 1; $i > 0; $i--) {
        $swap = random_int(0, $i);
        [$password[$i], $password[$swap]] = [$password[$swap], $password[$i]];
    }
    return implode('', $password);
}
function device_info(): array {
    $ua=$_SERVER['HTTP_USER_AGENT']??'';
    $type=preg_match('/iPad|Tablet/i',$ua)?'Tablet':(preg_match('/Mobile|Android|iPhone/i',$ua)?'Mobile':'Desktop');
    $os='Unknown'; foreach(['Windows'=>'Windows','Android'=>'Android','iPhone|iPad|iPod'=>'iOS','Mac OS X'=>'macOS','Linux'=>'Linux'] as $k=>$v) if(preg_match('/'.$k.'/i',$ua)){$os=$v;break;}
    $browser='Unknown'; foreach(['/Edg\//'=>'Edge','/Firefox\//'=>'Firefox','/Chrome\//'=>'Chrome','/CriOS\//'=>'Chrome iOS','/Safari\//'=>'Safari'] as $p=>$v) if(preg_match($p,$ua)){$browser=$v;break;}
    return ['name'=>substr($ua,0,250)?:'Unknown device','type'=>$type,'os'=>$os,'browser'=>$browser];
}
function db(): PDO {
    global $DB_HOST,$DB_USER,$DB_PASS,$DB_INVENTORY;
    static $pdo=null; if($pdo) return $pdo;
    $pdo=new PDO("mysql:host={$DB_HOST};dbname={$DB_INVENTORY};charset=utf8mb4",$DB_USER,$DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->exec("CREATE TABLE IF NOT EXISTS Users (UserID BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, EmployeeID VARCHAR(80) NOT NULL UNIQUE, FirstName VARCHAR(100) NOT NULL, LastName VARCHAR(100) NOT NULL, WorkspaceDatabaseStem VARCHAR(51) NOT NULL DEFAULT '', Department VARCHAR(150) NOT NULL, WorkArea VARCHAR(150) NOT NULL, Username VARCHAR(80) NOT NULL UNIQUE, PasswordHash VARCHAR(255) NOT NULL, DateCreated DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, Status ENUM('active','disabled') NOT NULL DEFAULT 'active') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!$pdo->query("SHOW COLUMNS FROM Users LIKE 'AccountID'")->fetch()) {
        $pdo->exec('ALTER TABLE Users ADD COLUMN AccountID CHAR(9) NULL AFTER EmployeeID');
    }
    if (!$pdo->query("SHOW COLUMNS FROM Users LIKE 'MustChangePassword'")->fetch()) {
        $pdo->exec('ALTER TABLE Users ADD COLUMN MustChangePassword TINYINT(1) NOT NULL DEFAULT 0 AFTER PasswordHash');
    }
    if (!$pdo->query("SHOW COLUMNS FROM Users LIKE 'LanguagePreference'")->fetch()) {
        $pdo->exec("ALTER TABLE Users ADD COLUMN LanguagePreference ENUM('en','ko') NOT NULL DEFAULT 'en' AFTER WorkArea");
    }
    $themeColumn=$pdo->query("SHOW COLUMNS FROM Users LIKE 'ThemePreference'")->fetch();
    if (!$themeColumn) {
        $pdo->exec("ALTER TABLE Users ADD COLUMN ThemePreference ENUM('system','light','dark') NOT NULL DEFAULT 'light' AFTER LanguagePreference");
    } elseif (($themeColumn['Default'] ?? null) !== 'light') {
        $pdo->exec("UPDATE Users SET ThemePreference='light' WHERE ThemePreference='system'");
        $pdo->exec("ALTER TABLE Users MODIFY ThemePreference ENUM('system','light','dark') NOT NULL DEFAULT 'light'");
    }
    if (!$pdo->query("SHOW COLUMNS FROM Users LIKE 'WorkspaceDatabaseStem'")->fetch()) {
        $pdo->exec("ALTER TABLE Users ADD COLUMN WorkspaceDatabaseStem VARCHAR(51) NOT NULL DEFAULT '' AFTER LastName");
    }
    $usersWithoutDatabaseStem = $pdo->query(
        "SELECT UserID, FirstName, LastName FROM Users WHERE WorkspaceDatabaseStem = '' ORDER BY UserID"
    )->fetchAll(PDO::FETCH_ASSOC);
    $saveDatabaseStem = $pdo->prepare('UPDATE Users SET WorkspaceDatabaseStem = ? WHERE UserID = ?');
    foreach ($usersWithoutDatabaseStem as $user) {
        $ownerId = (int)$user['UserID'];
        $stem = asset_backup_allocate_workspace_stem(
            $pdo,
            (string)$user['FirstName'],
            (string)$user['LastName'],
            $ownerId
        );
        $saveDatabaseStem->execute([$stem, $ownerId]);
    }
    $missingAccountIds = $pdo->query("SELECT UserID FROM Users WHERE AccountID IS NULL OR AccountID NOT REGEXP '^[0-9]{9}$'")->fetchAll(PDO::FETCH_COLUMN);
    $assignAccountId = $pdo->prepare('UPDATE Users SET AccountID = ? WHERE UserID = ?');
    foreach ($missingAccountIds as $userId) {
        $accountId = generate_account_id($pdo);
        $assignAccountId->execute([$accountId, $userId]);
        $tenantDatabase = asset_backup_existing_workspace_database_names($pdo, (int)$userId)['inventory'];
        $tenantExists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $tenantExists->execute([$tenantDatabase]);
        if ((int)$tenantExists->fetchColumn() === 1) {
            $quotedTenant = '`' . str_replace('`', '``', $tenantDatabase) . '`';
            $tenantUsersTable = $pdo->query("SHOW TABLES FROM {$quotedTenant} LIKE 'Users'")->fetchColumn();
            if ($tenantUsersTable) {
                $tenantHasAccountId = $pdo->query("SHOW COLUMNS FROM {$quotedTenant}.Users LIKE 'AccountID'")->fetch();
            } else {
                $tenantHasAccountId = false;
            }
            if ($tenantHasAccountId) {
                $tenantUser = $pdo->prepare("UPDATE {$quotedTenant}.Users SET AccountID = ? WHERE UserID = ?");
                $tenantUser->execute([$accountId, $userId]);
            }
        }
    }
    $accountIdColumn = $pdo->query("SHOW COLUMNS FROM Users LIKE 'AccountID'")->fetch();
    if (strtolower((string)($accountIdColumn['Type'] ?? '')) !== 'char(9)') {
        $pdo->exec('ALTER TABLE Users MODIFY AccountID CHAR(9) NULL');
    }
    if (!$pdo->query("SHOW INDEX FROM Users WHERE Key_name = 'uq_users_account_id'")->fetch()) {
        $pdo->exec('CREATE UNIQUE INDEX uq_users_account_id ON Users(AccountID)');
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS LoginHistory (LoginID BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, UserID BIGINT UNSIGNED NULL, LoginDateTime DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, LastActivity DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, LogoutDateTime DATETIME NULL, DeviceName VARCHAR(255) NOT NULL, DeviceType VARCHAR(32) NOT NULL, OperatingSystem VARCHAR(80) NOT NULL, Browser VARCHAR(80) NOT NULL, IPAddress VARCHAR(45) NOT NULL, Country VARCHAR(100) NULL, Region VARCHAR(100) NULL, City VARCHAR(100) NULL, Latitude DECIMAL(10,7) NULL, Longitude DECIMAL(10,7) NULL, IsCurrentSession TINYINT(1) NOT NULL DEFAULT 1, Success TINYINT(1) NOT NULL DEFAULT 0, AttemptedUsername VARCHAR(80) NULL, INDEX idx_logins_user_date(UserID,LoginDateTime), INDEX idx_login_attempt(IPAddress,LoginDateTime)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $lastActivityColumn=$pdo->query("SHOW COLUMNS FROM LoginHistory LIKE 'LastActivity'")->fetch();if(!$lastActivityColumn)$pdo->exec('ALTER TABLE LoginHistory ADD COLUMN LastActivity DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER LoginDateTime');
    $pdo->exec("CREATE TABLE IF NOT EXISTS ActivityLogs (LogID BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, UserID BIGINT UNSIGNED NULL, Action VARCHAR(100) NOT NULL, Module VARCHAR(100) NOT NULL, DateTime DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, DeviceName VARCHAR(255) NULL, IPAddress VARCHAR(45) NULL, Details JSON NULL, INDEX idx_activity_user_date(UserID,DateTime)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS RememberedDevices (DeviceID BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, UserID BIGINT UNSIGNED NOT NULL, TokenHash CHAR(64) NOT NULL UNIQUE, ExpiresAt DATETIME NOT NULL, CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, INDEX idx_device_user(UserID)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS AccountLockouts (Username VARCHAR(80) NOT NULL PRIMARY KEY, FailedAttempts TINYINT UNSIGNED NOT NULL DEFAULT 0, LockedUntil DATETIME NULL, UpdatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS PasswordResetRequests (RequestID BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, Username VARCHAR(80) NOT NULL, RequestedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, Status ENUM('pending','resolved') NOT NULL DEFAULT 'pending', INDEX idx_reset_status(Status,RequestedAt)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS AccountRecoveryAttempts (
        AttemptKey CHAR(64) NOT NULL PRIMARY KEY,
        FailedAttempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        LockedUntil DATETIME NULL,
        UpdatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS WorkspaceMembers (
        OwnerUserID BIGINT UNSIGNED NOT NULL,
        MemberUserID BIGINT UNSIGNED NOT NULL,
        Role ENUM('viewer','editor') NOT NULL DEFAULT 'viewer',
        Status ENUM('active','removed') NOT NULL DEFAULT 'active',
        JoinedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UpdatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (OwnerUserID, MemberUserID),
        INDEX idx_workspace_member (MemberUserID, Status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS WorkspaceInvitations (
        InvitationID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        OwnerUserID BIGINT UNSIGNED NOT NULL,
        CodeHash CHAR(64) NOT NULL UNIQUE,
        ExpiresAt DATETIME NOT NULL,
        UsedBy BIGINT UNSIGNED NULL,
        UsedAt DATETIME NULL,
        RevokedAt DATETIME NULL,
        CreatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_workspace_invitation_owner (OwnerUserID, ExpiresAt)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS WorkspacePreferences (
        UserID BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        OwnerUserID BIGINT UNSIGNED NOT NULL,
        UpdatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS WorkspaceInvitationAttempts (
        UserID BIGINT UNSIGNED NOT NULL PRIMARY KEY,
        FailedAttempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        LockedUntil DATETIME NULL,
        UpdatedAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    return $pdo;
}
function audit(PDO $pdo, ?int $uid, string $action, string $module, array $details=[]): void { $d=device_info(); $s=$pdo->prepare('INSERT INTO ActivityLogs(UserID,Action,Module,DeviceName,IPAddress,Details) VALUES(?,?,?,?,?,?)'); $s->execute([$uid,$action,$module,$d['name'],client_ip(),$details?json_encode($details):null]); }
function session_user(PDO $pdo): array {
    if(empty($_SESSION['uid'])) respond(['success'=>false,'message'=>'Please sign in again.'],401);
    $idle=(int)($_SESSION['last_activity']??time()); if(time()-$idle>1200){if(($_SESSION['login_db']??'')===$GLOBALS['DB_INVENTORY']){$s=$pdo->prepare('UPDATE LoginHistory SET LogoutDateTime=NOW(),IsCurrentSession=0 WHERE LoginID=?');$s->execute([$_SESSION['login_id']??0]);}audit($pdo,(int)$_SESSION['uid'],'session expired','authentication');session_unset();session_destroy();respond(['success'=>false,'message'=>'Session expired. Please sign in again.'],401);}
    $_SESSION['last_activity']=time();
    if(!empty($_SESSION['login_id'])&&($_SESSION['login_db']??'')===$GLOBALS['DB_INVENTORY']){$touch=$pdo->prepare('UPDATE LoginHistory SET LastActivity=NOW() WHERE LoginID=? AND IsCurrentSession=1');$touch->execute([$_SESSION['login_id']]);}
    $s=$pdo->prepare('SELECT UserID,AccountID,FirstName,LastName,Department,WorkArea,LanguagePreference,ThemePreference,Username,MustChangePassword FROM Users WHERE UserID=? AND Status="active"');$s->execute([$_SESSION['uid']]);$u=$s->fetch();if(!$u)respond(['success'=>false,'message'=>'Account unavailable.'],401);return $u;
}

try {
 $pdo=db(); $method=$_SERVER['REQUEST_METHOD']; $action=$_GET['action']??'';
 if(!empty($_SESSION['uid'])&&$action!=='login'){
   $forceCheck=$pdo->prepare('SELECT MustChangePassword FROM Users WHERE UserID=? AND Status="active"');$forceCheck->execute([$_SESSION['uid']]);$mustChange=(int)$forceCheck->fetchColumn()===1;
   if($mustChange&&!in_array($action,['csrf','me','profile','language_update','theme_update','password_change','password_recover','logout'],true)){
     respond(['success'=>false,'message'=>'Change your temporary password before continuing.'],403);
   }
 }
 if($method==='GET' && $action==='csrf'){ if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32)); respond(['success'=>true,'csrf'=>$_SESSION['csrf']]); }
 if($method==='GET' && $action==='me'){$u=session_user($pdo);respond(['success'=>true,'user'=>$u,'workspaceRole'=>$_SESSION['workspace_role']??'owner','workspaceOwnerId'=>(int)($_SESSION['workspace_owner_id']??$u['UserID'])]);}
 if($method==='GET' && $action==='profile'){$u=session_user($pdo);respond(['success'=>true,'user'=>$u]);}
 if($method==='GET' && $action==='workspaces'){
   $u=session_user($pdo);$workspaces=[['ownerId'=>(int)$u['UserID'],'name'=>$u['FirstName'].' '.$u['LastName'].' (My workspace)','role'=>'owner']];
   $s=$pdo->prepare('SELECT owner.UserID AS ownerId, owner.FirstName, owner.LastName, wm.Role FROM WorkspaceMembers wm JOIN Users owner ON owner.UserID=wm.OwnerUserID AND owner.Status="active" WHERE wm.MemberUserID=? AND wm.Status="active" ORDER BY owner.FirstName, owner.LastName');
   $s->execute([$u['UserID']]);
   foreach($s->fetchAll() as $workspace){$workspaces[]=['ownerId'=>(int)$workspace['ownerId'],'name'=>$workspace['FirstName'].' '.$workspace['LastName'].' workspace','role'=>$workspace['Role']];}
   respond(['success'=>true,'workspaces'=>$workspaces,'activeOwnerId'=>(int)($_SESSION['workspace_owner_id']??$u['UserID'])]);
 }
 if($method==='GET' && $action==='team_members'){
   $u=session_user($pdo);$s=$pdo->prepare('SELECT wm.MemberUserID AS userId, u.FirstName, u.LastName, u.Username, wm.Role, wm.JoinedAt FROM WorkspaceMembers wm JOIN Users u ON u.UserID=wm.MemberUserID WHERE wm.OwnerUserID=? AND wm.Status="active" ORDER BY u.FirstName,u.LastName');
   $s->execute([$u['UserID']]);respond(['success'=>true,'members'=>$s->fetchAll()]);
 }
 if($method==='GET' && $action==='history'){$u=session_user($pdo);$s=$pdo->prepare('SELECT LoginDateTime,LogoutDateTime,DeviceName,DeviceType,OperatingSystem,Browser,IPAddress,Country,Region,City,Success FROM LoginHistory WHERE UserID=? ORDER BY LoginID DESC LIMIT 100');$s->execute([$u['UserID']]);respond(['success'=>true,'history'=>$s->fetchAll()]);}
 if($method==='GET' && $action==='options'){
   $departments=[];$areas=[];
   foreach($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table){ if(!preg_match('/^[a-zA-Z0-9_]+$/',$table))continue; $cols=$pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);$map=array_change_key_case(array_flip($cols),CASE_LOWER);$dc=$map['department']??null;$ac=$map['work_area']??$map['workarea']??null;if(!$dc&&!$ac)continue;
      try { if($dc){foreach($pdo->query("SELECT DISTINCT `$cols[$dc]` FROM `$table` WHERE `$cols[$dc]` IS NOT NULL AND TRIM(`$cols[$dc]`)<>'' LIMIT 300")->fetchAll(PDO::FETCH_COLUMN) as $v)$departments[]=$v;} if($ac){foreach($pdo->query("SELECT DISTINCT `$cols[$ac]` FROM `$table` WHERE `$cols[$ac]` IS NOT NULL AND TRIM(`$cols[$ac]`)<>'' LIMIT 300")->fetchAll(PDO::FETCH_COLUMN) as $v)$areas[]=$v;} }catch(Throwable $e){}
   }
   respond(['success'=>true,'departments'=>array_values(array_unique($departments)),'workAreas'=>array_values(array_unique($areas))]);
 }
 if($method!=='POST')respond(['success'=>false,'message'=>'Unsupported request.'],405);
 csrf_check(); $data=body();
 if($action==='profile_update'){
   $u=session_user($pdo);$first=trim((string)($data['firstName']??''));$last=trim((string)($data['lastName']??''));$dept=trim((string)($data['department']??''));$area=trim((string)($data['workArea']??''));
   if(!$first||!$last||!$dept||!$area||strlen($first)>100||strlen($last)>100||strlen($dept)>150||strlen($area)>150)respond(['success'=>false,'message'=>'Complete all profile fields within their allowed lengths.'],422);
   $s=$pdo->prepare('UPDATE Users SET FirstName=?,LastName=?,Department=?,WorkArea=? WHERE UserID=?');$s->execute([$first,$last,$dept,$area,$u['UserID']]);
   audit($pdo,(int)$u['UserID'],'profile updated','account');respond(['success'=>true,'message'=>'Profile updated.']);
 }
 if($action==='language_update'){
   $u=session_user($pdo);$language=(string)($data['language']??'');
   if(!in_array($language,['en','ko'],true))respond(['success'=>false,'message'=>'Choose English or Korean.'],422);
   $s=$pdo->prepare('UPDATE Users SET LanguagePreference=? WHERE UserID=?');$s->execute([$language,$u['UserID']]);
   audit($pdo,(int)$u['UserID'],'language preference updated','account',['language'=>$language]);
   respond(['success'=>true,'message'=>'Language preference saved.','language'=>$language]);
 }
 if($action==='theme_update'){
   $u=session_user($pdo);$theme=(string)($data['theme']??'');
   if(!in_array($theme,['system','light','dark'],true))respond(['success'=>false,'message'=>'Choose System, Light, or Dark appearance.'],422);
   $s=$pdo->prepare('UPDATE Users SET ThemePreference=? WHERE UserID=?');$s->execute([$theme,$u['UserID']]);
   audit($pdo,(int)$u['UserID'],'appearance preference updated','account',['theme'=>$theme]);
   respond(['success'=>true,'message'=>'Appearance preference saved.','theme'=>$theme]);
 }
 if($action==='password_change'){
   $u=session_user($pdo);$username=trim((string)($data['username']??''));$accountId=strtoupper(trim((string)($data['accountId']??'')));$oldPassword=(string)($data['oldPassword']??'');$newPassword=(string)($data['newPassword']??'');$confirmPassword=(string)($data['confirmPassword']??'');
   if($username===''||$accountId===''||$oldPassword===''||$newPassword===''||$confirmPassword==='')respond(['success'=>false,'message'=>'Complete every password field.'],422);
   if(strlen($username)>80||!hash_equals((string)$u['Username'],$username))respond(['success'=>false,'message'=>'The username does not match your signed-in account.'],422);
   if(!preg_match('/^[0-9]{9}$/',$accountId)||!hash_equals((string)$u['AccountID'],$accountId))respond(['success'=>false,'message'=>'The Account ID does not match your signed-in account.'],422);
   if(!valid_new_password($newPassword))respond(['success'=>false,'message'=>'Use 4 to 72 characters. Special characters, uppercase letters, and numbers are optional.'],422);
   if(!hash_equals($newPassword,$confirmPassword))respond(['success'=>false,'message'=>'The new passwords do not match.'],422);
   $tenantDatabases=asset_backup_existing_workspace_database_names($pdo,(int)$u['UserID']);
   $tenantDatabase=$tenantDatabases['inventory'];
   $tenantControlDatabase=$tenantDatabases['control'];
   $pdo->beginTransaction();
   try {
     $s=$pdo->prepare('SELECT PasswordHash FROM Users WHERE UserID=? AND Status="active" FOR UPDATE');$s->execute([$u['UserID']]);$currentHash=$s->fetchColumn();
     if(!$currentHash||!password_verify($oldPassword,(string)$currentHash)){ $pdo->rollBack();respond(['success'=>false,'message'=>'The current password is incorrect.'],422); }
     if(password_verify($newPassword,(string)$currentHash)){ $pdo->rollBack();respond(['success'=>false,'message'=>'Choose a new password that is different from your current password.'],422); }
     $tenantCheck=$pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');$tenantCheck->execute([$tenantDatabase]);$tenantExists=(int)$tenantCheck->fetchColumn();
     $tenantPair=$pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME IN (?,?)');$tenantPair->execute(array_values($tenantDatabases));$pairCount=(int)$tenantPair->fetchColumn();
     if($pairCount===1){$pdo->rollBack();respond(['success'=>false,'message'=>'Your account workspace is incomplete. Contact the administrator before changing your password.'],503);}
     $newHash=password_hash($newPassword,PASSWORD_DEFAULT);
     if($tenantExists===1){
       $quotedTenant='`'.str_replace('`','``',$tenantDatabase).'`';
       $tenantUser=$pdo->prepare("UPDATE {$quotedTenant}.Users SET PasswordHash=? WHERE UserID=?");
       $tenantUser->execute([$newHash,$u['UserID']]);
       if($tenantUser->rowCount()!==1){$pdo->rollBack();respond(['success'=>false,'message'=>'The account workspace user record could not be updated. Contact the administrator.'],503);}
     }
     $s=$pdo->prepare('UPDATE Users SET PasswordHash=?,MustChangePassword=0 WHERE UserID=?');$s->execute([$newHash,$u['UserID']]);
     $pdo->commit();
   } catch(Throwable $error) {if($pdo->inTransaction())$pdo->rollBack();throw $error;}
   unset($_SESSION['must_change_password']);audit($pdo,(int)$u['UserID'],'password changed','authentication');respond(['success'=>true,'message'=>'Your password has been changed.']);
 }
 if($action==='invite_create'){
   $u=session_user($pdo);$ownerDatabases=asset_backup_existing_workspace_database_names($pdo,(int)$u['UserID']);$databaseCheck=$pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME IN (?,?)');$databaseCheck->execute(array_values($ownerDatabases));if((int)$databaseCheck->fetchColumn()!==2)respond(['success'=>false,'message'=>'Your workspace is not fully provisioned and cannot be shared yet.'],503);$pdo->beginTransaction();
   try {
     $pdo->prepare('UPDATE WorkspaceInvitations SET RevokedAt=NOW() WHERE OwnerUserID=? AND UsedAt IS NULL AND RevokedAt IS NULL')->execute([$u['UserID']]);
     $alphabet='abcdefghijklmnopqrstuvwxyz0123456789';
     do {$code='';for($i=0;$i<8;$i++)$code.=$alphabet[random_int(0,strlen($alphabet)-1)];$hash=hash('sha256',$code);$check=$pdo->prepare('SELECT 1 FROM WorkspaceInvitations WHERE CodeHash=?');$check->execute([$hash]);} while($check->fetchColumn());
     $s=$pdo->prepare('INSERT INTO WorkspaceInvitations(OwnerUserID,CodeHash,ExpiresAt) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 7 DAY))');$s->execute([$u['UserID'],$hash]);
     $pdo->commit();
   } catch(Throwable $error) {if($pdo->inTransaction())$pdo->rollBack();throw $error;}
   audit($pdo,(int)$u['UserID'],'workspace invitation created','workspace');respond(['success'=>true,'code'=>$code,'expiresInDays'=>7]);
 }
 if($action==='invite_join'){
   $u=session_user($pdo);$code=strtolower(trim((string)($data['code']??'')));
   if(!preg_match('/^[a-z0-9]{8}$/',$code))respond(['success'=>false,'message'=>'Enter a valid 8-character invitation code.'],422);
   $attempts=$pdo->prepare('SELECT FailedAttempts,LockedUntil FROM WorkspaceInvitationAttempts WHERE UserID=?');$attempts->execute([$u['UserID']]);$attemptState=$attempts->fetch();
   if($attemptState&&$attemptState['LockedUntil']&&strtotime((string)$attemptState['LockedUntil'])>time())respond(['success'=>false,'message'=>'Too many incorrect invitation codes. Try again in 15 minutes.'],429);
   $pdo->beginTransaction();
   try {
     $s=$pdo->prepare('SELECT InvitationID,OwnerUserID FROM WorkspaceInvitations WHERE CodeHash=? AND UsedAt IS NULL AND RevokedAt IS NULL AND ExpiresAt>NOW() FOR UPDATE');$s->execute([hash('sha256',$code)]);$invitation=$s->fetch();
     if(!$invitation) {
       $pdo->rollBack();
       $pdo->prepare('INSERT INTO WorkspaceInvitationAttempts(UserID,FailedAttempts,LockedUntil) VALUES(?,1,NULL) ON DUPLICATE KEY UPDATE FailedAttempts=IF(LockedUntil IS NOT NULL AND LockedUntil<=NOW(),1,FailedAttempts+1),LockedUntil=IF(FailedAttempts>=4,DATE_ADD(NOW(),INTERVAL 15 MINUTE),NULL)')->execute([$u['UserID']]);
       respond(['success'=>false,'message'=>'This invitation code is invalid, expired, or already used.'],404);
     }
     $ownerId=(int)$invitation['OwnerUserID'];
     if($ownerId===(int)$u['UserID']){$pdo->rollBack();respond(['success'=>false,'message'=>'You cannot join your own workspace.'],422);}
     $s=$pdo->prepare('SELECT Status FROM Users WHERE UserID=?');$s->execute([$ownerId]);if($s->fetchColumn()!=='active'){$pdo->rollBack();respond(['success'=>false,'message'=>'The workspace owner account is unavailable.'],404);}
     $ownerDatabases=asset_backup_workspace_database_names($pdo,$ownerId);$legacyDatabases=asset_backup_legacy_workspace_database_names($ownerId);$databaseCheck=$pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME IN (?,?)');$databaseCheck->execute(array_values($ownerDatabases));$databaseCount=(int)$databaseCheck->fetchColumn();if($databaseCount!==2){$databaseCheck->execute(array_values($legacyDatabases));$databaseCount=(int)$databaseCheck->fetchColumn();}if($databaseCount!==2){$pdo->rollBack();respond(['success'=>false,'message'=>'The workspace is currently unavailable.'],503);}
     $s=$pdo->prepare('SELECT Status FROM WorkspaceMembers WHERE OwnerUserID=? AND MemberUserID=?');$s->execute([$ownerId,$u['UserID']]);$existing=$s->fetchColumn();
     if($existing==='active'){$pdo->rollBack();respond(['success'=>false,'message'=>'You already have access to this workspace.'],409);}
     $s=$pdo->prepare("INSERT INTO WorkspaceMembers(OwnerUserID,MemberUserID,Role,Status) VALUES(?,?,'editor','active') ON DUPLICATE KEY UPDATE Role='editor',Status='active',JoinedAt=NOW()");$s->execute([$ownerId,$u['UserID']]);
     $s=$pdo->prepare('UPDATE WorkspaceInvitations SET UsedBy=?,UsedAt=NOW() WHERE InvitationID=?');$s->execute([$u['UserID'],$invitation['InvitationID']]);
     $s=$pdo->prepare('INSERT INTO WorkspacePreferences(UserID,OwnerUserID) VALUES(?,?) ON DUPLICATE KEY UPDATE OwnerUserID=VALUES(OwnerUserID)');$s->execute([$u['UserID'],$ownerId]);
     $pdo->prepare('DELETE FROM WorkspaceInvitationAttempts WHERE UserID=?')->execute([$u['UserID']]);
     $pdo->commit();
   } catch(Throwable $error) {if($pdo->inTransaction())$pdo->rollBack();throw $error;}
   $_SESSION['workspace_owner_id']=$ownerId;$_SESSION['tenant_db']=$ownerDatabases['inventory'];$_SESSION['workspace_role']='editor';
   audit($pdo,(int)$u['UserID'],'workspace joined','workspace',['ownerId'=>$ownerId]);respond(['success'=>true,'message'=>'Workspace joined. You now have edit access.']);
 }
 if($action==='workspace_switch'){
   $u=session_user($pdo);$ownerId=filter_var($data['ownerId']??null,FILTER_VALIDATE_INT);
   if($ownerId===false||$ownerId<1)respond(['success'=>false,'message'=>'Choose a valid workspace.'],422);
   if($ownerId===(int)$u['UserID']){$role='owner';}
   else{$s=$pdo->prepare('SELECT Role FROM WorkspaceMembers WHERE OwnerUserID=? AND MemberUserID=? AND Status="active"');$s->execute([$ownerId,$u['UserID']]);$role=$s->fetchColumn();if(!in_array($role,['viewer','editor'],true))respond(['success'=>false,'message'=>'You do not have access to that workspace.'],403);}
   $workspaceDatabases=asset_backup_existing_workspace_database_names($pdo,(int)$ownerId);$inventoryName=$workspaceDatabases['inventory'];$controlName=$workspaceDatabases['control'];$s=$pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME IN (?,?)');$s->execute([$inventoryName,$controlName]);if((int)$s->fetchColumn()!==2)respond(['success'=>false,'message'=>'The selected workspace is not available.'],503);
   $s=$pdo->prepare('INSERT INTO WorkspacePreferences(UserID,OwnerUserID) VALUES(?,?) ON DUPLICATE KEY UPDATE OwnerUserID=VALUES(OwnerUserID)');$s->execute([$u['UserID'],$ownerId]);
   $_SESSION['workspace_owner_id']=$ownerId;$_SESSION['tenant_db']=$inventoryName;$_SESSION['workspace_role']=$role;
   respond(['success'=>true,'message'=>'Workspace switched.','role'=>$role]);
 }
 if($action==='member_role'){
   $u=session_user($pdo);$memberId=filter_var($data['userId']??null,FILTER_VALIDATE_INT);$role=(string)($data['role']??'');
   if($memberId===false||$memberId<1||!in_array($role,['viewer','editor'],true))respond(['success'=>false,'message'=>'Choose a valid member and access level.'],422);
   $s=$pdo->prepare('UPDATE WorkspaceMembers SET Role=? WHERE OwnerUserID=? AND MemberUserID=? AND Status="active"');$s->execute([$role,$u['UserID'],$memberId]);if(!$s->rowCount())respond(['success'=>false,'message'=>'That active workspace member could not be found.'],404);
   audit($pdo,(int)$u['UserID'],'workspace member role changed','workspace',['memberId'=>$memberId,'role'=>$role]);respond(['success'=>true,'message'=>'Member access updated.']);
 }
 if($action==='member_remove'){
   $u=session_user($pdo);$memberId=filter_var($data['userId']??null,FILTER_VALIDATE_INT);
   if($memberId===false||$memberId<1||$memberId===(int)$u['UserID'])respond(['success'=>false,'message'=>'Choose a valid team member.'],422);
   $s=$pdo->prepare("UPDATE WorkspaceMembers SET Status='removed' WHERE OwnerUserID=? AND MemberUserID=? AND Status='active'");$s->execute([$u['UserID'],$memberId]);if(!$s->rowCount())respond(['success'=>false,'message'=>'That active workspace member could not be found.'],404);
   audit($pdo,(int)$u['UserID'],'workspace member removed','workspace',['memberId'=>$memberId]);respond(['success'=>true,'message'=>'Workspace access removed.']);
 }
 if($action==='register'){
   $first=trim((string)($data['firstName']??''));$last=trim((string)($data['lastName']??''));$dept=trim((string)($data['department']??''));$area=trim((string)($data['workArea']??''));$username=trim((string)($data['username']??''));$password=(string)($data['password']??'');
   if($DB_INVENTORY!=='inventory')respond(['success'=>false,'message'=>'Open the main sign-in page at /assets-monitoring/page/login.html to create a new account.'],403);
   if(!$first||!$last||!$dept||!$area||!$username||preg_match('/^\s*$/',$username))respond(['success'=>false,'message'=>'Complete every required field.'],422);
   if(strlen($first)>100||strlen($last)>100||strlen($dept)>150||strlen($area)>150||strlen($username)>80||!preg_match('/^[\pL\pN._-]{2,80}$/u',$username))respond(['success'=>false,'message'=>'Check the field lengths and username format.'],422);
   if(!valid_new_password($password))respond(['success'=>false,'message'=>'Use 4 to 72 characters. Special characters, uppercase letters, and numbers are optional.'],422);
   if($password!==($data['confirmPassword']??null))respond(['success'=>false,'message'=>'Passwords do not match.'],422);
   $passwordHash=password_hash($password,PASSWORD_DEFAULT);$accountId=generate_account_id($pdo);
   $pdo->beginTransaction();
   $s=$pdo->prepare('INSERT INTO Users(EmployeeID,AccountID,FirstName,LastName,Department,WorkArea,Username,PasswordHash) VALUES(?,?,?,?,?,?,?,?)');
   try{$s->execute([$username,$accountId,$first,$last,$dept,$area,$username,$passwordHash]);}
   catch(PDOException $e){$pdo->rollBack();if($e->getCode()==='23000')respond(['success'=>false,'message'=>'That employee ID or username is already registered.'],409);throw $e;}
   $userId=(int)$pdo->lastInsertId();
   $databaseStem=asset_backup_allocate_workspace_stem($pdo,$first,$last,$userId);
   $pdo->prepare('UPDATE Users SET WorkspaceDatabaseStem=? WHERE UserID=?')->execute([$databaseStem,$userId]);
   try{
     audit($pdo,$userId,'account created','authentication');
     provision_user_databases($userId,['EmployeeID'=>$username,'AccountID'=>$accountId,'FirstName'=>$first,'LastName'=>$last,'WorkspaceDatabaseStem'=>$databaseStem,'Department'=>$dept,'WorkArea'=>$area,'Username'=>$username,'Status'=>'active'],$passwordHash);
     $pdo->commit();
   }catch(Throwable $e){
     if($pdo->inTransaction())$pdo->rollBack();
     respond(['success'=>false,'message'=>'Account setup could not be completed. No account was created. Please contact the administrator if this continues.'],500);
   }
   respond(['success'=>true,'message'=>'Account created. Save your permanent Account ID; it is required for account recovery.','accountId'=>$accountId]);
 }
 if($action==='password_recover'){
   $username=trim((string)($data['username']??''));$accountId=strtoupper(trim((string)($data['accountId']??'')));
   if($username===''||strlen($username)>80||!preg_match('/^[0-9]{9}$/',$accountId))respond(['success'=>false,'message'=>'Enter your username and 9-digit Account ID.'],422);
   $attemptKey=hash('sha256',strtolower($username));
   $attempt=$pdo->prepare('SELECT LockedUntil FROM AccountRecoveryAttempts WHERE AttemptKey=?');$attempt->execute([$attemptKey]);$lockedUntil=$attempt->fetchColumn();
   if($lockedUntil&&strtotime((string)$lockedUntil)>time())respond(['success'=>false,'message'=>'Too many recovery attempts. Try again in 15 minutes.'],429);
   $s=$pdo->prepare('SELECT UserID FROM Users WHERE (Username=? OR EmployeeID=?) AND AccountID=? AND Status="active" LIMIT 1');$s->execute([$username,$username,$accountId]);$userId=(int)$s->fetchColumn();
   if($userId<1){
     $pdo->prepare('INSERT INTO AccountRecoveryAttempts(AttemptKey,FailedAttempts,LockedUntil) VALUES(?,1,NULL) ON DUPLICATE KEY UPDATE FailedAttempts=IF(LockedUntil IS NOT NULL AND LockedUntil<=NOW(),1,FailedAttempts+1),LockedUntil=IF(FailedAttempts>=4,DATE_ADD(NOW(),INTERVAL 15 MINUTE),NULL)')->execute([$attemptKey]);
     respond(['success'=>false,'message'=>'The username or Account ID is incorrect.'],401);
   }
   $temporaryPassword=generate_temporary_password();$temporaryHash=password_hash($temporaryPassword,PASSWORD_DEFAULT);
   $tenantDatabases=asset_backup_existing_workspace_database_names($pdo,$userId);$tenantDatabase=$tenantDatabases['inventory'];
   $tenantCheck=$pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME IN (?,?)');$tenantCheck->execute(array_values($tenantDatabases));$tenantDatabaseCount=(int)$tenantCheck->fetchColumn();
   if($tenantDatabaseCount===1)respond(['success'=>false,'message'=>'The account workspace is incomplete. Contact the administrator for help.'],503);
   $pdo->beginTransaction();
   try {
     if($tenantDatabaseCount===2){
       $quotedTenant='`'.str_replace('`','``',$tenantDatabase).'`';
       $tenantUser=$pdo->prepare("UPDATE {$quotedTenant}.Users SET PasswordHash=? WHERE UserID=?");
       $tenantUser->execute([$temporaryHash,$userId]);
       if($tenantUser->rowCount()!==1)throw new RuntimeException('The account workspace credentials could not be updated.');
     }
     $update=$pdo->prepare('UPDATE Users SET PasswordHash=?,MustChangePassword=1 WHERE UserID=? AND Status="active"');$update->execute([$temporaryHash,$userId]);
     if($update->rowCount()!==1)throw new RuntimeException('The account recovery could not be completed.');
     $pdo->prepare('DELETE FROM AccountRecoveryAttempts WHERE AttemptKey=?')->execute([$attemptKey]);
     audit($pdo,$userId,'temporary password issued','authentication');
     $pdo->commit();
   } catch(Throwable $error) {if($pdo->inTransaction())$pdo->rollBack();throw $error;}
   respond(['success'=>true,'message'=>'Temporary password generated. It is shown once. Sign in with it and choose a new password immediately.','temporaryPassword'=>$temporaryPassword]);
 }
 if($action==='login'){
   $username=trim((string)($data['username']??''));$password=(string)($data['password']??'');$ip=client_ip();
   $s=$pdo->prepare('SELECT LockedUntil FROM AccountLockouts WHERE Username=?');$s->execute([substr($username,0,80)]);$locked=$s->fetchColumn();if($locked&&strtotime((string)$locked)>time())respond(['success'=>false,'message'=>'This account is temporarily locked after repeated failed attempts. Try again in 15 minutes.'],429);
   $s=$pdo->prepare('SELECT * FROM Users WHERE Username=? OR EmployeeID=? LIMIT 1');$s->execute([$username,$username]);$u=$s->fetch();$device=device_info();
   if(!$u||$u['Status']!=='active'||!password_verify($password,$u['PasswordHash'])){$l=$pdo->prepare('INSERT INTO LoginHistory(UserID,DeviceName,DeviceType,OperatingSystem,Browser,IPAddress,Success,AttemptedUsername,IsCurrentSession) VALUES(?,?,?,?,?,?,0,?,0)');$l->execute([$u['UserID']??null,$device['name'],$device['type'],$device['os'],$device['browser'],$ip,substr($username,0,80)]);$f=$pdo->prepare('INSERT INTO AccountLockouts(Username,FailedAttempts,LockedUntil) VALUES(?,1,NULL) ON DUPLICATE KEY UPDATE FailedAttempts=IF(LockedUntil IS NOT NULL AND LockedUntil<=NOW(),1,FailedAttempts+1),LockedUntil=IF(FailedAttempts>=4,DATE_ADD(NOW(),INTERVAL 15 MINUTE),NULL)');$f->execute([substr($username,0,80)]);respond(['success'=>false,'message'=>'Invalid username or password.'],401);}
   $clear=$pdo->prepare('DELETE FROM AccountLockouts WHERE Username=?');$clear->execute([substr($username,0,80)]);
   if(password_needs_rehash($u['PasswordHash'],PASSWORD_DEFAULT)){$s=$pdo->prepare('UPDATE Users SET PasswordHash=? WHERE UserID=?');$s->execute([password_hash($password,PASSWORD_DEFAULT),$u['UserID']]);}
   $previous=$pdo->prepare('SELECT LoginDateTime,DeviceName,DeviceType,OperatingSystem,Browser,IPAddress,Country,Region,City FROM LoginHistory WHERE UserID=? AND Success=1 ORDER BY LoginID DESC LIMIT 1');$previous->execute([$u['UserID']]);$prev=$previous->fetch()?:null;
   $known=false;if(!empty($data['rememberDevice'])){$token=bin2hex(random_bytes(32));$h=$pdo->prepare('INSERT INTO RememberedDevices(UserID,TokenHash,ExpiresAt) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 90 DAY))');$h->execute([$u['UserID'],hash('sha256',$token)]);setcookie('asset_device',$token,['expires'=>time()+7776000,'path'=>'/','secure'=>$https,'httponly'=>true,'samesite'=>'Lax']);$known=true;}elseif(!empty($_COOKIE['asset_device'])){$s=$pdo->prepare('SELECT 1 FROM RememberedDevices WHERE UserID=? AND TokenHash=? AND ExpiresAt>NOW()');$s->execute([$u['UserID'],hash('sha256',$_COOKIE['asset_device'])]);$known=(bool)$s->fetchColumn();}
   $tenantDatabases=asset_backup_existing_workspace_database_names($pdo,(int)$u['UserID']);$tenantInventory=$tenantDatabases['inventory'];$tenantControl=$tenantDatabases['control'];
   $tenantCheck=$pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME IN (?,?)');$tenantCheck->execute([$tenantInventory,$tenantControl]);$tenantDatabaseCount=(int)$tenantCheck->fetchColumn();
   if($tenantDatabaseCount===1)respond(['success'=>false,'message'=>'This account workspace is incomplete. Please contact the administrator.'],500);
   if($tenantDatabaseCount!==0&&$tenantDatabaseCount!==2)respond(['success'=>false,'message'=>'Unable to verify this account workspace. Please contact the administrator.'],500);
   $workspaceOwnerId=(int)$u['UserID'];$workspaceRole='owner';
   $savedWorkspace=$pdo->prepare('SELECT OwnerUserID FROM WorkspacePreferences WHERE UserID=?');$savedWorkspace->execute([$u['UserID']]);$preferredOwner=(int)$savedWorkspace->fetchColumn();
   if($preferredOwner>0&&$preferredOwner!==(int)$u['UserID']){
     $membership=$pdo->prepare('SELECT wm.Role FROM WorkspaceMembers wm JOIN Users owner ON owner.UserID=wm.OwnerUserID AND owner.Status="active" WHERE wm.OwnerUserID=? AND wm.MemberUserID=? AND wm.Status="active"');$membership->execute([$preferredOwner,$u['UserID']]);$preferredRole=$membership->fetchColumn();
     $preferredDatabases=asset_backup_existing_workspace_database_names($pdo,$preferredOwner);$preferredInventory=$preferredDatabases['inventory'];$preferredControl=$preferredDatabases['control'];$databaseCheck=$pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME IN (?,?)');$databaseCheck->execute([$preferredInventory,$preferredControl]);
     if(in_array($preferredRole,['viewer','editor'],true)&&(int)$databaseCheck->fetchColumn()===2){$workspaceOwnerId=$preferredOwner;$workspaceRole=$preferredRole;}
     else{$pdo->prepare('UPDATE WorkspacePreferences SET OwnerUserID=? WHERE UserID=?')->execute([$u['UserID'],$u['UserID']]);}
   }
   session_regenerate_id(true);$_SESSION['uid']=(int)$u['UserID'];$_SESSION['last_activity']=time();$_SESSION['login_db']=$DB_INVENTORY;$_SESSION['tenant_db']=$tenantDatabaseCount===2?$tenantInventory:$DB_INVENTORY;$l=$pdo->prepare('INSERT INTO LoginHistory(UserID,DeviceName,DeviceType,OperatingSystem,Browser,IPAddress,Success,IsCurrentSession) VALUES(?,?,?,?,?,?,1,1)');$l->execute([$u['UserID'],$device['name'],$device['type'],$device['os'],$device['browser'],$ip]);$_SESSION['login_id']=(int)$pdo->lastInsertId();audit($pdo,(int)$u['UserID'],'login','authentication',['rememberedDevice'=>$known]);
   $activeWorkspaceDatabases=asset_backup_existing_workspace_database_names($pdo,$workspaceOwnerId);
   $_SESSION['workspace_owner_id']=$workspaceOwnerId;$_SESSION['workspace_role']=$workspaceRole;$_SESSION['tenant_db']=$workspaceOwnerId===(int)$u['UserID']&&$tenantDatabaseCount!==2?$DB_INVENTORY:$activeWorkspaceDatabases['inventory'];
   $expire=$pdo->prepare('UPDATE LoginHistory SET IsCurrentSession=0,LogoutDateTime=COALESCE(LogoutDateTime,NOW()) WHERE UserID=? AND IsCurrentSession=1 AND LastActivity<DATE_SUB(NOW(),INTERVAL 20 MINUTE)');$expire->execute([$u['UserID']]);$active=$pdo->prepare('SELECT COUNT(*) FROM LoginHistory WHERE UserID=? AND IsCurrentSession=1 AND LoginID<>?');$active->execute([$u['UserID'],$_SESSION['login_id']]);
   $mustChangePassword=(int)$u['MustChangePassword']===1;
   $_SESSION['must_change_password']=$mustChangePassword;
   $redirect=$mustChangePassword?'../Tracking/inventory/settings/profile.html?forcePasswordChange=1':'../Tracking/sidebar/sidebar.html';
   respond(['success'=>true,'user'=>['id'=>$u['UserID'],'name'=>$u['FirstName'].' '.$u['LastName'],'department'=>$u['Department'],'workArea'=>$u['WorkArea']],'previousLogin'=>$prev,'device'=>$device,'ip'=>$ip,'rememberedDevice'=>$known,'multiLogin'=>(int)$active->fetchColumn()>0,'mustChangePassword'=>$mustChangePassword,'redirect'=>$redirect]);
 }
 if($action==='me'){$u=session_user($pdo);respond(['success'=>true,'user'=>$u,'workspaceRole'=>$_SESSION['workspace_role']??'owner','workspaceOwnerId'=>(int)($_SESSION['workspace_owner_id']??$u['UserID'])]);}
 if($action==='logout'){$u=session_user($pdo);if(($_SESSION['login_db']??'')===$DB_INVENTORY){$s=$pdo->prepare('UPDATE LoginHistory SET LogoutDateTime=NOW(),IsCurrentSession=0 WHERE LoginID=?');$s->execute([$_SESSION['login_id']??0]);}audit($pdo,(int)$u['UserID'],'logout','authentication');session_unset();session_destroy();setcookie(session_name(),'',time()-3600,'/');respond(['success'=>true]);}
 if($action==='history'){$u=session_user($pdo);$s=$pdo->prepare('SELECT LoginDateTime,LogoutDateTime,DeviceName,DeviceType,OperatingSystem,Browser,IPAddress,Country,Region,City,Success FROM LoginHistory WHERE UserID=? ORDER BY LoginID DESC LIMIT 100');$s->execute([$u['UserID']]);respond(['success'=>true,'history'=>$s->fetchAll()]);}
 if($action==='location'){$u=session_user($pdo);$lat=filter_var($data['latitude']??null,FILTER_VALIDATE_FLOAT);$lon=filter_var($data['longitude']??null,FILTER_VALIDATE_FLOAT);if($lat===false||$lon===false||$lat < -90||$lat > 90||$lon < -180||$lon > 180)respond(['success'=>false,'message'=>'Invalid location coordinates.'],422);$s=$pdo->prepare('UPDATE LoginHistory SET Latitude=?,Longitude=? WHERE LoginID=? AND UserID=?');$s->execute([$lat,$lon,$_SESSION['login_id']??0,$u['UserID']]);respond(['success'=>true]);}
 if($action==='activity'){$u=session_user($pdo);$d=body();$event=trim((string)($d['action']??''));$module=trim((string)($d['module']??''));if(!$event||!$module||strlen($event)>100||strlen($module)>100)respond(['success'=>false,'message'=>'Invalid activity.'],422);audit($pdo,(int)$u['UserID'],$event,$module);respond(['success'=>true]);}
 respond(['success'=>false,'message'=>'Unknown request.'],404);
} catch(Throwable $e) { error_log('Authentication API error: '.$e->getMessage()); respond(['success'=>false,'message'=>'Authentication service is unavailable.'],500); }
