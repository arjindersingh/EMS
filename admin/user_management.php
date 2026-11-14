<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth_funcs.php';
require_once __DIR__ . '/rbac_funcs.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/events_funcs.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

requireAdminAuthentication();
requireMenuAccess($pdo = createDbConnection(), 'user_management');

$adminError = $_SESSION['admin_error'] ?? '';
$adminSuccess = $_SESSION['admin_success'] ?? '';
unset($_SESSION['admin_error'], $_SESSION['admin_success']);

try {
    $pdo = createDbConnection();
    ensureAdminUsersTable($pdo);
    ensureRolesTable($pdo);
    
    $allUsers = getAllAdminUsers($pdo);
    $allRoles = getAllRoles($pdo);
    $allEvents = getAllEvents($pdo);
} catch (PDOException $exception) {
    $adminError = 'Database error: ' . $exception->getMessage();
    $allUsers = [];
    $allRoles = [];
    $allEvents = [];
}

$editUserId = isset($_GET['edit']) ? (int) $_GET['edit'] : null;
$editUser = null;

if ($editUserId !== null && $pdo instanceof PDO) {
    $statement = $pdo->prepare(<<<'SQL'
        SELECT
            au.admin_user_id,
            au.name,
            au.username,
            au.email,
            au.role,
            au.role_id,
            au.assigned_event_id,
            r.role_name
        FROM admin_users au
        LEFT JOIN roles r ON au.role_id = r.role_id
        WHERE au.admin_user_id = :user_id
        LIMIT 1
SQL);
    $statement->execute([':user_id' => $editUserId]);
    $editUser = $statement->fetch();
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $pdo instanceof PDO) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $roleId = (int) ($_POST['role_id'] ?? 0);
        $eventId = (int) ($_POST['assigned_event_id'] ?? 0);

        if ($name === '' || $username === '' || $email === '' || $password === '') {
            $adminError = 'All fields are required.';
        } elseif (adminUserExists($pdo, $username, $email)) {
            $adminError = 'Username or email already exists.';
        } else {
            try {
                $userId = createAdminUser($pdo, $name, $email, $username, $password);
                
                if ($roleId > 0) {
                    assignRoleToUser($pdo, $userId, $roleId, $eventId > 0 ? $eventId : null);
                }
                
                $adminSuccess = 'User created successfully.';
                header('Location: ' . buildUrl('admin/user_management.php'));
                exit;
            } catch (PDOException $exception) {
                $adminError = 'Error creating user: ' . $exception->getMessage();
            }
        }
    } elseif ($action === 'update') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $roleId = (int) ($_POST['role_id'] ?? 0);
        $eventId = (int) ($_POST['assigned_event_id'] ?? 0);

        if ($name === '' || $username === '' || $email === '') {
            $adminError = 'Name, username, and email are required.';
        } elseif (adminUserExists($pdo, $username, $email, $userId)) {
            $adminError = 'Username or email already exists.';
        } else {
            try {
                $passwordArg = $password !== '' ? $password : null;
                updateAdminUser($pdo, $userId, $name, $email, $username, $passwordArg);
                
                if ($roleId > 0) {
                    assignRoleToUser($pdo, $userId, $roleId, $eventId > 0 ? $eventId : null);
                } else {
                    removeRoleFromUser($pdo, $userId);
                }
                
                $adminSuccess = 'User updated successfully.';
                header('Location: ' . buildUrl('admin/user_management.php'));
                exit;
            } catch (PDOException $exception) {
                $adminError = 'Error updating user: ' . $exception->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        
        if ($userId === (int) ($_SESSION['admin_user_id'] ?? 0)) {
            $adminError = 'Cannot delete your own account.';
        } else {
            try {
                deleteAdminUser($pdo, $userId);
                $adminSuccess = 'User deleted successfully.';
                header('Location: ' . buildUrl('admin/user_management.php'));
                exit;
            } catch (PDOException $exception) {
                $adminError = 'Error deleting user: ' . $exception->getMessage();
            }
        }
    }
}

ob_start();
?>

<style>
    .user-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 20px;
    }
    .user-table th, .user-table td {
        padding: 12px;
        text-align: left;
        border-bottom: 1px solid #ddd;
    }
    .user-table th {
        background-color: #f5f5f5;
        font-weight: bold;
    }
    .user-table tr:hover {
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
    .form-group select {
        width: 100%;
        padding: 8px;
        border: 1px solid #ddd;
        border-radius: 4px;
        box-sizing: border-box;
    }
    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
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
</style>

<h1>User Management</h1>

<?php if ($adminError !== ''): ?>
    <div class="alert error"><?php echo htmlspecialchars($adminError, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<?php if ($adminSuccess !== ''): ?>
    <div class="alert success"><?php echo htmlspecialchars($adminSuccess, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<!-- Tabs -->
<div class="tab-buttons">
    <button class="tab-button active" onclick="showTab('list')">Users List</button>
    <button class="tab-button" onclick="showTab('create')">Add New User</button>
    <?php if ($editUser): ?>
        <button class="tab-button active" onclick="showTab('edit')">Edit User</button>
    <?php endif; ?>
</div>

<!-- Users List Tab -->
<div id="list" class="tab-content active">
    <h2>All Users</h2>
    <?php if (count($allUsers) > 0): ?>
        <table class="user-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Assigned Event</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($allUsers as $user): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string) $user['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) $user['username'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) $user['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($user['role_name'] ?: 'No Role'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($user['event_title'] ?: '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars(date('M d, Y', strtotime((string) $user['created_at'])), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <div class="actions">
                                <a href="<?php echo htmlspecialchars(buildUrl('admin/user_management.php?edit=' . (int) $user['admin_user_id']), ENT_QUOTES, 'UTF-8'); ?>" class="btn-sm btn-edit">Edit</a>
                                <form method="post" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this user?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="user_id" value="<?php echo (int) $user['admin_user_id']; ?>">
                                    <button type="submit" class="btn-sm btn-delete">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>No users found.</p>
    <?php endif; ?>
</div>

<!-- Add New User Tab -->
<div id="create" class="tab-content">
    <h2>Add New User</h2>
    <div class="form-section">
        <form method="post">
            <input type="hidden" name="action" value="create">
            
            <div class="form-row">
                <div class="form-group">
                    <label for="name">Full Name *</label>
                    <input type="text" id="name" name="name" required>
                </div>
                <div class="form-group">
                    <label for="username">Username *</label>
                    <input type="text" id="username" name="username" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="email">Email *</label>
                    <input type="email" id="email" name="email" required>
                </div>
                <div class="form-group">
                    <label for="password">Password *</label>
                    <input type="password" id="password" name="password" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="role_id">Role</label>
                    <select id="role_id" name="role_id">
                        <option value="0">-- No Role --</option>
                        <?php foreach ($allRoles as $role): ?>
                            <option value="<?php echo (int) $role['role_id']; ?>">
                                <?php echo htmlspecialchars((string) $role['role_name'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="assigned_event_id">Assigned Event</label>
                    <select id="assigned_event_id" name="assigned_event_id">
                        <option value="0">-- No Event --</option>
                        <?php foreach ($allEvents as $event): ?>
                            <option value="<?php echo (int) $event['event_id']; ?>">
                                <?php echo htmlspecialchars((string) $event['event_title'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <button type="submit" class="btn-primary">Create User</button>
            <a href="<?php echo htmlspecialchars(buildUrl('admin'), ENT_QUOTES, 'UTF-8'); ?>" class="btn-secondary">Cancel</a>
        </form>
    </div>
</div>

<!-- Edit User Tab -->
<?php if ($editUser): ?>
    <div id="edit" class="tab-content active">
        <h2>Edit User</h2>
        <div class="form-section">
            <form method="post">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="user_id" value="<?php echo (int) $editUser['admin_user_id']; ?>">
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_name">Full Name *</label>
                        <input type="text" id="edit_name" name="name" value="<?php echo htmlspecialchars((string) $editUser['name'], ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_username">Username *</label>
                        <input type="text" id="edit_username" name="username" value="<?php echo htmlspecialchars((string) $editUser['username'], ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_email">Email *</label>
                        <input type="email" id="edit_email" name="email" value="<?php echo htmlspecialchars((string) $editUser['email'], ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_password">Password (leave blank to keep current)</label>
                        <input type="password" id="edit_password" name="password">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="edit_role_id">Role</label>
                        <select id="edit_role_id" name="role_id">
                            <option value="0">-- No Role --</option>
                            <?php foreach ($allRoles as $role): ?>
                                <option value="<?php echo (int) $role['role_id']; ?>" <?php echo ((int) $editUser['role_id'] === (int) $role['role_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string) $role['role_name'], ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="edit_assigned_event_id">Assigned Event</label>
                        <select id="edit_assigned_event_id" name="assigned_event_id">
                            <option value="0">-- No Event --</option>
                            <?php foreach ($allEvents as $event): ?>
                                <option value="<?php echo (int) $event['event_id']; ?>" <?php echo ((int) $editUser['assigned_event_id'] === (int) $event['event_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string) $event['event_title'], ENT_QUOTES, 'UTF-8'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <button type="submit" class="btn-primary">Update User</button>
                <a href="<?php echo htmlspecialchars(buildUrl('admin/user_management.php'), ENT_QUOTES, 'UTF-8'); ?>" class="btn-secondary">Cancel</a>
            </form>
        </div>
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
renderAdminLayout('User Management', $content, [
    'current_path' => 'user_management',
    'page_heading' => 'User Management',
    'show_sidebar' => true,
]);
