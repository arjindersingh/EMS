<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth_funcs.php';
require_once __DIR__ . '/rbac_funcs.php';
require_once __DIR__ . '/layout.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

requireAdminAuthentication();
requireMenuAccess($pdo = createDbConnection(), 'role_management');

$adminError = $_SESSION['admin_error'] ?? '';
$adminSuccess = $_SESSION['admin_success'] ?? '';
unset($_SESSION['admin_error'], $_SESSION['admin_success']);

try {
    $pdo = createDbConnection();
    ensureRolesTable($pdo);
    ensureMenuItemsTable($pdo);
    ensureRoleMenuPermissionsTable($pdo);
    
    $allRoles = getAllRoles($pdo);
    $allMenuItems = getAllMenuItems($pdo);
} catch (PDOException $exception) {
    $adminError = 'Database error: ' . $exception->getMessage();
    $allRoles = [];
    $allMenuItems = [];
}

$editRoleId = isset($_GET['edit']) ? (int) $_GET['edit'] : null;
$editRole = null;
$roleMenuPermissions = [];

if ($editRoleId !== null) {
    $editRole = getRoleById($pdo, $editRoleId);
    if ($editRole) {
        $roleMenuPermissions = getMenuPermissionsForRole($pdo, $editRoleId);
    }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo instanceof PDO) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_role') {
        $roleName = trim($_POST['role_name'] ?? '');
        $description = trim($_POST['role_description'] ?? '');
        $eventAssigned = isset($_POST['event_assigned']) ? (bool) $_POST['event_assigned'] : false;

        if ($roleName === '') {
            $adminError = 'Role name is required.';
        } else {
            try {
                createRole($pdo, $roleName, $description, $eventAssigned);
                $adminSuccess = 'Role created successfully.';
                header('Location: ' . buildUrl('admin/role_management.php'));
                exit;
            } catch (PDOException $exception) {
                $adminError = 'Error creating role: ' . $exception->getMessage();
            }
        }
    } elseif ($action === 'update_permissions') {
        $roleId = (int) ($_POST['role_id'] ?? 0);
        
        if ($roleId > 0) {
            try {
                foreach ($allMenuItems as $menu) {
                    $menuId = (int) $menu['menu_id'];
                    $canAccess = isset($_POST['menu_' . $menuId]);
                    updateMenuPermission($pdo, $roleId, $menuId, $canAccess);
                }
                $adminSuccess = 'Permissions updated successfully.';
                header('Location: ' . buildUrl('admin/role_management.php?edit=' . $roleId));
                exit;
            } catch (PDOException $exception) {
                $adminError = 'Error updating permissions: ' . $exception->getMessage();
            }
        }
    } elseif ($action === 'delete_role') {
        $roleId = (int) ($_POST['role_id'] ?? 0);
        
        try {
            deleteRole($pdo, $roleId);
            $adminSuccess = 'Role deleted successfully.';
            header('Location: ' . buildUrl('admin/role_management.php'));
            exit;
        } catch (PDOException $exception) {
            $adminError = 'Error deleting role: ' . $exception->getMessage();
        }
    }
}

ob_start();
?>

<style>
    .role-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
    }
    .role-table th, .role-table td {
        padding: 12px;
        text-align: left;
        border-bottom: 1px solid #ddd;
    }
    .role-table th {
        background-color: #f5f5f5;
        font-weight: bold;
    }
    .role-table tr:hover {
        background-color: #f9f9f9;
    }
    .actions {
        display: flex;
        gap: 8px;
    }
    .btn-sm {
        padding: 6px 12px;
        font-size: 12px;
        text-decoration: none;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        display: inline-block;
    }
    .btn-edit {
        background-color: #007bff;
        color: white;
    }
    .btn-delete {
        background-color: #dc3545;
        color: white;
    }
    .btn-edit:hover {
        background-color: #0056b3;
    }
    .btn-delete:hover {
        background-color: #c82333;
    }
    .form-section {
        background-color: #f9f9f9;
        padding: 20px;
        border-radius: 8px;
        margin-bottom: 30px;
        border: 1px solid #e0e0e0;
    }
    .form-group {
        margin-bottom: 15px;
    }
    .form-group label {
        display: block;
        margin-bottom: 5px;
        font-weight: bold;
    }
    .form-group input,
    .form-group textarea,
    .form-group select {
        width: 100%;
        padding: 8px;
        border: 1px solid #ddd;
        border-radius: 4px;
        box-sizing: border-box;
    }
    .form-group textarea {
        min-height: 100px;
        resize: vertical;
    }
    .alert {
        padding: 12px;
        border-radius: 4px;
        margin-bottom: 20px;
    }
    .alert.error {
        background-color: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }
    .alert.success {
        background-color: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }
    .permissions-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
        gap: 15px;
        margin-top: 20px;
    }
    .permission-item {
        display: flex;
        align-items: center;
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 4px;
        background-color: #fafafa;
    }
    .permission-item input[type="checkbox"] {
        width: auto;
        margin-right: 10px;
        cursor: pointer;
    }
    .permission-item label {
        margin: 0;
        cursor: pointer;
        flex: 1;
        font-weight: normal;
    }
    .role-badge {
        display: inline-block;
        background-color: #007bff;
        color: white;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 12px;
        margin-right: 5px;
    }
    .tab-buttons {
        display: flex;
        gap: 10px;
        margin-bottom: 20px;
        border-bottom: 2px solid #e0e0e0;
    }
    .tab-button {
        padding: 10px 20px;
        border: none;
        background-color: transparent;
        cursor: pointer;
        font-weight: bold;
        border-bottom: 3px solid transparent;
        color: #666;
    }
    .tab-button.active {
        border-bottom-color: #007bff;
        color: #007bff;
    }
    .tab-content {
        display: none;
    }
    .tab-content.active {
        display: block;
    }
    .checkbox-label {
        display: flex;
        align-items: center;
    }
</style>

<h1>Roles & Permissions</h1>

<?php if ($adminError !== ''): ?>
    <div class="alert error"><?php echo htmlspecialchars($adminError, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<?php if ($adminSuccess !== ''): ?>
    <div class="alert success"><?php echo htmlspecialchars($adminSuccess, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<!-- Tabs -->
<div class="tab-buttons">
    <button class="tab-button active" onclick="showTab('list')">Roles List</button>
    <button class="tab-button" onclick="showTab('create')">Create Role</button>
    <?php if ($editRole): ?>
        <button class="tab-button active" onclick="showTab('permissions')">Edit Permissions</button>
    <?php endif; ?>
</div>

<!-- Roles List Tab -->
<div id="list" class="tab-content active">
    <h2>All Roles</h2>
    <?php if (count($allRoles) > 0): ?>
        <table class="role-table">
            <thead>
                <tr>
                    <th>Role Name</th>
                    <th>Description</th>
                    <th>Event Assigned</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($allRoles as $role): ?>
                    <tr>
                        <td>
                            <span class="role-badge"><?php echo htmlspecialchars((string) $role['role_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </td>
                        <td><?php echo htmlspecialchars((string) $role['role_description'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo ((int) $role['event_assigned'] === 1) ? '✓ Yes' : '✗ No'; ?></td>
                        <td><?php echo htmlspecialchars(date('M d, Y', strtotime((string) $role['created_at'])), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <div class="actions">
                                <a href="<?php echo htmlspecialchars(buildUrl('admin/role_management.php?edit=' . (int) $role['role_id']), ENT_QUOTES, 'UTF-8'); ?>" class="btn-sm btn-edit">Configure</a>
                                <?php if ((string) $role['role_name'] !== 'Admin'): ?>
                                    <form method="post" style="display:inline;" onsubmit="return confirm('Are you sure? Users with this role will lose access.');">
                                        <input type="hidden" name="action" value="delete_role">
                                        <input type="hidden" name="role_id" value="<?php echo (int) $role['role_id']; ?>">
                                        <button type="submit" class="btn-sm btn-delete">Delete</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>No roles found.</p>
    <?php endif; ?>
</div>

<!-- Create Role Tab -->
<div id="create" class="tab-content">
    <h2>Create New Role</h2>
    <div class="form-section">
        <form method="post">
            <input type="hidden" name="action" value="create_role">
            
            <div class="form-group">
                <label for="role_name">Role Name *</label>
                <input type="text" id="role_name" name="role_name" placeholder="e.g., Event Organizer" required>
            </div>

            <div class="form-group">
                <label for="role_description">Description</label>
                <textarea id="role_description" name="role_description" placeholder="Describe the responsibilities of this role..."></textarea>
            </div>

            <div class="form-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="event_assigned" value="1">
                    <span style="margin-left: 5px;">This role requires event assignment</span>
                </label>
                <small style="display: block; margin-top: 5px; color: #666;">When checked, users with this role can be assigned to specific events.</small>
            </div>

            <button type="submit" class="btn-primary">Create Role</button>
            <a href="<?php echo htmlspecialchars(buildUrl('admin'), ENT_QUOTES, 'UTF-8'); ?>" class="btn-secondary">Cancel</a>
        </form>
    </div>
</div>

<!-- Edit Permissions Tab -->
<?php if ($editRole): ?>
    <div id="permissions" class="tab-content active">
        <h2>Configure Permissions: <?php echo htmlspecialchars((string) $editRole['role_name'], ENT_QUOTES, 'UTF-8'); ?></h2>
        
        <div class="form-section">
            <p><strong>Description:</strong> <?php echo htmlspecialchars((string) $editRole['role_description'], ENT_QUOTES, 'UTF-8'); ?></p>
        </div>

        <form method="post">
            <input type="hidden" name="action" value="update_permissions">
            <input type="hidden" name="role_id" value="<?php echo (int) $editRole['role_id']; ?>">
            
            <h3>Menu Items Access</h3>
            <div class="permissions-grid">
                <?php foreach ($roleMenuPermissions as $perm): ?>
                    <?php
                        $permId = (int) $perm['permission_id'];
                        $menuId = (int) $perm['menu_id'];
                        $canAccess = (bool) ($perm['can_access'] ?? false);
                        $menuLabel = (string) $perm['menu_label'];
                        $menuType = (string) $perm['menu_type'];
                    ?>
                    <div class="permission-item">
                        <input 
                            type="checkbox" 
                            id="menu_<?php echo $menuId; ?>" 
                            name="menu_<?php echo $menuId; ?>" 
                            value="1"
                            <?php echo $canAccess ? 'checked' : ''; ?>
                        >
                        <label for="menu_<?php echo $menuId; ?>">
                            <?php echo htmlspecialchars($menuLabel, ENT_QUOTES, 'UTF-8'); ?>
                            <?php if ($menuType === 'section'): ?>
                                <small style="display: block; color: #999; margin-top: 3px;">(Section)</small>
                            <?php endif; ?>
                        </label>
                    </div>
                <?php endforeach; ?>
            </div>

            <div style="margin-top: 30px;">
                <button type="submit" class="btn-primary">Save Permissions</button>
                <a href="<?php echo htmlspecialchars(buildUrl('admin/role_management.php'), ENT_QUOTES, 'UTF-8'); ?>" class="btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
<?php endif; ?>

<script>
    function showTab(tabName) {
        // Hide all tabs
        const tabs = document.querySelectorAll('.tab-content');
        tabs.forEach(tab => tab.classList.remove('active'));
        
        // Remove active class from all buttons
        const buttons = document.querySelectorAll('.tab-button');
        buttons.forEach(btn => btn.classList.remove('active'));
        
        // Show selected tab
        document.getElementById(tabName).classList.add('active');
        
        // Add active class to clicked button
        event.target.classList.add('active');
    }
</script>

<?php
$content = ob_get_clean();
renderAdminLayout('Roles & Permissions', $content, [
    'current_path' => 'role_management',
    'page_heading' => 'Roles & Permissions Management',
    'show_sidebar' => true,
]);
