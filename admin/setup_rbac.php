<?php

/**
 * RBAC Setup Script
 * Run this once to initialize the role-based access control system
 * Usage: php admin/setup_rbac.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth_funcs.php';
require_once __DIR__ . '/rbac_funcs.php';
require_once __DIR__ . '/rbac_init.php';

try {
    $pdo = createDbConnection();
    
    echo "=========================================\n";
    echo "  EMS Role-Based Access Control Setup\n";
    echo "=========================================\n\n";
    
    echo "1. Initializing RBAC system...\n";
    initializeRBACSystem($pdo);
    echo "   ✓ RBAC tables created\n";
    echo "   ✓ Default roles created\n";
    echo "   ✓ Menu items configured\n";
    echo "   ✓ Default permissions set\n\n";
    
    echo "2. Verifying admin user...\n";
    $adminCount = getAdminUsersCount($pdo);
    if ($adminCount === 0) {
        echo "   ! No admin users found. Creating default admin...\n";
        $email = trim((string) getenv('ADMIN_EMAIL')) ?: 'admin@example.com';
        $username = trim((string) getenv('ADMIN_USERNAME')) ?: 'admin';
        $password = trim((string) getenv('ADMIN_PASSWORD')) ?: 'admin123';
        $name = trim((string) getenv('ADMIN_NAME')) ?: 'Administrator';
        
        $userId = createAdminUser($pdo, $name, $email, $username, $password);
        
        // Assign Admin role
        $adminRole = getRoleByName($pdo, 'Admin');
        if ($adminRole) {
            assignRoleToUser($pdo, $userId, (int) $adminRole['role_id']);
        }
        
        echo "   ✓ Default admin user created\n";
        echo "     Username: $username\n";
        echo "     Email: $email\n";
    } else {
        echo "   ✓ Admin users already exist ($adminCount user(s))\n";
        
        echo "\n3. Assigning roles to existing admin users...\n";
        $allAdmins = getAllAdminUsers($pdo);
        $adminRole = getRoleByName($pdo, 'Admin');
        
        if ($adminRole) {
            $assigned = 0;
            foreach ($allAdmins as $admin) {
                if (!$admin['role_id']) {
                    assignRoleToUser($pdo, (int) $admin['admin_user_id'], (int) $adminRole['role_id']);
                    $assigned++;
                }
            }
            if ($assigned > 0) {
                echo "   ✓ Assigned Admin role to $assigned user(s)\n";
            }
        }
    }
    
    echo "\n4. Available Roles:\n";
    $roles = getAllRoles($pdo);
    foreach ($roles as $role) {
        $eventAssigned = ((int) $role['event_assigned'] === 1) ? ' (requires event)' : '';
        echo "   • " . htmlspecialchars((string) $role['role_name']) . "$eventAssigned\n";
    }
    
    echo "\n5. Menu Structure:\n";
    $menus = getAllMenuItems($pdo);
    foreach ($menus as $menu) {
        if ($menu['menu_parent_key'] === null) {
            echo "   • " . htmlspecialchars((string) $menu['menu_label']) . "\n";
        }
    }
    
    echo "\n=========================================\n";
    echo "  Setup Complete!\n";
    echo "=========================================\n";
    echo "\nNext Steps:\n";
    echo "1. Visit: http://localhost:8000/admin\n";
    echo "2. Login with your admin credentials\n";
    echo "3. Go to 'Administration > Roles & Permissions'\n";
    echo "4. Create new roles and configure permissions\n";
    echo "5. Go to 'Administration > User Management'\n";
    echo "6. Create users and assign roles\n\n";
    
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
