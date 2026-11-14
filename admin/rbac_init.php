<?php

/**
 * RBAC Initialization Bootstrap
 * Ensures all RBAC tables and default data are set up
 */

declare(strict_types=1);

require_once __DIR__ . '/auth_funcs.php';
require_once __DIR__ . '/rbac_funcs.php';

function initializeRBACSystem(PDO $pdo): void
{
    // Create all necessary tables
    ensureRolesTable($pdo);
    ensureMenuItemsTable($pdo);
    ensureRoleMenuPermissionsTable($pdo);
    ensureAdminUsersRoleColumn($pdo);
    ensureAdminUsersEventColumn($pdo);
    
    // Create default roles
    createDefaultRoles($pdo);
    
    // Create default menu items
    createDefaultMenuItems($pdo);
    
    // Ensure any missing menu items are added (for updates/migrations)
    ensureMissingMenuItemsExist($pdo);
    
    // Set default permissions for all roles
    setDefaultRolePermissions($pdo);
}
