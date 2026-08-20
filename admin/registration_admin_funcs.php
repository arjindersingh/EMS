<?php

function ensureAdminRegistrationTables(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS registration_form_options (
            option_id INT AUTO_INCREMENT PRIMARY KEY,
            option_group VARCHAR(50) NOT NULL,
            option_value VARCHAR(150) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            UNIQUE KEY uq_registration_option (option_group, option_value)
        )
SQL);
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS special_registration_invitations (
            invitation_id INT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            candidate_name VARCHAR(255) NULL,
            email VARCHAR(255) NULL,
            whatsapp_number VARCHAR(30) NULL,
            access_token CHAR(64) NOT NULL UNIQUE,
            status ENUM('created','sent','completed','expired') NOT NULL DEFAULT 'created',
            expires_at DATETIME NULL,
            registration_id INT NULL,
            sent_at DATETIME NULL,
            completed_at DATETIME NULL,
            created_by VARCHAR(100) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_special_registration_event (event_id)
        )
SQL);
    $defaults = [
        'salutation' => ['Mr.', 'Ms.', 'Mrs.', 'Dr.', 'Prof.'],
        'designation' => ['Principal', 'Teacher', 'Administrator', 'Coordinator', 'Manager', 'Staff'],
        'teacher_post' => ['PGT', 'TGT', 'PRT', 'HOD', 'Vice Principal', 'Principal'],
        'role' => ['Delegate', 'Speaker', 'Panelist', 'Moderator', 'Volunteer', 'Organizer'],
        'institution_level' => ['Primary', 'Secondary', 'Senior Secondary', 'College', 'University', 'Other'],
        'state' => ['Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chhattisgarh', 'Goa', 'Gujarat', 'Haryana', 'Himachal Pradesh', 'Jharkhand', 'Karnataka', 'Kerala', 'Madhya Pradesh', 'Maharashtra', 'Manipur', 'Meghalaya', 'Mizoram', 'Nagaland', 'Odisha', 'Punjab', 'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana', 'Tripura', 'Uttar Pradesh', 'Uttarakhand', 'West Bengal', 'Delhi', 'Chandigarh'],
        'country' => ['India'],
    ];
    $insert = $pdo->prepare('INSERT IGNORE INTO registration_form_options (option_group, option_value, sort_order) VALUES (:group_name, :value, :sort_order)');
    foreach ($defaults as $group => $values) foreach ($values as $sort => $value) $insert->execute([':group_name' => $group, ':value' => $value, ':sort_order' => $sort + 1]);
}

function getRegistrationOptions(PDO $pdo, bool $activeOnly = true): array
{
    $rows = $pdo->query('SELECT * FROM registration_form_options' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY option_group, sort_order, option_value')->fetchAll() ?: [];
    $options = [];
    foreach ($rows as $row) $options[$row['option_group']][] = $row;
    return $options;
}

function saveAdministrativeRegistration(PDO $pdo, array $data): int
{
    $required = ['event_id', 'name', 'mobile', 'official_email'];
    foreach ($required as $field) if (trim((string) ($data[$field] ?? '')) === '') throw new InvalidArgumentException('Event, name, mobile, and official email are required.');
    if (!filter_var($data['official_email'], FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Enter a valid official email address.');
    $experience = trim((string) ($data['experience'] ?? ''));
    if ($experience !== '' && (!ctype_digit($experience) || (int) $experience > 60)) throw new InvalidArgumentException('Experience must be a whole number between 0 and 60.');
    foreach (['mobile' => 'Mobile number', 'whatsapp_number' => 'WhatsApp number'] as $field => $label) {
        $number = trim((string) ($data[$field] ?? ''));
        if ($field === 'whatsapp_number' && $number === '') continue;
        $normalized = preg_replace('/[\s\-()]/', '', $number);
        if (!preg_match('/^\+?[0-9]{7,15}$/', (string) $normalized)) throw new InvalidArgumentException($label . ' must contain 7 to 15 digits and may begin with +.');
    }
    $fields = ['event_id','salutation','name','designation','teacher_post','role','institution_name','affiliation_number','institution_level','experience','mobile','whatsapp_number','official_email','institution_address','city','district','state','country','declaration_accepted'];
    $declarationAccepted = administrativeRegistrationDeclarationValue($data['declaration_accepted'] ?? 1);
    $columns = implode(', ', $fields);
    $placeholders = implode(', ', array_map(static fn($field) => ':' . $field, $fields));
    $statement = $pdo->prepare("INSERT INTO event_registrations ({$columns}) VALUES ({$placeholders})");
    $params = [];
    foreach ($fields as $field) $params[':' . $field] = $field === 'event_id' ? (int) $data[$field] : ($field === 'declaration_accepted' ? $declarationAccepted : trim((string) ($data[$field] ?? '')));
    $statement->execute($params);
    return (int) $pdo->lastInsertId();
}

function administrativeRegistrationDeclarationValue(mixed $value): int
{
    if (is_bool($value)) return $value ? 1 : 0;
    $normalized = strtolower(trim((string) $value));
    if ($normalized === '' || in_array($normalized, ['1', 'true', 'yes', 'y', 'accepted', 'on'], true)) return 1;
    if (in_array($normalized, ['0', 'false', 'no', 'n', 'not accepted', 'off'], true)) return 0;
    throw new InvalidArgumentException('Declaration accepted must be Yes/No or 1/0.');
}

function saveAdministrativePhotograph(PDO $pdo, array $file, int $eventId, int $registrationId, string $name): void
{
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return;
    if ((int) ($file['error'] ?? -1) !== UPLOAD_ERR_OK || (int) ($file['size'] ?? 0) > 5 * 1024 * 1024) throw new InvalidArgumentException('The photograph upload failed or exceeds 5 MB.');
    $temporary = (string) ($file['tmp_name'] ?? '');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporary);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime]) || !is_uploaded_file($temporary) || getimagesize($temporary) === false) throw new InvalidArgumentException('Upload a valid JPG, PNG, or WebP photograph.');
    $directory = __DIR__ . '/../assets/images/Registrations';
    if (!is_dir($directory)) mkdir($directory, 0755, true);
    $safeName = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-') ?: 'Participant';
    $fileName = $eventId . '-' . $registrationId . '-' . $safeName . '.' . $extensions[$mime];
    if (!move_uploaded_file($temporary, $directory . '/' . $fileName)) throw new RuntimeException('Could not save the photograph.');
    $pdo->prepare('UPDATE event_registrations SET photograph_name = :name, photograph_path = :path WHERE registration_id = :id')->execute([':name' => $fileName, ':path' => 'assets/images/Registrations/' . $fileName, ':id' => $registrationId]);
}

function createSpecialRegistrationInvitation(PDO $pdo, int $eventId, string $name, string $email, string $whatsapp): array
{
    if ($eventId <= 0) throw new InvalidArgumentException('Select an event.');
    if ($email === '' && $whatsapp === '') throw new InvalidArgumentException('Enter an email or WhatsApp number.');
    $token = bin2hex(random_bytes(32));
    $statement = $pdo->prepare("INSERT INTO special_registration_invitations (event_id, candidate_name, email, whatsapp_number, access_token, expires_at, created_by) VALUES (:event_id, :name, :email, :whatsapp, :token, DATE_ADD(NOW(), INTERVAL 7 DAY), :username)");
    $statement->execute([':event_id' => $eventId, ':name' => $name, ':email' => $email, ':whatsapp' => $whatsapp, ':token' => $token, ':username' => $_SESSION['admin_username'] ?? null]);
    return ['invitation_id' => (int) $pdo->lastInsertId(), 'event_id' => $eventId, 'access_token' => $token];
}

function buildSpecialRegistrationLink(array $invitation): string
{
    return buildUrl('special_register.php') . '?' . http_build_query(['event_id' => (int) $invitation['event_id'], 'invitation_id' => (int) $invitation['invitation_id'], 'token' => (string) $invitation['access_token']]);
}

function renderAdministrativeRegistrationFields(array $options, array $values = []): void
{
    $select = static function (string $group, string $name, string $label) use ($options, $values): void { ?>
        <label><?php echo htmlspecialchars($label); ?><select name="<?php echo htmlspecialchars($name); ?>"><option value="">Select <?php echo htmlspecialchars(strtolower($label)); ?></option><?php foreach ($options[$group] ?? [] as $option): $value = $option['option_value']; ?><option value="<?php echo htmlspecialchars($value); ?>" <?php echo ($values[$name] ?? '') === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($value); ?></option><?php endforeach; ?></select></label>
    <?php }; ?>
    <div class="admin-registration-fields">
        <?php $select('salutation', 'salutation', 'Salutation'); ?>
        <label>Name<input name="name" value="<?php echo htmlspecialchars($values['name'] ?? ''); ?>" required></label>
        <?php $select('designation', 'designation', 'Designation'); ?>
        <?php $select('teacher_post', 'teacher_post', 'Post'); ?>
        <?php $select('role', 'role', 'Role'); ?>
        <label>Experience<input name="experience" type="number" min="0" max="60" step="1" inputmode="numeric" placeholder="Years"></label>
        <label>Photograph<input name="photograph" type="file" accept="image/jpeg,image/png,image/webp"></label>
        <label>Institution Name<input name="institution_name"></label>
        <label>Affiliation Number<input name="affiliation_number"></label>
        <?php $select('institution_level', 'institution_level', 'Institution Level'); ?>
        <label>Mobile<input name="mobile" type="tel" required inputmode="tel" minlength="7" maxlength="20" pattern="\+?[0-9\s()\-]{7,20}" title="Enter 7 to 15 digits; spaces, brackets, hyphens, and a leading + are allowed."></label>
        <label>WhatsApp<input name="whatsapp_number" type="tel" inputmode="tel" minlength="7" maxlength="20" pattern="\+?[0-9\s()\-]{7,20}" title="Enter 7 to 15 digits; spaces, brackets, hyphens, and a leading + are allowed." value="<?php echo htmlspecialchars($values['whatsapp_number'] ?? ''); ?>"></label>
        <label>Official Email<input name="official_email" type="email" maxlength="255" autocomplete="email" value="<?php echo htmlspecialchars($values['official_email'] ?? ''); ?>" required></label>
        <label class="wide">Institution Address<textarea name="institution_address"></textarea></label>
        <label>City<input name="city"></label><label>District<input name="district"></label>
        <?php $select('state', 'state', 'State'); ?><?php $select('country', 'country', 'Country'); ?>
    </div>
    <?php
}
