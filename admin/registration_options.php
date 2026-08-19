<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/registration_admin_funcs.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['admin_authenticated'])) {
    header('Location: ' . buildUrl('admin'));
    exit;
}

if (empty($_SESSION['admin_registration_options_csrf'])) {
    $_SESSION['admin_registration_options_csrf'] = bin2hex(random_bytes(24));
}

$optionGroups = [
    'salutation' => 'Salutation',
    'designation' => 'Designation',
    'teacher_post' => 'Post',
    'role' => 'Role',
    'institution_level' => 'Institution Level',
    'state' => 'State',
    'country' => 'Country',
];

$pdo = null;
$options = [];
$error = (string) ($_SESSION['admin_error'] ?? '');
$success = (string) ($_SESSION['admin_success'] ?? '');
unset($_SESSION['admin_error'], $_SESSION['admin_success']);

try {
    $pdo = createDbConnection();
    ensureAdminRegistrationTables($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['admin_registration_options_csrf'], (string) ($_POST['csrf_token'] ?? ''))) {
            http_response_code(403);
            exit('Invalid request token.');
        }

        $action = (string) ($_POST['action'] ?? '');
        try {
            if ($action === 'add_option') {
                $group = (string) ($_POST['option_group'] ?? '');
                $value = trim((string) ($_POST['option_value'] ?? ''));
                if (!array_key_exists($group, $optionGroups) || $value === '') {
                    throw new InvalidArgumentException('Choose a valid dropdown and enter a value.');
                }

                $statement = $pdo->prepare(
                    'INSERT INTO registration_form_options (option_group, option_value, sort_order) VALUES (:group_name, :value, :sort_order)'
                );
                $statement->execute([
                    ':group_name' => $group,
                    ':value' => $value,
                    ':sort_order' => (int) ($_POST['sort_order'] ?? 0),
                ]);
                $_SESSION['admin_success'] = 'Dropdown option added.';
            } elseif ($action === 'toggle_option') {
                $optionId = (int) ($_POST['option_id'] ?? 0);
                if ($optionId <= 0) {
                    throw new InvalidArgumentException('Choose a valid dropdown option.');
                }

                $statement = $pdo->prepare(
                    'UPDATE registration_form_options SET is_active = 1 - is_active WHERE option_id = :id'
                );
                $statement->execute([':id' => $optionId]);
                if ($statement->rowCount() === 0) {
                    throw new InvalidArgumentException('The dropdown option could not be found.');
                }
                $_SESSION['admin_success'] = 'Dropdown option updated.';
            } else {
                throw new InvalidArgumentException('Unknown dropdown option action.');
            }
        } catch (Throwable $exception) {
            $_SESSION['admin_error'] = $exception->getMessage();
        }

        header('Location: ' . buildUrl('admin/registration_options.php'));
        exit;
    }

    $options = getRegistrationOptions($pdo, false);
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

$csrf = htmlspecialchars((string) $_SESSION['admin_registration_options_csrf'], ENT_QUOTES, 'UTF-8');

ob_start();
?>
<div class="admin-registration-page">
    <?php if ($success !== ''): ?>
        <div class="alert success admin-registration-message"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="alert error admin-registration-message"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <section class="feedback-panel">
        <h2>Registration Dropdown Options</h2>
        <p class="small">Manage the choices shown on registration forms. Disabled options remain in the list but are hidden from new registrations.</p>

        <form method="post" class="registration-option-form">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
            <input type="hidden" name="action" value="add_option">

            <label>
                Dropdown
                <select name="option_group">
                    <?php foreach ($optionGroups as $group => $label): ?>
                        <option value="<?php echo htmlspecialchars($group, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                New value
                <input name="option_value" maxlength="150" required>
            </label>
            <label>
                Order
                <input type="number" name="sort_order" value="0">
            </label>
            <button type="submit">Add Option</button>
        </form>

        <?php foreach ($options as $group => $values): ?>
            <h3><?php echo htmlspecialchars($optionGroups[$group] ?? ucwords(str_replace('_', ' ', $group)), ENT_QUOTES, 'UTF-8'); ?></h3>
            <div class="registration-option-list">
                <?php foreach ($values as $option): ?>
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf; ?>">
                        <input type="hidden" name="action" value="toggle_option">
                        <input type="hidden" name="option_id" value="<?php echo (int) $option['option_id']; ?>">
                        <span><?php echo htmlspecialchars((string) $option['option_value'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <button class="button-secondary" type="submit"><?php echo !empty($option['is_active']) ? 'Disable' : 'Enable'; ?></button>
                    </form>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </section>
</div>
<?php
$content = (string) ob_get_clean();

renderAdminLayout('Registration Dropdown Options', $content, [
    'current_path' => 'registration_options',
    'page_heading' => 'Registration Dropdown Options',
]);
