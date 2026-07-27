<?php

require_once __DIR__ . '/settings_funcs.php';
require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!($_SESSION['admin_authenticated'] ?? false)) {
    header('Location: /admin');
    exit;
}

$pdo = null;
$settings = [];
$settingFormData = [
    'setting_id' => '',
    'setting_name' => '',
    'setting_type' => 'text',
    'setting_value' => '',
    'setting_description' => '',
];
$adminError = '';
$adminSuccess = $_SESSION['admin_success'] ?? '';
unset($_SESSION['admin_success']);

try {
    $pdo = createDbConnection();
    ensureSettingsTable($pdo);
    $attendanceViewDefaults = [
        ['attendance_view_enabled', 'boolean', '1'],
        ['attendance_view_duration_seconds', 'number', '8'],
        ['attendance_view_idle_seconds', 'number', '12'],
        ['attendance_view_title', 'text', 'Welcome Attendee'],
        ['attendance_view_message', 'text', 'Welcome to the event'],
    ];

    foreach ($attendanceViewDefaults as $default) {
        [$name, $type, $value] = $default;
        if (!getSettingByName($pdo, $name)) {
            saveSetting($pdo, [
                'setting_id' => 0,
                'setting_name' => $name,
                'setting_type' => $type,
                'setting_value' => $value,
                'setting_description' => 'Controls the live attendance display view.',
            ]);
        }
    }

    $settings = getAllSettings($pdo);
} catch (PDOException $exception) {
    $adminError = 'Database connection failed: ' . $exception->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_setting') {
        $settingFormData = [
            'setting_id' => (int) ($_POST['setting_id'] ?? 0),
            'setting_name' => trim((string) ($_POST['setting_name'] ?? '')),
            'setting_type' => trim((string) ($_POST['setting_type'] ?? 'text')),
            'setting_value' => trim((string) ($_POST['setting_value'] ?? '')),
            'setting_description' => trim((string) ($_POST['setting_description'] ?? '')),
        ];

        try {
            saveSetting($pdo, $settingFormData);
            $_SESSION['admin_success'] = !empty($settingFormData['setting_id']) ? 'Setting updated successfully.' : 'Setting created successfully.';
            header('Location: /admin/settings.php');
            exit;
        } catch (InvalidArgumentException $exception) {
            $adminError = $exception->getMessage();
        } catch (PDOException $exception) {
            $adminError = 'Unable to save setting: ' . $exception->getMessage();
        }
    } elseif ($action === 'delete_setting') {
        $settingId = (int) ($_POST['setting_id'] ?? 0);
        if ($settingId > 0 && $pdo !== null) {
            try {
                deleteSetting($pdo, $settingId);
                $_SESSION['admin_success'] = 'Setting deleted successfully.';
                header('Location: /admin/settings.php');
                exit;
            } catch (PDOException $exception) {
                $adminError = 'Unable to delete setting: ' . $exception->getMessage();
            }
        }
    }
}

if (isset($_GET['edit']) && $pdo !== null) {
    $settingId = (int) $_GET['edit'];
    $setting = getSettingById($pdo, $settingId);
    if ($setting) {
        $settingFormData = [
            'setting_id' => (int) $setting['setting_id'],
            'setting_name' => $setting['setting_name'] ?? '',
            'setting_type' => $setting['setting_type'] ?? 'text',
            'setting_value' => $setting['setting_value'] ?? '',
            'setting_description' => $setting['setting_description'] ?? '',
        ];
    } else {
        $adminError = 'Setting not found.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings Manager</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 2rem; line-height: 1.6; color: #222; }
        .card { max-width: 1100px; margin: 0 auto; padding: 1.5rem 2rem; border: 1px solid #d0d7de; border-radius: 8px; background: #f8f9fa; }
        .alert { padding: 0.75rem 1rem; margin-bottom: 1rem; border-radius: 6px; }
        .alert.error { background: #ffe8e8; color: #9c1c1c; }
        .alert.success { background: #e8f7eb; color: #20653d; }
        form { margin-top: 1rem; }
        label { display: block; margin-top: 0.75rem; font-weight: bold; }
        input, textarea, select { width: 100%; padding: 0.65rem; margin: 0.35rem 0 0.8rem; box-sizing: border-box; }
        textarea { min-height: 110px; }
        button { padding: 0.6rem 1rem; cursor: pointer; }
        .actions { display: flex; gap: 0.75rem; flex-wrap: wrap; margin-top: 1rem; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th, td { border: 1px solid #d0d7de; padding: 0.6rem; text-align: left; vertical-align: top; }
        th { background: #eef2f7; }
        .inline-form { display: inline; }
    </style>
</head>
<body>
<div class="card">
    <h1>Settings Manager</h1>
    <p>Create, edit, delete, and review project-wide settings from a single page.</p>

    <?php if ($adminError !== ''): ?><div class="alert error"><?php echo htmlspecialchars($adminError, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if ($adminSuccess !== ''): ?><div class="alert success"><?php echo htmlspecialchars((string) $adminSuccess, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

    <form method="post">
        <input type="hidden" name="action" value="save_setting">
        <input type="hidden" name="setting_id" value="<?php echo htmlspecialchars((string) ($settingFormData['setting_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

        <label for="setting_name">Setting Name</label>
        <input id="setting_name" name="setting_name" type="text" value="<?php echo htmlspecialchars((string) ($settingFormData['setting_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>

        <label for="setting_type">Setting Type</label>
        <select id="setting_type" name="setting_type">
            <option value="text" <?php echo (($settingFormData['setting_type'] ?? 'text') === 'text') ? 'selected' : ''; ?>>Text</option>
            <option value="number" <?php echo (($settingFormData['setting_type'] ?? 'text') === 'number') ? 'selected' : ''; ?>>Number</option>
            <option value="boolean" <?php echo (($settingFormData['setting_type'] ?? 'text') === 'boolean') ? 'selected' : ''; ?>>Boolean</option>
        </select>

        <label for="setting_value">Setting Value</label>
        <input id="setting_value" name="setting_value" type="text" value="<?php echo htmlspecialchars((string) ($settingFormData['setting_value'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

        <label for="setting_description">Description</label>
        <textarea id="setting_description" name="setting_description"><?php echo htmlspecialchars((string) ($settingFormData['setting_description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>

        <div class="actions">
            <button type="submit">Save Setting</button>
            <?php if (!empty($settingFormData['setting_id'])): ?>
                <a href="/admin/settings.php"><button type="button">Cancel Edit</button></a>
            <?php endif; ?>
        </div>
    </form>

    <h2>Existing Settings</h2>
    <?php if (!empty($settings)): ?>
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Value</th>
                    <th>Description</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($settings as $setting): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string) ($setting['setting_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($setting['setting_type'] ?? 'text'), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo htmlspecialchars((string) ($setting['setting_value'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?php echo nl2br(htmlspecialchars((string) ($setting['setting_description'] ?? ''), ENT_QUOTES, 'UTF-8')); ?></td>
                        <td>
                            <a href="/admin/settings.php?edit=<?php echo (int) $setting['setting_id']; ?>">Edit</a>
                            |
                            <form class="inline-form" method="post" onsubmit="return confirm('Delete this setting?');">
                                <input type="hidden" name="action" value="delete_setting">
                                <input type="hidden" name="setting_id" value="<?php echo (int) $setting['setting_id']; ?>">
                                <button type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>No settings found yet.</p>
    <?php endif; ?>

    <p><a href="/admin">Back to admin</a></p>
</div>
</body>
</html>
