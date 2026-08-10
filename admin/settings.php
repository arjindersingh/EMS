<?php

require_once __DIR__ . '/settings_funcs.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/layout.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!($_SESSION['admin_authenticated'] ?? false)) {
    header('Location: ' . buildUrl('admin'));
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
            header('Location: ' . buildUrl('admin/settings.php'));
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
                header('Location: ' . buildUrl('admin/settings.php'));
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

ob_start();
?>
    <h1>Settings Manager</h1>
    <p>Create, edit, delete, and review project-wide settings from a single page.</p>

    <?php if ($adminError !== ''): ?><div class="alert error"><?php echo htmlspecialchars($adminError, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if ($adminSuccess !== ''): ?><div class="alert success"><?php echo htmlspecialchars((string) $adminSuccess, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

    <form method="post" class="settings-form">
        <input type="hidden" name="action" value="save_setting">
        <input type="hidden" name="setting_id" value="<?php echo htmlspecialchars((string) ($settingFormData['setting_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

        <div class="form-field">
            <label for="setting_name">Setting Name</label>
            <input id="setting_name" name="setting_name" type="text" value="<?php echo htmlspecialchars((string) ($settingFormData['setting_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
        </div>

        <div class="form-field">
            <label for="setting_type">Setting Type</label>
            <select id="setting_type" name="setting_type">
                <option value="text" <?php echo (($settingFormData['setting_type'] ?? 'text') === 'text') ? 'selected' : ''; ?>>Text</option>
                <option value="number" <?php echo (($settingFormData['setting_type'] ?? 'text') === 'number') ? 'selected' : ''; ?>>Number</option>
                <option value="boolean" <?php echo (($settingFormData['setting_type'] ?? 'text') === 'boolean') ? 'selected' : ''; ?>>Boolean</option>
            </select>
        </div>

        <div class="form-field full-width">
            <label for="setting_value">Setting Value</label>
            <input id="setting_value" name="setting_value" type="text" value="<?php echo htmlspecialchars((string) ($settingFormData['setting_value'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
        </div>

        <div class="form-field full-width">
            <label for="setting_description">Description</label>
            <textarea id="setting_description" name="setting_description"><?php echo htmlspecialchars((string) ($settingFormData['setting_description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></textarea>
        </div>

        <div class="actions">
            <button type="submit" class="button-primary">Save Setting</button>
            <?php if (!empty($settingFormData['setting_id'])): ?>
                <a href="<?php echo htmlspecialchars(buildUrl('admin/settings.php'), ENT_QUOTES, 'UTF-8'); ?>" class="button-secondary">Cancel Edit</a>
            <?php endif; ?>
            <div class="form-note">Use this form to manage application settings with a clean responsive layout.</div>
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
                            <a href="<?php echo htmlspecialchars(buildUrl('admin/settings.php') . '?edit=' . (int) $setting['setting_id'], ENT_QUOTES, 'UTF-8'); ?>">Edit</a>
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

    <p><a href="<?php echo htmlspecialchars(buildUrl('admin'), ENT_QUOTES, 'UTF-8'); ?>">Back to admin</a></p>
<?php
$content = ob_get_clean();

renderAdminLayout('Project Settings', $content, ['current_path' => 'settings', 'page_heading' => 'Project Settings']);
