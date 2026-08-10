<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth_funcs.php';
require_once __DIR__ . '/layout.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo = null;
$adminError = $_SESSION['admin_error'] ?? '';
$adminSuccess = $_SESSION['admin_success'] ?? '';
unset($_SESSION['admin_error'], $_SESSION['admin_success']);

try {
    $pdo = createDbConnection();
    ensureAdminUsersTable($pdo);
    createDefaultAdminUserIfNoneExists($pdo);
} catch (PDOException $exception) {
    $adminError = $adminError !== '' ? $adminError : 'Database connection failed: ' . $exception->getMessage();
}

requireAdminAuthentication();

$editingUser = null;
$adminUsers = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? ''; 

    if ($action === 'create_user') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = trim((string) ($_POST['password'] ?? ''));
        $role = trim((string) ($_POST['role'] ?? 'admin')) ?: 'admin';

        if ($name === '' || $username === '' || $email === '' || $password === '') {
            $adminError = 'Name, username, email, and password are required to create an admin user.';
        } elseif (adminUserExists($pdo, $username, $email)) {
            $adminError = 'An admin user already exists with that username or email.';
        } else {
            createAdminUser($pdo, $name, $email, $username, $password, $role);
            $adminSuccess = 'Admin user created successfully.';
        }
    } elseif ($action === 'edit_user') {
        $adminUserId = (int) ($_POST['admin_user_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $password = trim((string) ($_POST['password'] ?? ''));
        $role = trim((string) ($_POST['role'] ?? 'admin')) ?: 'admin';

        if ($adminUserId <= 0 || $name === '' || $username === '' || $email === '') {
            $adminError = 'Name, username, and email are required to update an admin user.';
        } elseif (adminUserExists($pdo, $username, $email, $adminUserId)) {
            $adminError = 'Another admin user already exists with that username or email.';
        } else {
            updateAdminUser($pdo, $adminUserId, $name, $email, $username, $password === '' ? null : $password, $role);
            $adminSuccess = 'Admin user updated successfully.';
        }
    } elseif ($action === 'delete_user') {
        $adminUserId = (int) ($_POST['admin_user_id'] ?? 0);
        $currentAdminId = (int) ($_SESSION['admin_user_id'] ?? 0);

        if ($adminUserId === $currentAdminId) {
            $adminError = 'You cannot delete the account you are currently signed in with.';
        } elseif ($adminUserId > 0 && deleteAdminUser($pdo, $adminUserId)) {
            $adminSuccess = 'Admin user deleted successfully.';
        } else {
            $adminError = 'Unable to delete the selected admin user.';
        }
    }
}

if (isset($_GET['edit']) && $pdo !== null) {
    $editingUser = getAdminUserById($pdo, (int) $_GET['edit']);
    if ($editingUser === null) {
        $adminError = 'Admin user not found.';
    }
}

if ($pdo !== null) {
    $adminUsers = getAllAdminUsers($pdo);
}

ob_start();
?>
    <?php if ($adminError !== ''): ?>
        <div class="alert error"><?php echo htmlspecialchars($adminError, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <?php if ($adminSuccess !== ''): ?>
        <div class="alert success"><?php echo htmlspecialchars($adminSuccess, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <section class="admin-user-management">
        <h2><?php echo $editingUser ? 'Edit Admin User' : 'Create Admin User'; ?></h2>
        <form method="post">
            <input type="hidden" name="action" value="<?php echo $editingUser ? 'edit_user' : 'create_user'; ?>">
            <?php if ($editingUser): ?>
                <input type="hidden" name="admin_user_id" value="<?php echo (int) $editingUser['admin_user_id']; ?>">
            <?php endif; ?>

            <label for="name">Name</label>
            <input id="name" name="name" type="text" required value="<?php echo htmlspecialchars($editingUser['name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

            <label for="username">Username</label>
            <input id="username" name="username" type="text" required value="<?php echo htmlspecialchars($editingUser['username'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

            <label for="email">Email</label>
            <input id="email" name="email" type="email" required value="<?php echo htmlspecialchars($editingUser['email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

            <label for="password">Password<?php echo $editingUser ? ' (leave blank to keep current)' : ''; ?></label>
            <input id="password" name="password" type="password" <?php echo $editingUser ? '' : 'required'; ?>>

            <label for="role">Role</label>
            <select id="role" name="role">
                <option value="admin"<?php echo ($editingUser['role'] ?? 'admin') === 'admin' ? ' selected' : ''; ?>>Admin</option>
                <option value="superadmin"<?php echo ($editingUser['role'] ?? '') === 'superadmin' ? ' selected' : ''; ?>>Superadmin</option>
            </select>

            <button type="submit"><?php echo $editingUser ? 'Update User' : 'Create User'; ?></button>
            <?php if ($editingUser): ?>
                <a class="button muted" href="<?php echo htmlspecialchars(buildUrl('admin/admin_users.php'), ENT_QUOTES, 'UTF-8'); ?>">Create New User</a>
            <?php endif; ?>
        </form>
    </section>

    <section class="admin-user-list">
        <h2>Admin Accounts</h2>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Created</th>
                    <th>Updated</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($adminUsers as $user): ?>
                    <tr>
                        <td><?php echo (int) $user['admin_user_id']; ?></td>
                        <td><?php echo htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($user['role'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($user['created_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars($user['updated_at'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <a href="<?php echo htmlspecialchars(buildUrl('admin/admin_users.php?edit=' . (int) $user['admin_user_id']), ENT_QUOTES, 'UTF-8'); ?>">Edit</a>
                            <?php if ((int) $user['admin_user_id'] !== (int) ($_SESSION['admin_user_id'] ?? 0)): ?>
                                <form method="post" style="display:inline;" onsubmit="return confirm('Delete this admin user?');">
                                    <input type="hidden" name="action" value="delete_user">
                                    <input type="hidden" name="admin_user_id" value="<?php echo (int) $user['admin_user_id']; ?>">
                                    <button type="submit">Delete</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
<?php
$content = ob_get_clean();

renderAdminLayout('Admin Users', $content, ['current_path' => 'admin_users', 'page_heading' => 'Manage Admin Users']);
