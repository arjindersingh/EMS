<?php

declare(strict_types=1);

/**
 * Role-Based Access Control (RBAC) Functions
 * Manages user roles, permissions, and menu access control
 */

// ============================================================================
// Database Schema Setup
// ============================================================================

function ensureRolesTable(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS roles (
            role_id INT AUTO_INCREMENT PRIMARY KEY,
            role_name VARCHAR(100) NOT NULL UNIQUE,
            role_description TEXT,
            event_assigned TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
SQL);
}

function ensureMenuItemsTable(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS menu_items (
            menu_id INT AUTO_INCREMENT PRIMARY KEY,
            menu_key VARCHAR(100) NOT NULL UNIQUE,
            menu_label VARCHAR(100) NOT NULL,
            menu_icon VARCHAR(50),
            menu_url VARCHAR(255),
            menu_parent_key VARCHAR(100),
            menu_order INT DEFAULT 0,
            menu_type ENUM('link', 'section') DEFAULT 'link',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
SQL);
}

function ensureRoleMenuPermissionsTable(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS role_menu_permissions (
            permission_id INT AUTO_INCREMENT PRIMARY KEY,
            role_id INT NOT NULL,
            menu_id INT NOT NULL,
            can_access TINYINT(1) DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_role_menu (role_id, menu_id),
            FOREIGN KEY (role_id) REFERENCES roles(role_id) ON DELETE CASCADE,
            FOREIGN KEY (menu_id) REFERENCES menu_items(menu_id) ON DELETE CASCADE
        )
SQL);
}

function ensureAdminUsersRoleColumn(PDO $pdo): void
{
    try {
        $result = $pdo->query("SHOW COLUMNS FROM admin_users LIKE 'role_id'");
        if (!$result || $result->rowCount() === 0) {
            $pdo->exec("ALTER TABLE admin_users ADD COLUMN role_id INT NULL DEFAULT NULL");
            $pdo->exec("ALTER TABLE admin_users ADD FOREIGN KEY (role_id) REFERENCES roles(role_id) ON DELETE SET NULL");
        }
    } catch (PDOException) {
        // Column might already exist
    }
}

function ensureAdminUsersEventColumn(PDO $pdo): void
{
    try {
        $result = $pdo->query("SHOW COLUMNS FROM admin_users LIKE 'assigned_event_id'");
        if (!$result || $result->rowCount() === 0) {
            $pdo->exec("ALTER TABLE admin_users ADD COLUMN assigned_event_id INT NULL DEFAULT NULL");
            $pdo->exec("ALTER TABLE admin_users ADD FOREIGN KEY (assigned_event_id) REFERENCES events(event_id) ON DELETE SET NULL");
        }
    } catch (PDOException) {
        // Column might already exist
    }
}

// ============================================================================
// Role Management
// ============================================================================

function createDefaultRoles(PDO $pdo): void
{
    $defaultRoles = [
        ['name' => 'Admin', 'desc' => 'Full system access', 'event' => 0],
        ['name' => 'Registration Incharge', 'desc' => 'Manages registration approvals and bulk uploads', 'event' => 1],
        ['name' => 'Registration Desk', 'desc' => 'Handles on-site registrations', 'event' => 1],
        ['name' => 'Stage Manager', 'desc' => 'Manages event stage and announcements', 'event' => 1],
        ['name' => 'Coordinator', 'desc' => 'General event coordinator', 'event' => 1],
        ['name' => 'Event Manager', 'desc' => 'Full event management permissions', 'event' => 1],
    ];

    $statement = $pdo->prepare(<<<'SQL'
        INSERT IGNORE INTO roles (role_name, role_description, event_assigned)
        VALUES (:name, :desc, :event)
SQL);

    foreach ($defaultRoles as $role) {
        $statement->execute([
            ':name' => $role['name'],
            ':desc' => $role['desc'],
            ':event' => $role['event'],
        ]);
    }
}

function getAllRoles(PDO $pdo): array
{
    $statement = $pdo->query('SELECT * FROM roles ORDER BY role_name ASC');
    return $statement ? $statement->fetchAll() : [];
}

function getRoleById(PDO $pdo, int $roleId): ?array
{
    $statement = $pdo->prepare('SELECT * FROM roles WHERE role_id = :role_id LIMIT 1');
    $statement->execute([':role_id' => $roleId]);
    $row = $statement->fetch();
    return $row ?: null;
}

function getRoleByName(PDO $pdo, string $roleName): ?array
{
    $statement = $pdo->prepare('SELECT * FROM roles WHERE role_name = :name LIMIT 1');
    $statement->execute([':name' => $roleName]);
    $row = $statement->fetch();
    return $row ?: null;
}

function createRole(PDO $pdo, string $roleName, string $description, bool $eventAssigned): void
{
    $statement = $pdo->prepare(<<<'SQL'
        INSERT INTO roles (role_name, role_description, event_assigned)
        VALUES (:name, :desc, :event)
SQL);
    $statement->execute([
        ':name' => $roleName,
        ':desc' => $description,
        ':event' => $eventAssigned ? 1 : 0,
    ]);
}

function updateRole(PDO $pdo, int $roleId, string $roleName, string $description, bool $eventAssigned): void
{
    $statement = $pdo->prepare(<<<'SQL'
        UPDATE roles
        SET role_name = :name, role_description = :desc, event_assigned = :event
        WHERE role_id = :role_id
SQL);
    $statement->execute([
        ':role_id' => $roleId,
        ':name' => $roleName,
        ':desc' => $description,
        ':event' => $eventAssigned ? 1 : 0,
    ]);
}

function deleteRole(PDO $pdo, int $roleId): void
{
    $statement = $pdo->prepare('DELETE FROM roles WHERE role_id = :role_id');
    $statement->execute([':role_id' => $roleId]);
}

// ============================================================================
// Menu Items Management
// ============================================================================

function createDefaultMenuItems(PDO $pdo): void
{
    $menuItems = [
        // Dashboard
        ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'icon-home', 'url' => '', 'parent' => null, 'order' => 0, 'type' => 'link'],
        
        // Events
        ['key' => 'events_section', 'label' => 'Events', 'icon' => 'icon-calendar', 'url' => null, 'parent' => null, 'order' => 10, 'type' => 'section'],
        ['key' => 'events_manage', 'label' => 'Manage Events', 'icon' => 'icon-edit', 'url' => 'events.php', 'parent' => 'events_section', 'order' => 11, 'type' => 'link'],
        ['key' => 'event_checkin', 'label' => 'Check-in', 'icon' => 'icon-check', 'url' => 'event_checkin.php', 'parent' => 'events_section', 'order' => 12, 'type' => 'link'],
        ['key' => 'event_schedules', 'label' => 'Event Schedules', 'icon' => 'icon-clock', 'url' => 'event_schedule.php', 'parent' => 'events_section', 'order' => 13, 'type' => 'link'],
        ['key' => 'event_itineraries', 'label' => 'Itineraries', 'icon' => 'icon-list', 'url' => 'event_itinerary.php', 'parent' => 'events_section', 'order' => 14, 'type' => 'link'],
        
        // Registrations
        ['key' => 'registration_section', 'label' => 'Registrations', 'icon' => 'icon-users', 'url' => null, 'parent' => null, 'order' => 20, 'type' => 'section'],
        ['key' => 'registration_manage', 'label' => 'Registrations', 'icon' => 'icon-list', 'url' => 'registration.php', 'parent' => 'registration_section', 'order' => 21, 'type' => 'link'],
        ['key' => 'registration_approvals', 'label' => 'Approvals', 'icon' => 'icon-check-circle', 'url' => 'registration_approvals.php', 'parent' => 'registration_section', 'order' => 22, 'type' => 'link'],
        ['key' => 'registration_bulk_upload', 'label' => 'Bulk Upload', 'icon' => 'icon-upload', 'url' => 'registration_bulk_upload.php', 'parent' => 'registration_section', 'order' => 23, 'type' => 'link'],
        ['key' => 'fetch_registration', 'label' => 'Fetch Registration', 'icon' => 'icon-download', 'url' => 'fetch_registration.php', 'parent' => 'registration_section', 'order' => 24, 'type' => 'link'],
        
        // Attendance & Reports
        ['key' => 'attendance_section', 'label' => 'Attendance', 'icon' => 'icon-clipboard', 'url' => null, 'parent' => null, 'order' => 30, 'type' => 'section'],
        ['key' => 'attendee_list', 'label' => 'Attendee List', 'icon' => 'icon-users', 'url' => 'attendee_list.php', 'parent' => 'attendance_section', 'order' => 31, 'type' => 'link'],
        ['key' => 'attendance_view', 'label' => 'View Attendance', 'icon' => 'icon-eye', 'url' => 'attendance_view.php', 'parent' => 'attendance_section', 'order' => 32, 'type' => 'link'],
        ['key' => 'attendance_reports', 'label' => 'Reports', 'icon' => 'icon-bar-chart', 'url' => 'attendance_reports.php', 'parent' => 'attendance_section', 'order' => 33, 'type' => 'link'],
        
        // Feedback
        ['key' => 'feedback_section', 'label' => 'Feedback', 'icon' => 'icon-message', 'url' => null, 'parent' => null, 'order' => 40, 'type' => 'section'],
        ['key' => 'feedback_manage', 'label' => 'Feedback', 'icon' => 'icon-list', 'url' => 'feedback.php', 'parent' => 'feedback_section', 'order' => 41, 'type' => 'link'],
        ['key' => 'feedback_analysis', 'label' => 'Analysis', 'icon' => 'icon-chart', 'url' => 'feedback_analysis.php', 'parent' => 'feedback_section', 'order' => 42, 'type' => 'link'],
        
        // Additional Features
        ['key' => 'event_pass', 'label' => 'Event Passes', 'icon' => 'icon-ticket', 'url' => 'event_pass.php', 'parent' => null, 'order' => 50, 'type' => 'link'],
        ['key' => 'broadcast', 'label' => 'Broadcast Messages', 'icon' => 'icon-send', 'url' => 'broadcast_messages.php', 'parent' => null, 'order' => 60, 'type' => 'link'],
        ['key' => 'playback', 'label' => 'Event Playback', 'icon' => 'icon-play', 'url' => 'playback.php', 'parent' => null, 'order' => 70, 'type' => 'link'],
        
        // Admin & Settings
        ['key' => 'admin_section', 'label' => 'Administration', 'icon' => 'icon-settings', 'url' => null, 'parent' => null, 'order' => 90, 'type' => 'section'],
        ['key' => 'user_management', 'label' => 'User Management', 'icon' => 'icon-user', 'url' => 'user_management.php', 'parent' => 'admin_section', 'order' => 91, 'type' => 'link'],
        ['key' => 'role_management', 'label' => 'Roles & Permissions', 'icon' => 'icon-lock', 'url' => 'role_management.php', 'parent' => 'admin_section', 'order' => 92, 'type' => 'link'],
        ['key' => 'admin_users', 'label' => 'Admin Users', 'icon' => 'icon-users', 'url' => 'admin_users.php', 'parent' => 'admin_section', 'order' => 93, 'type' => 'link'],
        ['key' => 'settings', 'label' => 'Settings', 'icon' => 'icon-sliders', 'url' => 'settings.php', 'parent' => 'admin_section', 'order' => 94, 'type' => 'link'],
    ];

    $statement = $pdo->prepare(<<<'SQL'
        INSERT IGNORE INTO menu_items (menu_key, menu_label, menu_icon, menu_url, menu_parent_key, menu_order, menu_type)
        VALUES (:key, :label, :icon, :url, :parent, :order, :type)
SQL);

    foreach ($menuItems as $item) {
        $statement->execute([
            ':key' => $item['key'],
            ':label' => $item['label'],
            ':icon' => $item['icon'],
            ':url' => $item['url'],
            ':parent' => $item['parent'],
            ':order' => $item['order'],
            ':type' => $item['type'],
        ]);
    }
}

function getAllMenuItems(PDO $pdo): array
{
    $statement = $pdo->query('SELECT * FROM menu_items ORDER BY menu_order ASC, menu_label ASC');
    return $statement ? $statement->fetchAll() : [];
}

function getMenuItemByKey(PDO $pdo, string $menuKey): ?array
{
    $statement = $pdo->prepare('SELECT * FROM menu_items WHERE menu_key = :key LIMIT 1');
    $statement->execute([':key' => $menuKey]);
    $row = $statement->fetch();
    return $row ?: null;
}

function ensureMissingMenuItemsExist(PDO $pdo): void
{
    $requiredMenuItems = [
        ['key' => 'fetch_registration', 'label' => 'Fetch Registration', 'icon' => 'icon-download', 'url' => 'fetch_registration.php', 'parent' => 'registration_section', 'order' => 24, 'type' => 'link'],
    ];

    $statement = $pdo->prepare(<<<'SQL'
        INSERT IGNORE INTO menu_items (menu_key, menu_label, menu_icon, menu_url, menu_parent_key, menu_order, menu_type)
        VALUES (:key, :label, :icon, :url, :parent, :order, :type)
SQL);

    foreach ($requiredMenuItems as $item) {
        $statement->execute([
            ':key' => $item['key'],
            ':label' => $item['label'],
            ':icon' => $item['icon'],
            ':url' => $item['url'],
            ':parent' => $item['parent'],
            ':order' => $item['order'],
            ':type' => $item['type'],
        ]);
    }
}

// ============================================================================
// Role-Menu Permission Management
// ============================================================================

function setDefaultRolePermissions(PDO $pdo): void
{
    $roles = getAllRoles($pdo);
    $menuItems = getAllMenuItems($pdo);

    foreach ($roles as $role) {
        foreach ($menuItems as $menu) {
            $roleId = (int) $role['role_id'];
            $menuId = (int) $menu['menu_id'];
            
            // Determine default access based on role
            $canAccess = determineDefaultMenuAccess((string) $role['role_name'], (string) $menu['menu_key']);

            try {
                $statement = $pdo->prepare(<<<'SQL'
                    INSERT INTO role_menu_permissions (role_id, menu_id, can_access)
                    VALUES (:role_id, :menu_id, :can_access)
                    ON DUPLICATE KEY UPDATE can_access = :can_access
SQL);
                $statement->execute([
                    ':role_id' => $roleId,
                    ':menu_id' => $menuId,
                    ':can_access' => $canAccess ? 1 : 0,
                ]);
            } catch (PDOException) {
                // Permission might already exist
            }
        }
    }
}

function determineDefaultMenuAccess(string $roleName, string $menuKey): bool
{
    // Admin gets access to everything
    if ($roleName === 'Admin') {
        return true;
    }

    // Admin sections restricted to Admin only
    if (str_starts_with($menuKey, 'admin_section') || str_ends_with($menuKey, 'admin_users') || 
        $menuKey === 'role_management' || $menuKey === 'user_management' || $menuKey === 'settings') {
        return false;
    }

    // Role-specific access
    return match ($roleName) {
        'Registration Incharge' => in_array($menuKey, [
            'dashboard', 'registration_section', 'registration_manage', 'registration_approvals',
            'registration_bulk_upload', 'fetch_registration', 'attendee_list', 'attendance_view', 'attendance_reports'
        ]),
        'Registration Desk' => in_array($menuKey, [
            'dashboard', 'event_checkin', 'attendee_list', 'attendance_view'
        ]),
        'Stage Manager' => in_array($menuKey, [
            'dashboard', 'event_checkin', 'broadcast', 'event_schedules', 'event_itineraries',
            'attendee_list', 'attendance_view'
        ]),
        'Coordinator' => in_array($menuKey, [
            'dashboard', 'events_section', 'events_manage', 'registration_section', 'registration_manage',
            'registration_approvals', 'fetch_registration', 'attendee_list', 'event_checkin', 'attendance_view', 'attendance_reports',
            'feedback_section', 'feedback_manage', 'event_schedules', 'event_itineraries'
        ]),
        'Event Manager' => in_array($menuKey, [
            'dashboard', 'events_section', 'events_manage', 'registration_section', 'registration_manage',
            'registration_approvals', 'registration_bulk_upload', 'fetch_registration', 'attendee_list', 'event_checkin',
            'attendance_view', 'attendance_reports', 'feedback_section', 'feedback_manage', 'feedback_analysis',
            'event_pass', 'broadcast', 'event_schedules', 'event_itineraries'
        ]),
        default => false,
    };
}

function getMenuPermissionsForRole(PDO $pdo, int $roleId): array
{
    $statement = $pdo->prepare(<<<'SQL'
        SELECT mi.*, rmp.can_access, rmp.permission_id
        FROM menu_items mi
        LEFT JOIN role_menu_permissions rmp ON mi.menu_id = rmp.menu_id AND rmp.role_id = :role_id
        ORDER BY mi.menu_order ASC
SQL);
    $statement->execute([':role_id' => $roleId]);
    return $statement ? $statement->fetchAll() : [];
}

function updateMenuPermission(PDO $pdo, int $roleId, int $menuId, bool $canAccess): void
{
    $statement = $pdo->prepare(<<<'SQL'
        INSERT INTO role_menu_permissions (role_id, menu_id, can_access)
        VALUES (:role_id, :menu_id, :can_access)
        ON DUPLICATE KEY UPDATE can_access = :can_access
SQL);
    $statement->execute([
        ':role_id' => $roleId,
        ':menu_id' => $menuId,
        ':can_access' => $canAccess ? 1 : 0,
    ]);
}

// ============================================================================
// User Role Assignment
// ============================================================================

function assignRoleToUser(PDO $pdo, int $adminUserId, int $roleId, ?int $eventId = null): void
{
    $statement = $pdo->prepare(<<<'SQL'
        UPDATE admin_users
        SET role_id = :role_id, assigned_event_id = :event_id
        WHERE admin_user_id = :user_id
SQL);
    $statement->execute([
        ':user_id' => $adminUserId,
        ':role_id' => $roleId,
        ':event_id' => $eventId,
    ]);
}

function removeRoleFromUser(PDO $pdo, int $adminUserId): void
{
    $statement = $pdo->prepare(<<<'SQL'
        UPDATE admin_users
        SET role_id = NULL, assigned_event_id = NULL
        WHERE admin_user_id = :user_id
SQL);
    $statement->execute([':user_id' => $adminUserId]);
}

function getUserRoleWithPermissions(PDO $pdo, int $adminUserId): ?array
{
    $statement = $pdo->prepare(<<<'SQL'
        SELECT
            au.admin_user_id,
            au.username,
            au.name,
            au.email,
            au.role_id,
            au.assigned_event_id,
            r.role_name,
            r.role_description,
            r.event_assigned
        FROM admin_users au
        LEFT JOIN roles r ON au.role_id = r.role_id
        WHERE au.admin_user_id = :user_id
        LIMIT 1
SQL);
    $statement->execute([':user_id' => $adminUserId]);
    $row = $statement->fetch();
    return $row ?: null;
}

function getUserAccessibleMenus(PDO $pdo, int $adminUserId): array
{
    $user = getUserRoleWithPermissions($pdo, $adminUserId);
    
    if (!$user || !$user['role_id']) {
        // No role assigned - return empty
        return [];
    }

    $roleId = (int) $user['role_id'];
    $statement = $pdo->prepare(<<<'SQL'
        SELECT
            mi.menu_key,
            mi.menu_label,
            mi.menu_icon,
            mi.menu_url,
            mi.menu_parent_key,
            mi.menu_order,
            mi.menu_type,
            COALESCE(rmp.can_access, 0) as can_access
        FROM menu_items mi
        LEFT JOIN role_menu_permissions rmp ON mi.menu_id = rmp.menu_id AND rmp.role_id = :role_id
        WHERE COALESCE(rmp.can_access, 0) = 1
        ORDER BY mi.menu_order ASC
SQL);
    $statement->execute([':role_id' => $roleId]);
    return $statement ? $statement->fetchAll() : [];
}

function canUserAccessMenu(PDO $pdo, int $adminUserId, string $menuKey): bool
{
    $user = getUserRoleWithPermissions($pdo, $adminUserId);
    
    if (!$user || !$user['role_id']) {
        return false;
    }

    $roleId = (int) $user['role_id'];
    $statement = $pdo->prepare(<<<'SQL'
        SELECT COUNT(*) as count
        FROM menu_items mi
        JOIN role_menu_permissions rmp ON mi.menu_id = rmp.menu_id
        WHERE mi.menu_key = :menu_key
          AND rmp.role_id = :role_id
          AND rmp.can_access = 1
        LIMIT 1
SQL);
    $statement->execute([
        ':menu_key' => $menuKey,
        ':role_id' => $roleId,
    ]);
    $result = $statement->fetch();
    return ($result['count'] ?? 0) > 0;
}

function getAdminUserRoleName(PDO $pdo, int $adminUserId): string
{
    $user = getUserRoleWithPermissions($pdo, $adminUserId);
    return $user && $user['role_name'] ? (string) $user['role_name'] : 'No Role Assigned';
}

// ============================================================================
// Authorization Check (for page access)
// ============================================================================

function requireMenuAccess(PDO $pdo, string $menuKey): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    $adminUserId = (int) ($_SESSION['admin_user_id'] ?? 0);
    
    if ($adminUserId === 0 || !canUserAccessMenu($pdo, $adminUserId, $menuKey)) {
        http_response_code(403);
        echo '<h1>Access Denied</h1>';
        echo '<p>You do not have permission to access this page.</p>';
        echo '<p><a href="' . htmlspecialchars(buildUrl('admin')) . '">Return to Dashboard</a></p>';
        exit;
    }
}

// ============================================================================
// Additional Helper Functions
// ============================================================================

function assignEventToUser(PDO $pdo, int $userId, int $eventId): bool
{
    $statement = $pdo->prepare('UPDATE admin_users SET assigned_event_id = :event_id WHERE admin_user_id = :user_id');
    return $statement->execute([
        ':user_id' => $userId,
        ':event_id' => $eventId,
    ]);
}

function getMenuItems(PDO $pdo): array
{
    $statement = $pdo->query('SELECT * FROM menu_items ORDER BY menu_order ASC');
    return $statement ? $statement->fetchAll() : [];
}

function getRoleMenuPermissions(PDO $pdo, int $roleId): array
{
    $statement = $pdo->prepare(<<<'SQL'
        SELECT rmp.*, mi.menu_label
        FROM role_menu_permissions rmp
        LEFT JOIN menu_items mi ON rmp.menu_id = mi.menu_id
        WHERE rmp.role_id = :role_id
        ORDER BY mi.menu_order ASC
SQL
    );
    $statement->execute([':role_id' => $roleId]);
    return $statement ? $statement->fetchAll() : [];
}
