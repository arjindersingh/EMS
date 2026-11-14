<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth_funcs.php';
require_once __DIR__ . '/rbac_funcs.php';
require_once __DIR__ . '/layout.php';

if (session_status() === PHP_SESSION_NONE) {
	session_start();
}

requireAdminAuthentication();

$pdo = createDbConnection();
$adminError = $_SESSION['admin_error'] ?? '';
$adminSuccess = $_SESSION['admin_success'] ?? '';
unset($_SESSION['admin_error'], $_SESSION['admin_success']);

$tab = $_GET['tab'] ?? 'users';
$action = $_POST['action'] ?? '';

// Handle user actions
if ($action === 'create_user') {
	$name = trim($_POST['name'] ?? '');
	$email = trim($_POST['email'] ?? '');
	$username = trim($_POST['username'] ?? '');
	$password = $_POST['password'] ?? '';
	$role_id = (int) ($_POST['role_id'] ?? 0);
	$event_id = (int) ($_POST['event_id'] ?? 0);

	if (!$name || !$email || !$username || !$password) {
		$adminError = 'All fields are required.';
	} elseif (adminUserExists($pdo, $username, $email)) {
		$adminError = 'Username or email already exists.';
	} else {
		try {
			$userId = createAdminUser($pdo, $name, $email, $username, $password);
			if ($role_id > 0) {
				assignRoleToUser($pdo, $userId, $role_id);
			}
			if ($event_id > 0) {
				assignEventToUser($pdo, $userId, $event_id);
			}
			$adminSuccess = "User '$name' created successfully!";
			$tab = 'users';
		} catch (Exception $e) {
			$adminError = 'Error creating user: ' . $e->getMessage();
		}
	}
} elseif ($action === 'update_user') {
	$user_id = (int) $_POST['user_id'];
	$name = trim($_POST['name'] ?? '');
	$email = trim($_POST['email'] ?? '');
	$username = trim($_POST['username'] ?? '');
	$password = $_POST['password'] ?? '';
	$role_id = (int) ($_POST['role_id'] ?? 0);
	$event_id = (int) ($_POST['event_id'] ?? 0);

	if (!$name || !$email || !$username) {
		$adminError = 'Name, email, and username are required.';
	} else {
		try {
			$pass = !empty($password) ? $password : null;
			updateAdminUser($pdo, $user_id, $name, $email, $username, $pass);
			if ($role_id > 0) {
				assignRoleToUser($pdo, $user_id, $role_id);
			}
			if ($event_id > 0) {
				assignEventToUser($pdo, $user_id, $event_id);
			}
			$adminSuccess = "User updated successfully!";
		} catch (Exception $e) {
			$adminError = 'Error updating user: ' . $e->getMessage();
		}
	}
} elseif ($action === 'delete_user') {
	$user_id = (int) $_POST['user_id'];
	try {
		if (deleteAdminUser($pdo, $user_id)) {
			$adminSuccess = 'User deleted successfully!';
		}
	} catch (Exception $e) {
		$adminError = 'Error deleting user: ' . $e->getMessage();
	}
}

// Handle role actions
if ($action === 'create_role') {
	$role_name = trim($_POST['role_name'] ?? '');
	$description = trim($_POST['description'] ?? '');
	$event_assigned = isset($_POST['event_assigned']) ? true : false;

	if (!$role_name) {
		$adminError = 'Role name is required.';
	} else {
		try {
			createRole($pdo, $role_name, $description, $event_assigned);
			$adminSuccess = "Role '$role_name' created successfully!";
			$tab = 'roles';
		} catch (Exception $e) {
			$adminError = 'Error creating role: ' . $e->getMessage();
		}
	}
} elseif ($action === 'update_role') {
	$role_id = (int) $_POST['role_id'];
	$role_name = trim($_POST['role_name'] ?? '');
	$description = trim($_POST['description'] ?? '');
	$event_assigned = isset($_POST['event_assigned']) ? true : false;

	if (!$role_name) {
		$adminError = 'Role name is required.';
	} else {
		try {
			updateRole($pdo, $role_id, $role_name, $description, $event_assigned);
			$adminSuccess = 'Role updated successfully!';
		} catch (Exception $e) {
			$adminError = 'Error updating role: ' . $e->getMessage();
		}
	}
} elseif ($action === 'delete_role') {
	$role_id = (int) $_POST['role_id'];
	try {
		deleteRole($pdo, $role_id);
		$adminSuccess = 'Role deleted successfully!';
	} catch (Exception $e) {
		$adminError = 'Error deleting role: ' . $e->getMessage();
	}
}

// Handle permission actions
if ($action === 'update_permissions') {
	$role_id = (int) $_POST['role_id'];
	try {
		$menus = getAllMenuItems($pdo);
		foreach ($menus as $menu) {
			$permission_key = 'menu_' . $menu['menu_id'];
			$can_access = isset($_POST[$permission_key]) ? true : false;
			updateMenuPermission($pdo, $role_id, $menu['menu_id'], $can_access);
		}
		$adminSuccess = 'Permissions updated successfully!';
	} catch (Exception $e) {
		$adminError = 'Error updating permissions: ' . $e->getMessage();
	}
}

// Get data for forms
$users = getAllAdminUsers($pdo);
$roles = getAllRoles($pdo);
$menus = getAllMenuItems($pdo);
$events = [];

// Try to get events if the function exists
if (function_exists('getAllEvents')) {
	require_once __DIR__ . '/events_funcs.php';
	$events = getAllEvents($pdo) ?? [];
}

$editingUser = null;
if (isset($_GET['edit_user'])) {
	$user_id = (int) $_GET['edit_user'];
	$editingUser = getAdminUserById($pdo, $user_id);
	$tab = 'users';
}

$editingRole = null;
if (isset($_GET['edit_role'])) {
	$role_id = (int) $_GET['edit_role'];
	$editingRole = getRoleById($pdo, $role_id);
	$tab = 'roles';
}

$editingPermissions = null;
if (isset($_GET['permissions_role'])) {
	$role_id = (int) $_GET['permissions_role'];
	$editingPermissions = getRoleById($pdo, $role_id);
	$editingPermissions['permissions'] = getMenuPermissionsForRole($pdo, $role_id);
	$tab = 'permissions';
}

ob_start();
?>
<style>
	.tabs {
		display: flex;
		gap: 1px;
		margin-bottom: 20px;
		border-bottom: 2px solid #ddd;
	}

	.tab-button {
		padding: 12px 20px;
		background: #f5f5f5;
		border: none;
		cursor: pointer;
		font-size: 14px;
		font-weight: 500;
		border-bottom: 3px solid transparent;
		transition: all 0.2s;
	}

	.tab-button:hover {
		background: #e8e8e8;
	}

	.tab-button.active {
		background: white;
		border-bottom-color: #007bff;
		color: #007bff;
	}

	.tab-content {
		display: none;
	}

	.tab-content.active {
		display: block;
	}

	.form-group {
		margin-bottom: 15px;
	}

	.form-group label {
		display: block;
		margin-bottom: 5px;
		font-weight: 500;
		color: #333;
	}

	.form-group input,
	.form-group select,
	.form-group textarea {
		width: 100%;
		padding: 8px;
		border: 1px solid #ccc;
		border-radius: 4px;
		font-size: 14px;
	}

	.form-group textarea {
		resize: vertical;
		min-height: 80px;
	}

	.form-row {
		display: grid;
		grid-template-columns: 1fr 1fr;
		gap: 15px;
	}

	.form-actions {
		display: flex;
		gap: 10px;
		margin-top: 20px;
	}

	.form-actions button {
		padding: 10px 20px;
		border: none;
		border-radius: 4px;
		cursor: pointer;
		font-size: 14px;
		font-weight: 500;
	}

	.btn-primary {
		background: #007bff;
		color: white;
	}

	.btn-primary:hover {
		background: #0056b3;
	}

	.btn-secondary {
		background: #6c757d;
		color: white;
	}

	.btn-secondary:hover {
		background: #545b62;
	}

	.btn-danger {
		background: #dc3545;
		color: white;
	}

	.btn-danger:hover {
		background: #c82333;
	}

	.btn-small {
		padding: 6px 12px;
		font-size: 12px;
	}

	table {
		width: 100%;
		border-collapse: collapse;
		margin-top: 15px;
	}

	table th {
		background: #f5f5f5;
		padding: 12px;
		text-align: left;
		font-weight: 600;
		border-bottom: 2px solid #ddd;
	}

	table td {
		padding: 12px;
		border-bottom: 1px solid #eee;
	}

	table tr:hover {
		background: #f9f9f9;
	}

	.actions-cell {
		display: flex;
		gap: 5px;
	}

	.permission-grid {
		display: grid;
		grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
		gap: 15px;
		margin: 20px 0;
	}

	.permission-item {
		border: 1px solid #ddd;
		padding: 12px;
		border-radius: 4px;
		display: flex;
		align-items: center;
		gap: 10px;
	}

	.permission-item input[type="checkbox"] {
		width: auto;
		cursor: pointer;
	}

	.permission-item label {
		margin: 0;
		cursor: pointer;
		flex: 1;
	}

	.section {
		margin-bottom: 30px;
	}

	.section h3 {
		margin-top: 0;
		padding-bottom: 10px;
		border-bottom: 2px solid #007bff;
	}

	.alert {
		padding: 15px;
		border-radius: 4px;
		margin-bottom: 20px;
	}

	.alert.error {
		background: #f8d7da;
		color: #721c24;
		border: 1px solid #f5c6cb;
	}

	.alert.success {
		background: #d4edda;
		color: #155724;
		border: 1px solid #c3e6cb;
	}
</style>

<h1>Users Management</h1>

<?php if ($adminError): ?>
	<div class="alert error"><?php echo htmlspecialchars($adminError); ?></div>
<?php endif; ?>

<?php if ($adminSuccess): ?>
	<div class="alert success"><?php echo htmlspecialchars($adminSuccess); ?></div>
<?php endif; ?>

<div class="tabs">
	<button class="tab-button <?php echo $tab === 'users' ? 'active' : ''; ?>" onclick="switchTab('users')">
		📝 Create User
	</button>
	<button class="tab-button <?php echo $tab === 'roles' ? 'active' : ''; ?>" onclick="switchTab('roles')">
		👥 User Roles
	</button>
	<button class="tab-button <?php echo $tab === 'permissions' ? 'active' : ''; ?>" onclick="switchTab('permissions')">
		🔒 Permission Settings
	</button>
</div>

<!-- TAB 1: CREATE/MANAGE USERS -->
<div id="users" class="tab-content <?php echo $tab === 'users' ? 'active' : ''; ?>">
	<div class="section">
		<h3><?php echo $editingUser ? 'Edit User' : 'Create New User'; ?></h3>

		<form method="post">
			<input type="hidden" name="action" value="<?php echo $editingUser ? 'update_user' : 'create_user'; ?>">
			<?php if ($editingUser): ?>
				<input type="hidden" name="user_id" value="<?php echo $editingUser['admin_user_id']; ?>">
			<?php endif; ?>

			<div class="form-row">
				<div class="form-group">
					<label for="name">Full Name *</label>
					<input type="text" id="name" name="name" required
						   value="<?php echo htmlspecialchars($editingUser['name'] ?? ''); ?>">
				</div>
				<div class="form-group">
					<label for="email">Email *</label>
					<input type="email" id="email" name="email" required
						   value="<?php echo htmlspecialchars($editingUser['email'] ?? ''); ?>">
				</div>
			</div>

			<div class="form-row">
				<div class="form-group">
					<label for="username">Username *</label>
					<input type="text" id="username" name="username" required
						   value="<?php echo htmlspecialchars($editingUser['username'] ?? ''); ?>">
				</div>
				<div class="form-group">
					<label for="password">Password <?php echo !$editingUser ? '*' : '(leave empty to keep current)'; ?></label>
					<input type="password" id="password" name="password"
						   <?php echo !$editingUser ? 'required' : ''; ?>>
				</div>
			</div>

			<div class="form-row">
				<div class="form-group">
					<label for="role_id">Role</label>
					<select id="role_id" name="role_id">
						<option value="0">-- Select Role --</option>
						<?php foreach ($roles as $role): ?>
							<option value="<?php echo $role['role_id']; ?>"
									<?php echo ($editingUser && $editingUser['role_id'] == $role['role_id']) ? 'selected' : ''; ?>>
								<?php echo htmlspecialchars($role['role_name']); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="form-group">
					<label for="event_id">Assigned Event</label>
					<select id="event_id" name="event_id">
						<option value="0">-- No Event --</option>
						<?php foreach ($events as $event): ?>
							<option value="<?php echo $event['event_id']; ?>"
									<?php echo ($editingUser && $editingUser['assigned_event_id'] == $event['event_id']) ? 'selected' : ''; ?>>
								<?php echo htmlspecialchars($event['event_title']); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>

			<div class="form-actions">
				<button type="submit" class="btn-primary">
					<?php echo $editingUser ? '💾 Update User' : '➕ Create User'; ?>
				</button>
				<?php if ($editingUser): ?>
					<a href="?tab=users" style="padding: 10px 20px; background: #6c757d; color: white; border-radius: 4px; text-decoration: none;">
						Cancel
					</a>
				<?php endif; ?>
			</div>
		</form>
	</div>

	<div class="section">
		<h3>Existing Users (<?php echo count($users); ?>)</h3>

		<?php if (empty($users)): ?>
			<p style="color: #666;">No users found.</p>
		<?php else: ?>
			<table>
				<thead>
					<tr>
						<th>Username</th>
						<th>Email</th>
						<th>Name</th>
						<th>Role</th>
						<th>Event</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($users as $user): ?>
						<tr>
							<td><?php echo htmlspecialchars($user['username']); ?></td>
							<td><?php echo htmlspecialchars($user['email']); ?></td>
							<td><?php echo htmlspecialchars($user['name']); ?></td>
							<td><?php echo htmlspecialchars($user['role_name'] ?? '—'); ?></td>
							<td><?php echo htmlspecialchars($user['event_title'] ?? '—'); ?></td>
							<td class="actions-cell">
								<a href="?tab=users&edit_user=<?php echo $user['admin_user_id']; ?>"
								   class="btn-primary btn-small" style="text-decoration: none;">Edit</a>
								<form method="post" style="display: inline;"
									  onsubmit="return confirm('Delete this user?');">
									<input type="hidden" name="action" value="delete_user">
									<input type="hidden" name="user_id" value="<?php echo $user['admin_user_id']; ?>">
									<button type="submit" class="btn-danger btn-small">Delete</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
</div>

<!-- TAB 2: USER ROLES -->
<div id="roles" class="tab-content <?php echo $tab === 'roles' ? 'active' : ''; ?>">
	<div class="section">
		<h3><?php echo $editingRole ? 'Edit Role' : 'Create New Role'; ?></h3>

		<form method="post">
			<input type="hidden" name="action" value="<?php echo $editingRole ? 'update_role' : 'create_role'; ?>">
			<?php if ($editingRole): ?>
				<input type="hidden" name="role_id" value="<?php echo $editingRole['role_id']; ?>">
			<?php endif; ?>

			<div class="form-group">
				<label for="role_name">Role Name *</label>
				<input type="text" id="role_name" name="role_name" required
					   value="<?php echo htmlspecialchars($editingRole['role_name'] ?? ''); ?>">
			</div>

			<div class="form-group">
				<label for="description">Description</label>
				<textarea id="description" name="description"><?php echo htmlspecialchars($editingRole['description'] ?? ''); ?></textarea>
			</div>

			<div class="form-group">
				<label for="event_assigned">
					<input type="checkbox" id="event_assigned" name="event_assigned"
						   <?php echo ($editingRole && $editingRole['event_assigned']) ? 'checked' : ''; ?>>
					Allow Event Assignment for this Role
				</label>
			</div>

			<div class="form-actions">
				<button type="submit" class="btn-primary">
					<?php echo $editingRole ? '💾 Update Role' : '➕ Create Role'; ?>
				</button>
				<?php if ($editingRole): ?>
					<a href="?tab=roles" style="padding: 10px 20px; background: #6c757d; color: white; border-radius: 4px; text-decoration: none;">
						Cancel
					</a>
				<?php endif; ?>
			</div>
		</form>
	</div>

	<div class="section">
		<h3>Existing Roles (<?php echo count($roles); ?>)</h3>

		<?php if (empty($roles)): ?>
			<p style="color: #666;">No roles found.</p>
		<?php else: ?>
			<table>
				<thead>
					<tr>
						<th>Role Name</th>
						<th>Description</th>
						<th>Created</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($roles as $role): ?>
						<tr>
							<td><strong><?php echo htmlspecialchars($role['role_name']); ?></strong></td>
							<td><?php echo htmlspecialchars($role['description'] ?? '—'); ?></td>
							<td><?php echo isset($role['created_at']) ? date('M d, Y', strtotime($role['created_at'])) : '—'; ?></td>
							<td class="actions-cell">
								<a href="?tab=roles&edit_role=<?php echo $role['role_id']; ?>"
								   class="btn-primary btn-small" style="text-decoration: none;">Edit</a>
								<a href="?tab=permissions&permissions_role=<?php echo $role['role_id']; ?>"
								   class="btn-primary btn-small" style="text-decoration: none;">Permissions</a>
								<form method="post" style="display: inline;"
									  onsubmit="return confirm('Delete this role?');">
									<input type="hidden" name="action" value="delete_role">
									<input type="hidden" name="role_id" value="<?php echo $role['role_id']; ?>">
									<button type="submit" class="btn-danger btn-small">Delete</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
</div>

<!-- TAB 3: PERMISSION SETTINGS -->
<div id="permissions" class="tab-content <?php echo $tab === 'permissions' ? 'active' : ''; ?>">
	<div class="section">
		<h3>Permission Settings</h3>

		<?php if ($editingPermissions): ?>
			<form method="post">
				<input type="hidden" name="action" value="update_permissions">
				<input type="hidden" name="role_id" value="<?php echo $editingPermissions['role_id']; ?>">

				<h4>Configure access for role: <strong><?php echo htmlspecialchars($editingPermissions['role_name']); ?></strong></h4>

				<p style="margin-top: 20px; margin-bottom: 20px; color: #666;">
					Check the menu items this role should have access to:
				</p>

				<div class="permission-grid">
					<?php foreach ($menus as $menu): ?>
						<?php
							$has_permission = false;
							if (isset($editingPermissions['permissions'])) {
								foreach ($editingPermissions['permissions'] as $perm) {
									if ($perm['menu_id'] == $menu['menu_id'] && $perm['can_access']) {
										$has_permission = true;
										break;
									}
								}
							}
						?>
						<div class="permission-item">
								<input type="checkbox" id="menu_<?php echo $menu['menu_id']; ?>"
									   name="menu_<?php echo $menu['menu_id']; ?>"
									   <?php echo $has_permission ? 'checked' : ''; ?>>
								<label for="menu_<?php echo $menu['menu_id']; ?>">
									<?php echo htmlspecialchars($menu['menu_label']); ?>
								</label>
						</div>
					<?php endforeach; ?>
				</div>

				<div class="form-actions">
					<button type="submit" class="btn-primary">💾 Save Permissions</button>
					<a href="?tab=permissions" style="padding: 10px 20px; background: #6c757d; color: white; border-radius: 4px; text-decoration: none;">
						Cancel
					</a>
				</div>
			</form>
		<?php else: ?>
			<p style="color: #666; margin-bottom: 20px;">
				Select a role to configure its menu permissions:
			</p>

			<table>
				<thead>
					<tr>
						<th>Role Name</th>
						<th>Description</th>
						<th>Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($roles as $role): ?>
						<tr>
							<td><strong><?php echo htmlspecialchars($role['role_name']); ?></strong></td>
							<td><?php echo htmlspecialchars($role['description'] ?? '—'); ?></td>
							<td>
								<a href="?tab=permissions&permissions_role=<?php echo $role['role_id']; ?>"
								   class="btn-primary btn-small" style="text-decoration: none;">Configure</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
</div>

<script>
	function switchTab(tabName) {
		// Hide all tab contents
		const contents = document.querySelectorAll('.tab-content');
		contents.forEach(content => content.classList.remove('active'));

		// Remove active class from all buttons
		const buttons = document.querySelectorAll('.tab-button');
		buttons.forEach(button => button.classList.remove('active'));

		// Show selected tab and mark button as active
		document.getElementById(tabName).classList.add('active');
		event.target.classList.add('active');

		// Update URL
		window.history.pushState({}, '', '?tab=' + tabName);
	}
</script>

<?php
$content = ob_get_clean();

renderAdminLayout('Users Management', $content, [
	'current_path' => 'users',
	'page_heading' => 'Users Management',
	'show_sidebar' => true,
]);
