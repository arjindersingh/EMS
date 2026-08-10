<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth_funcs.php';
require_once __DIR__ . '/layout.php';

if (session_status() === PHP_SESSION_NONE) {
	session_start();
}

$adminError = $_SESSION['admin_error'] ?? '';
$adminSuccess = $_SESSION['admin_success'] ?? '';
unset($_SESSION['admin_error'], $_SESSION['admin_success']);

// Load event helper functions (no side effects)
require_once __DIR__ . '/events_funcs.php';
require_once __DIR__ . '/settings_funcs.php';

$pdo = null;
$events = [];
$eventFormData = [
	'event_id' => '',
	'event_title' => '',
	'event_code' => '',
	'event_tagline' => '',
	'event_theme' => '',
	'event_type' => 'Other',
	'event_description' => '',
	'start_date' => '',
	'end_date' => '',
	'start_time' => '',
	'end_time' => '',
	'venue_name' => '',
	'venue_address' => '',
	'city' => '',
	'state' => '',
	'country' => '',
	'registration_required' => 0,
	'registration_start_date' => '',
	'registration_end_date' => '',
	'registration_fee' => '0.00',
];

try {
	$pdo = createDbConnection();
	ensureAdminUsersTable($pdo);
	createDefaultAdminUserIfNoneExists($pdo);
	ensureEventsTable($pdo);
	ensureSettingsTable($pdo);
	$events = getAllEvents($pdo);
} catch (PDOException $exception) {
	$adminError = $adminError !== '' ? $adminError : 'Database connection failed: ' . $exception->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = $_POST['action'] ?? '';

	if ($action === 'login') {
		$login = trim((string) ($_POST['login'] ?? ''));
		$password = (string) ($_POST['password'] ?? '');

		if (!$pdo instanceof PDO) {
			// The connection error is already captured above. Do not mask it with
			// a TypeError when a login form is submitted during an outage.
			$adminError = $adminError !== '' ? $adminError : 'Database connection is unavailable. Please try again shortly.';
		} else {
		$user = getAdminUserByLogin($pdo, $login);
		if ($user !== null && password_verify($password, (string) $user['password_hash'])) {
			session_regenerate_id(true);
			$_SESSION['admin_authenticated'] = true;
			$_SESSION['admin_user_id'] = (int) $user['admin_user_id'];
			$_SESSION['admin_username'] = (string) $user['username'];
			$_SESSION['admin_name'] = (string) $user['name'];
			$_SESSION['admin_email'] = (string) $user['email'];
			$_SESSION['admin_success'] = 'Welcome back, ' . (string) $user['name'] . '.';
			header('Location: ' . buildUrl('admin'));
			exit;
		}

		$adminError = 'Invalid username, email, or password.';
		}
	} elseif ($action === 'logout') {
		session_destroy();
		session_start();
		header('Location: ' . buildUrl('admin'));
		exit;
	} elseif ($action === 'run_command') {
		requireAdminAuthentication();
		$_SESSION['admin_success'] = 'Protected admin command executed successfully.';
		header('Location: ' . buildUrl('admin'));
		exit;
	} elseif ($action === 'show_info') {
		requireAdminAuthentication();
		$_SESSION['admin_success'] = 'Protected admin information viewed successfully.';
		header('Location: ' . buildUrl('admin'));
		exit;
	}
}

if (isset($_GET['edit']) && $pdo !== null) {
	$editEventId = (int) $_GET['edit'];
	$selectedEvent = getEventById($pdo, $editEventId);
	if ($selectedEvent) {
		$eventFormData = $selectedEvent;
	} else {
		$adminError = 'Event not found.';
	}
}

$isAuthenticated = !empty($_SESSION['admin_authenticated']);
$eventTypeOptions = ['Academic','Cultural','Sports','Seminar','Workshop','Conference','Meeting','Celebration','Competition','Other'];

ob_start();
?>
	<p>This area is protected and requires authentication before any admin actions to be used.</p>

	<?php if ($adminError !== ''): ?>
		<div class="alert error"><?php echo htmlspecialchars($adminError, ENT_QUOTES, 'UTF-8'); ?></div>
	<?php endif; ?>

	<?php if ($adminSuccess !== ''): ?>
		<div class="alert success"><?php echo htmlspecialchars($adminSuccess, ENT_QUOTES, 'UTF-8'); ?></div>
	<?php endif; ?>

	<?php if (!$isAuthenticated): ?>
		<h2>Sign in</h2>
		<form method="post">
			<input type="hidden" name="action" value="login">
			<label for="login">Username or email</label>
			<input id="login" name="login" type="text" required autocomplete="username">

			<label for="password">Password</label>
			<input id="password" name="password" type="password" required autocomplete="current-password">

			<button type="submit">Login</button>
		</form>
	<?php else: ?>
		<p>You are signed in as <strong><?php echo htmlspecialchars((string) ($_SESSION['admin_name'] ?? $_SESSION['admin_username'] ?? 'admin'), ENT_QUOTES, 'UTF-8'); ?></strong>.</p>

		<div class="actions">
			<form method="post">
				<input type="hidden" name="action" value="logout">
				<button type="submit">Logout</button>
			</form>
		</div>

		<p>Use the sidebar to navigate through admin sections.</p>
	<?php endif; ?>
<?php
$content = ob_get_clean();

renderAdminLayout('EMS Admin', $content, ['current_path' => 'dashboard', 'page_heading' => 'Admin Panel', 'show_sidebar' => $isAuthenticated]);
