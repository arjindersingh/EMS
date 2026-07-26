<?php

require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
	session_start();
}

function getAdminUsers(): array
{
	$configuredUsers = getenv('ADMIN_USERS');

	if (!empty($configuredUsers)) {
		$users = [];
		$entries = preg_split('/[\r\n,;]+/', trim((string) $configuredUsers));

		foreach ($entries as $entry) {
			$entry = trim((string) $entry);
			if ($entry === '') {
				continue;
			}

			$parts = explode(':', $entry, 2);
			if (count($parts) === 2) {
				$username = trim($parts[0]);
				$password = trim($parts[1]);

				if ($username !== '' && $password !== '') {
					$users[$username] = $password;
				}
			}
		}

		if (!empty($users)) {
			return $users;
		}
	}

	$singleUsername = getenv('ADMIN_USERNAME');
	$singlePassword = getenv('ADMIN_PASSWORD') ?: 'admin123';

	if (!empty($singleUsername)) {
		return [$singleUsername => $singlePassword];
	}

	return [
		'admin' => 'admin123',
	];
}

function requireAdminAuthentication(): void
{
	if (!($_SESSION['admin_authenticated'] ?? false)) {
		$_SESSION['admin_error'] = 'Please sign in to access the admin panel.';
		header('Location: /admin');
		exit;
	}
}

$adminUsers = getAdminUsers();
$adminError = $_SESSION['admin_error'] ?? '';
$adminSuccess = $_SESSION['admin_success'] ?? '';
unset($_SESSION['admin_error'], $_SESSION['admin_success']);

// Load event helper functions (no side effects)
require_once __DIR__ . '/events_funcs.php';

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
	ensureEventsTable($pdo);
	$events = getAllEvents($pdo);
} catch (PDOException $exception) {
	$adminError = $adminError !== '' ? $adminError : 'Database connection failed: ' . $exception->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = $_POST['action'] ?? '';

	if ($action === 'login') {
		$username = trim((string) ($_POST['username'] ?? ''));
		$password = (string) ($_POST['password'] ?? '');

		if (!empty($adminUsers[$username]) && $adminUsers[$username] === $password) {
			$_SESSION['admin_authenticated'] = true;
			$_SESSION['admin_username'] = $username;
			$_SESSION['admin_success'] = 'Welcome back, ' . $username . '.';
			header('Location: /admin');
			exit;
		}

		$adminError = 'Invalid username or password.';
	} elseif ($action === 'logout') {
		session_destroy();
		session_start();
		header('Location: /admin');
		exit;
	} elseif ($action === 'run_command') {
		requireAdminAuthentication();
		$_SESSION['admin_success'] = 'Protected admin command executed successfully.';
		header('Location: /admin');
		exit;
	} elseif ($action === 'show_info') {
		requireAdminAuthentication();
		$_SESSION['admin_success'] = 'Protected admin information viewed successfully.';
		header('Location: /admin');
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>EMS Admin</title>
	<style>
		body {
			font-family: Arial, sans-serif;
			margin: 2rem;
			line-height: 1.6;
			color: #222;
		}

		.card {
			max-width: 980px;
			margin: 0 auto;
			padding: 1.5rem 2rem;
			border: 1px solid #d0d7de;
			border-radius: 8px;
			background: #f8f9fa;
		}

		.alert {
			padding: 0.75rem 1rem;
			margin-bottom: 1rem;
			border-radius: 6px;
		}

		.alert.error {
			background: #ffe8e8;
			color: #9c1c1c;
		}

		.alert.success {
			background: #e8f7eb;
			color: #20653d;
		}

		form {
			margin-top: 1rem;
		}

		label {
			display: block;
			margin-top: 0.75rem;
			font-weight: bold;
		}

		input[type="text"], input[type="password"], input[type="date"], input[type="time"], input[type="number"], textarea, select {
			width: 100%;
			padding: 0.65rem;
			margin: 0.35rem 0 0.8rem;
			box-sizing: border-box;
		}

		textarea {
			min-height: 110px;
		}

		button {
			padding: 0.6rem 1rem;
			cursor: pointer;
		}

		.actions {
			display: flex;
			gap: 0.75rem;
			flex-wrap: wrap;
			margin-top: 1rem;
		}

		table {
			width: 100%;
			border-collapse: collapse;
			margin-top: 1rem;
		}

		th, td {
			border: 1px solid #d0d7de;
			padding: 0.6rem;
			text-align: left;
			vertical-align: top;
		}

		th {
			background: #eef2f7;
		}

		.inline-form {
			display: inline;
		}
	</style>
</head>
<body>
	<div class="card">
		<h1>Admin Panel</h1>
		<p>This area is protected and requires authentication before any admin actions can be used.</p>

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
				<label for="username">Username</label>
				<input id="username" name="username" type="text" required>

				<label for="password">Password</label>
				<input id="password" name="password" type="password" required>

				<button type="submit">Login</button>
			</form>
		<?php else: ?>
			<p>You are signed in as <strong><?php echo htmlspecialchars((string) ($_SESSION['admin_username'] ?? 'admin'), ENT_QUOTES, 'UTF-8'); ?></strong>.</p>

			<div class="actions">
				<form method="post">
					<input type="hidden" name="action" value="run_command">
					<button type="submit">Run protected command</button>
				</form>

				<form method="post">
					<input type="hidden" name="action" value="show_info">
					<button type="submit">View protected info</button>
				</form>

				<form method="post">
					<input type="hidden" name="action" value="logout">
					<button type="submit">Logout</button>
				</form>
			</div>

			<?php include __DIR__ . '/events.php'; ?>
		<?php endif; ?>

	</div>
</body>
</html>
