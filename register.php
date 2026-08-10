<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/public_layout.php';
require_once __DIR__ . '/admin/registration_admin_funcs.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function ensureEventRegistrationsTable(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS event_registrations (
            registration_id INT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            salutation VARCHAR(20) NOT NULL,
            name VARCHAR(255) NOT NULL,
            designation VARCHAR(100),
            teacher_post VARCHAR(100),
            role VARCHAR(100),
            photograph_name VARCHAR(255),
            photograph_path VARCHAR(500),
            institution_name VARCHAR(255),
            affiliation_number VARCHAR(100),
            institution_level VARCHAR(100),
            experience VARCHAR(100),
            mobile VARCHAR(20),
            whatsapp_number VARCHAR(20),
            official_email VARCHAR(255),
            institution_address TEXT,
            city VARCHAR(100),
            district VARCHAR(100),
            state VARCHAR(100),
            country VARCHAR(100),
            declaration_accepted TINYINT(1) DEFAULT 0,
            qr_code_path VARCHAR(500),
            pass_code CHAR(5) NULL,
            approval_status ENUM('pending', 'approved', 'denied') NOT NULL DEFAULT 'pending',
            reviewed_at DATETIME NULL,
            reviewed_by VARCHAR(255) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_event_id (event_id)
        )
SQL);

    try {
        $pdo->exec("ALTER TABLE event_registrations ADD COLUMN qr_code_path VARCHAR(500)");
    } catch (PDOException $exception) {
        if (strpos($exception->getMessage(), 'Duplicate column name') === false && strpos($exception->getMessage(), 'already exists') === false) {
            throw $exception;
        }
    }

    try {
        $pdo->exec("ALTER TABLE event_registrations ADD COLUMN country VARCHAR(100) AFTER state");
    } catch (PDOException $exception) {
        if (strpos($exception->getMessage(), 'Duplicate column name') === false && strpos($exception->getMessage(), 'already exists') === false) {
            throw $exception;
        }
    }

    try {
        $pdo->exec("ALTER TABLE event_registrations ADD COLUMN pass_code CHAR(5) NULL AFTER qr_code_path");
    } catch (PDOException $exception) {
        if (strpos($exception->getMessage(), 'Duplicate column name') === false && strpos($exception->getMessage(), 'already exists') === false) {
            throw $exception;
        }
    }

    try {
        $pdo->exec("ALTER TABLE event_registrations ADD UNIQUE INDEX uq_event_pass_code (event_id, pass_code)");
    } catch (PDOException $exception) {
        if (strpos($exception->getMessage(), 'Duplicate key name') === false && strpos($exception->getMessage(), 'already exists') === false) {
            throw $exception;
        }
    }
}

function getActiveEvent(PDO $pdo): ?array
{
    $statement = $pdo->prepare(<<<'SQL'
        SELECT e.*
        FROM events e
        WHERE e.registration_required = 1
          AND e.event_status = 'Open'
          AND (e.end_date IS NULL OR e.end_date >= CURDATE())
        ORDER BY e.start_date ASC, e.event_id ASC
        LIMIT 1
SQL);
    $statement->execute();
    $event = $statement->fetch();
    return $event ?: null;
}

function getEventScheduleForToday(PDO $pdo, int $eventId): ?array
{
    $statement = $pdo->prepare(<<<'SQL'
        SELECT *
        FROM event_schedules
        WHERE event_id = :event_id
          AND schedule_start_date <= CURDATE()
          AND (schedule_end_date IS NULL OR schedule_end_date >= CURDATE())
        ORDER BY schedule_start_date ASC, schedule_id ASC
        LIMIT 1
SQL);
    $statement->execute([':event_id' => $eventId]);
    $schedule = $statement->fetch();
    return $schedule ?: null;
}

function getEventById(PDO $pdo, int $eventId): ?array
{
    $statement = $pdo->prepare(<<<'SQL'
        SELECT *
        FROM events
        WHERE event_id = :event_id
          AND registration_required = 1
          AND event_status = 'Open'
          AND (end_date IS NULL OR end_date >= CURDATE())
        LIMIT 1
SQL);
    $statement->execute([':event_id' => $eventId]);
    $event = $statement->fetch();
    return $event ?: null;
}

function getRegisterableEvents(PDO $pdo): array
{
    $statement = $pdo->prepare(<<<'SQL'
        SELECT *
        FROM events
        WHERE registration_required = 1
          AND event_status = 'Open'
          AND (end_date IS NULL OR end_date >= CURDATE())
        ORDER BY start_date ASC, event_title ASC
SQL);
    $statement->execute();
    return $statement->fetchAll();
}

function getOpenEventSchedules(PDO $pdo): array
{
    $statement = $pdo->prepare(<<<'SQL'
        SELECT es.*, e.event_title, e.event_code, e.event_status
        FROM event_schedules es
        JOIN events e ON e.event_id = es.event_id
        WHERE e.event_status = 'Open'
          AND (es.schedule_end_date IS NULL OR es.schedule_end_date >= CURDATE())
        ORDER BY es.schedule_start_date ASC, es.schedule_end_date ASC, es.schedule_id ASC
SQL);
    $statement->execute();
    return $statement->fetchAll();
}

function saveRegistrationPhotograph(array $file, int $eventId, int $registrationId, string $registrantName): array
{
    $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError === UPLOAD_ERR_NO_FILE) {
        return ['name' => '', 'path' => '', 'absolute_path' => ''];
    }

    if ($uploadError !== UPLOAD_ERR_OK) {
        $uploadMessages = [
            UPLOAD_ERR_INI_SIZE => 'The photograph exceeds the server upload limit.',
            UPLOAD_ERR_FORM_SIZE => 'The photograph is too large.',
            UPLOAD_ERR_PARTIAL => 'The photograph was only partially uploaded. Please try again.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server upload directory is unavailable.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not save the photograph.',
            UPLOAD_ERR_EXTENSION => 'The photograph upload was stopped by a server extension.',
        ];
        throw new InvalidArgumentException($uploadMessages[$uploadError] ?? 'The photograph could not be uploaded.');
    }

    $temporaryPath = (string) ($file['tmp_name'] ?? '');
    $originalName = basename((string) ($file['name'] ?? 'photograph'));
    $fileSize = (int) ($file['size'] ?? 0);

    if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
        throw new InvalidArgumentException('The photograph upload is invalid. Please select the file again.');
    }

    if ($fileSize <= 0 || $fileSize > 5 * 1024 * 1024) {
        throw new InvalidArgumentException('The photograph must be smaller than 5 MB.');
    }

    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $fileInfo->file($temporaryPath);

    if (!isset($allowedTypes[$mimeType]) || getimagesize($temporaryPath) === false) {
        throw new InvalidArgumentException('Please upload a valid JPG, PNG, or WebP photograph.');
    }

    $uploadDirectory = __DIR__ . '/assets/images/Registrations';
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
        throw new RuntimeException('The photograph storage directory could not be created.');
    }

    $safeName = preg_replace('/[^A-Za-z0-9]+/', '-', trim($registrantName));
    $safeName = trim((string) $safeName, '-');
    if ($safeName === '') {
        $safeName = 'Participant';
    }

    $uniqueName = $eventId . '-' . $registrationId . '-' . $safeName . '.' . $allowedTypes[$mimeType];

    $absolutePath = $uploadDirectory . '/' . $uniqueName;
    if (!move_uploaded_file($temporaryPath, $absolutePath)) {
        throw new RuntimeException('The photograph could not be saved. Please try again.');
    }

    return [
        'name' => $uniqueName,
        'original_name' => $originalName,
        'path' => 'assets/images/Registrations/' . $uniqueName,
        'absolute_path' => $absolutePath,
    ];
}

function updateRegistrationPhotograph(PDO $pdo, int $registrationId, array $photograph): void
{
    $statement = $pdo->prepare(
        'UPDATE event_registrations
         SET photograph_name = :photograph_name, photograph_path = :photograph_path
         WHERE registration_id = :registration_id'
    );
    $statement->execute([
        ':photograph_name' => (string) ($photograph['name'] ?? ''),
        ':photograph_path' => (string) ($photograph['path'] ?? ''),
        ':registration_id' => $registrationId,
    ]);
}

function saveEventRegistration(PDO $pdo, array $data): int
{
    $payload = [
        ':event_id' => (int) ($data['event_id'] ?? 0),
        ':salutation' => trim((string) ($data['salutation'] ?? '')),
        ':name' => trim((string) ($data['name'] ?? '')),
        ':designation' => trim((string) ($data['designation'] ?? '')),
        ':teacher_post' => trim((string) ($data['teacher_post'] ?? '')),
        ':role' => trim((string) ($data['role'] ?? '')),
        ':photograph_name' => trim((string) ($data['photograph_name'] ?? '')),
        ':photograph_path' => trim((string) ($data['photograph_path'] ?? '')),
        ':institution_name' => trim((string) ($data['institution_name'] ?? '')),
        ':affiliation_number' => trim((string) ($data['affiliation_number'] ?? '')),
        ':institution_level' => trim((string) ($data['institution_level'] ?? '')),
        ':experience' => trim((string) ($data['experience'] ?? '')),
        ':mobile' => trim((string) ($data['mobile'] ?? '')),
        ':whatsapp_number' => trim((string) ($data['whatsapp_number'] ?? '')),
        ':official_email' => trim((string) ($data['official_email'] ?? '')),
        ':institution_address' => trim((string) ($data['institution_address'] ?? '')),
        ':city' => trim((string) ($data['city'] ?? '')),
        ':district' => trim((string) ($data['district'] ?? '')),
        ':state' => trim((string) ($data['state'] ?? '')),
        ':country' => trim((string) ($data['country'] ?? '')),
        ':declaration_accepted' => !empty($data['declaration_accepted']) ? 1 : 0,
    ];

    if ($payload[':name'] === '' || $payload[':event_id'] <= 0 || $payload[':mobile'] === '' || $payload[':official_email'] === '') {
        throw new InvalidArgumentException('Please fill in the required fields: name, mobile, official email, and event selection.');
    }
    if (!filter_var($payload[':official_email'], FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Please enter a valid official email address.');
    }
    $experience = $payload[':experience'];
    if ($experience !== '' && (!ctype_digit($experience) || (int) $experience > 60)) {
        throw new InvalidArgumentException('Experience must be a whole number between 0 and 60.');
    }
    foreach ([':mobile' => 'Mobile number', ':whatsapp_number' => 'WhatsApp number'] as $field => $label) {
        $number = $payload[$field];
        if ($number === '' && $field === ':whatsapp_number') continue;
        $normalized = preg_replace('/[\s\-()]/', '', $number);
        if (!preg_match('/^\+?[0-9]{7,15}$/', (string) $normalized)) {
            throw new InvalidArgumentException($label . ' must contain 7 to 15 digits and may begin with +.');
        }
    }
    if ($payload[':declaration_accepted'] !== 1) {
        throw new InvalidArgumentException('Please accept the declaration before submitting.');
    }

    $statement = $pdo->prepare(<<<'SQL'
        INSERT INTO event_registrations (
            event_id, salutation, name, designation, teacher_post, role, photograph_name, photograph_path,
            institution_name, affiliation_number, institution_level, experience, mobile, whatsapp_number,
            official_email, institution_address, city, district, state, country, declaration_accepted
        ) VALUES (
            :event_id, :salutation, :name, :designation, :teacher_post, :role, :photograph_name, :photograph_path,
            :institution_name, :affiliation_number, :institution_level, :experience, :mobile, :whatsapp_number,
            :official_email, :institution_address, :city, :district, :state, :country, :declaration_accepted
        )
SQL);
    $statement->execute($payload);
    return (int) $pdo->lastInsertId();
}

$pdo = null;
$event = null;
$eventSchedule = null;
$events = [];
$selectedEventId = 0;
$errorMessage = '';
$successMessage = '';
$registrationOptions = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedEventId = (int) ($_POST['event_id'] ?? 0);
} else {
    $selectedEventId = (int) ($_GET['event_id'] ?? 0);
}

try {
    $pdo = createDbConnection();
    ensureEventRegistrationsTable($pdo);
    ensureAdminRegistrationTables($pdo);
    $registrationOptions = getRegistrationOptions($pdo, true);
    $events = getRegisterableEvents($pdo);

    if ($selectedEventId > 0) {
        $event = getEventById($pdo, $selectedEventId);
    }

    if ($event) {
        $eventSchedule = getEventScheduleForToday($pdo, (int) $event['event_id']);
    }

    $openSchedules = getOpenEventSchedules($pdo);
} catch (PDOException $exception) {
    $errorMessage = 'Database error: ' . $exception->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $savedPhotograph = ['absolute_path' => ''];
    try {
        $registrationData = $_POST;
        $registrationData['photograph_name'] = '';
        $registrationData['photograph_path'] = '';

        $pdo->beginTransaction();
        $registrationId = saveEventRegistration($pdo, $registrationData);
        $savedPhotograph = saveRegistrationPhotograph(
            $_FILES['photograph'] ?? [],
            (int) ($registrationData['event_id'] ?? 0),
            $registrationId,
            (string) ($registrationData['name'] ?? '')
        );
        if ($savedPhotograph['path'] !== '') {
            updateRegistrationPhotograph($pdo, $registrationId, $savedPhotograph);
        }
        $pdo->commit();

        $eventName = trim((string) ($event['event_title'] ?? 'the selected event'));
        $eventStartDate = !empty($event['start_date'])
            ? date('d M, Y', strtotime((string) $event['start_date']))
            : '';
        $eventEndDate = !empty($event['end_date'])
            ? date('d M, Y', strtotime((string) $event['end_date']))
            : '';
        $eventDates = $eventStartDate;
        if ($eventEndDate !== '' && $eventEndDate !== $eventStartDate) {
            $eventDates .= ' to ' . $eventEndDate;
        }

        $mobile = trim((string) ($registrationData['mobile'] ?? ''));
        $whatsApp = trim((string) ($registrationData['whatsapp_number'] ?? ''));
        $email = trim((string) ($registrationData['official_email'] ?? ''));

        $successMessage = 'Thanks for registering. You have registered for '
            . $eventName
            . ($eventDates !== '' ? ' from ' . $eventDates : '')
            . '. Further communication will be sent to your provided Mobile: '
            . $mobile
            . ', WhatsApp: '
            . ($whatsApp !== '' ? $whatsApp : 'Not provided')
            . ', and Email: '
            . $email
            . '. Reference ID: '
            . $registrationId
            . '.';
    } catch (PDOException $exception) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($savedPhotograph['absolute_path'] !== '' && is_file($savedPhotograph['absolute_path'])) {
            unlink($savedPhotograph['absolute_path']);
        }
        $errorMessage = 'Unable to save registration: ' . $exception->getMessage();
    } catch (InvalidArgumentException | RuntimeException $exception) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($savedPhotograph['absolute_path'] !== '' && is_file($savedPhotograph['absolute_path'])) {
            unlink($savedPhotograph['absolute_path']);
        }
        $errorMessage = $exception->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register for Event</title>
    <link rel="stylesheet"
        href="<?php echo htmlspecialchars(buildUrl('assets/css/styles.css'), ENT_QUOTES, 'UTF-8'); ?>">
</head>

<body class="public-page register-page">
    <?php renderPublicHeader('register'); ?>
    <main class="content-panel public-register-content">
        <form method="get" class="event-select-form">
            <table class="event-select-table">
                <tr>
                    <td class="event-title-column">
                        <h1>Register for Event</h1>
                    </td>

                    <td class="event-label-column">
                        <label for="event_id">
                            Choose event to register
                        </label>
                    </td>

                    <td class="event-select-column">
                        <select id="event_id" name="event_id">
                            <option value="" <?php echo $selectedEventId <= 0 ? 'selected' : ''; ?>>Select an Event</option>

                            <?php foreach ($events as $evt): ?>
                                <?php
                                $eventId = (int) ($evt['event_id'] ?? 0);

                                $eventLabel = htmlspecialchars(
                                    (string) ($evt['event_title'] ?? '') .
                                    ' (' . (string) ($evt['event_code'] ?? '') . ')',
                                    ENT_QUOTES,
                                    'UTF-8'
                                );
                                ?>

                                <option value="<?php echo $eventId; ?>" <?php echo ((int) $selectedEventId === $eventId)
                                       ? 'selected'
                                       : ''; ?>>
                                    <?php echo $eventLabel; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </table>
        </form>

        <?php if ($errorMessage !== ''): ?>
            <div class="alert error"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($successMessage !== ''): ?>
            <div class="alert success"><?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if (!$event): ?>
            <div class="schedule-note event-selection-prompt">
                Select an Event from the dropdown to view its details and registration form.
            </div>
        <?php else: ?>
            <div class="event-box">

                <div class="detail-cards">
                    <?php
                    $escape = static function ($value): string {
                        return htmlspecialchars(
                            (string) ($value ?? ''),
                            ENT_QUOTES,
                            'UTF-8'
                        );
                    };

                    $eventStatus = (string) ($event['event_status'] ?? 'Open');

                    $statusClass = strtolower(
                        preg_replace('/[^a-zA-Z0-9]+/', '-', $eventStatus)
                    );

                    $startDate = $escape($event['start_date'] ?? '');
                    $endDate = $escape($event['end_date'] ?? '');

                    $eventDate = $startDate;

                    if ($endDate !== '') {
                        $eventDate .= ' to ' . $endDate;
                    }
                    ?>

                    <div class="event-detail-table-wrapper">
                        <table class="event-detail-table">
                            <tr>
                                <th colspan=4>Event Details
                                    (<strong>Registration Window:</strong>
                                    <?php if (!empty($eventSchedule)): ?>
                                        <?php echo htmlspecialchars(date('d M, y', strtotime($eventSchedule['schedule_start_date'])), ENT_QUOTES, 'UTF-8'); ?>
                                        to
                                        <?php echo htmlspecialchars(!empty($eventSchedule['schedule_end_date']) ? date('d M, y', strtotime($eventSchedule['schedule_end_date'])) : 'ongoing', ENT_QUOTES, 'UTF-8'); ?>
                                    <?php else: ?>
                                        Closed
                                    <?php endif; ?>)
                                </th>
                            </tr>
                            <tr class="field-heading">
                                <th>Title</th>
                                <th>Theme</th>
                                <th>Type</th>
                                <th>Status</th>
                            </tr>

                            <tr>
                                <td>
                                    <?php echo $escape($event['event_title'] ?? ''); ?>

                                </td>

                                <td>
                                    <?php echo $escape($event['event_theme'] ?? ''); ?>
                                </td>

                                <td>
                                    <?php echo $escape($event['event_type'] ?? ''); ?>
                                </td>

                                <td>
                                    <span class="event-status event-status-<?php echo $statusClass; ?>">
                                        <?php echo $escape($eventStatus); ?>
                                    </span>
                                </td>
                            </tr>

                            <tr class="field-heading">
                                <th colspan="2">Date(s)</th>
                                <th colspan="2">Venue</th>
                            </tr>

                            <tr>
                                <td colspan="2">
                                    <?php echo $eventDate; ?>
                                </td>

                                <td colspan="2">
                                    <?php echo $escape($event['venue_name'] ?? ''); ?>
                                </td>
                            </tr>

                        </table>
                    </div>
                </div>
                <div class="detail-card">
                    <h3>Description</h3>
                    <p><?php echo htmlspecialchars((string) ($event['event_description'] ?? 'No description available.'), ENT_QUOTES, 'UTF-8'); ?>
                    </p>
                </div>
                <?php if (empty($eventSchedule)): ?>
                    <div class="schedule-note">Registration is currently closed for this event because there is no active registration schedule today.</div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($event && !empty($eventSchedule)): ?>
            <form method="post" enctype="multipart/form-data" class="registration-form">
                <input type="hidden" name="event_id" value="<?php echo (int) $event['event_id']; ?>">

                <div class="registration-table-wrap">
                <table class="registration-table">
                    <tbody>
                    <tr class="form-section-heading">
                        <th colspan="12">Participant Details</th>
                    </tr>
                    <tr>
                    <td colspan="4">
                        <label for="salutation">Salutation</label>
                        <select id="salutation" name="salutation">
                            <option value="">Select salutation</option>
                            <?php foreach ($registrationOptions['salutation'] ?? [] as $option): ?><option value="<?php echo htmlspecialchars($option['option_value'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($option['option_value'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?>
                        </select>
                    </td>
                    <td colspan="4">
                        <label for="name">Name</label>
                        <input id="name" name="name" type="text" required>
                    </td>
                    <td colspan="4">
                        <label for="designation">Designation</label>
                        <select id="designation" name="designation">
                            <option value="">Select designation</option>
                            <?php foreach ($registrationOptions['designation'] ?? [] as $option): ?><option value="<?php echo htmlspecialchars($option['option_value'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($option['option_value'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?>
                        </select>
                    </td>
                    </tr>
                    <tr>
                    <td colspan="2">
                        <label for="teacher_post">Post (if teacher)</label>
                        <select id="teacher_post" name="teacher_post">
                            <option value="">Select post</option>
                            <?php foreach ($registrationOptions['teacher_post'] ?? [] as $option): ?><option value="<?php echo htmlspecialchars($option['option_value'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($option['option_value'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?>
                        </select>
                    </td>
                    <td colspan="2">
                        <label for="role">Role</label>
                        <select id="role" name="role">
                            <option value="">Select role</option>
                            <?php foreach ($registrationOptions['role'] ?? [] as $option): ?><option value="<?php echo htmlspecialchars($option['option_value'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($option['option_value'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?>
                        </select>
                    </td>
                    <td colspan="2">
                        <label for="experience">Experience</label>
                        <input id="experience" name="experience" type="number" min="0" max="60" step="1" inputmode="numeric" placeholder="Years">
                    </td>
                    <td colspan="6">
                        <label for="photograph">Photograph</label>
                        <div class="photograph-input-row">
                            <input id="photograph" name="photograph" type="file"
                                accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                            <button type="button" id="open_photo_camera" class="photo-camera-button">Take Photo</button>
                        </div>
                        <img id="photograph_preview" class="photograph-preview" alt="Selected photograph preview" hidden>
                        <span class="small">JPG, PNG, or WebP; maximum file size 5 MB.</span>
                    </td>
                    </tr>

                    <tr class="form-section-heading">
                        <th colspan="12">Institution Details</th>
                    </tr>
                    <tr>
                    <td colspan="4">
                        <label for="institution_name">Institution Name</label>
                        <input id="institution_name" name="institution_name" type="text">
                    </td>
                    <td colspan="4">
                        <label for="affiliation_number">Institution Affiliation Number</label>
                        <input id="affiliation_number" name="affiliation_number" type="text">
                    </td>
                    <td colspan="4">
                        <label for="institution_level">Institution Level</label>
                        <select id="institution_level" name="institution_level">
                            <option value="">Select institution level</option>
                            <?php foreach ($registrationOptions['institution_level'] ?? [] as $option): ?><option value="<?php echo htmlspecialchars($option['option_value'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($option['option_value'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?>
                        </select>
                    </td>
                    </tr>

                    <tr class="form-section-heading">
                        <th colspan="12">Contact Details</th>
                    </tr>
                    <tr>
                    <td colspan="4">
                        <label for="mobile">Mobile</label>
                        <input id="mobile" name="mobile" type="tel" required inputmode="tel" minlength="7" maxlength="20" pattern="\+?[0-9\s()\-]{7,20}" title="Enter 7 to 15 digits; spaces, brackets, hyphens, and a leading + are allowed.">
                    </td>
                    <td colspan="4">
                        <label for="whatsapp_number">WhatsApp Number</label>
                        <input id="whatsapp_number" name="whatsapp_number" type="tel" inputmode="tel" minlength="7" maxlength="20" pattern="\+?[0-9\s()\-]{7,20}" title="Enter 7 to 15 digits; spaces, brackets, hyphens, and a leading + are allowed.">
                    </td>
                    <td colspan="4">
                        <label for="official_email">Official Email</label>
                        <input id="official_email" name="official_email" type="email" maxlength="255" required autocomplete="email">
                    </td>
                    </tr>

                    <tr class="form-section-heading">
                        <th colspan="12">Institution Address</th>
                    </tr>
                    <tr>
                    <td colspan="12">
                        <label for="institution_address">Address of Institution</label>
                        <textarea id="institution_address" name="institution_address"></textarea>
                    </td>
                    </tr>
                    <tr>
                    <td colspan="4">
                        <label for="city">City</label>
                        <input id="city" name="city" type="text">
                    </td>
                    <td colspan="4">
                        <label for="state">State</label>
                        <select id="state" name="state">
                            <option value="">Select state</option>
                            <?php foreach ($registrationOptions['state'] ?? [] as $option): ?><option value="<?php echo htmlspecialchars($option['option_value'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($option['option_value'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?>
                        </select>
                    </td>
                    <td colspan="4">
                        <label for="country">Country</label>
                        <select id="country" name="country"><option value="">Select country</option><?php foreach ($registrationOptions['country'] ?? [] as $option): ?><option value="<?php echo htmlspecialchars($option['option_value'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($option['option_value'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select>
                    </td>
                    </tr>
                    </tbody>
                </table>
                </div>

                <label class="declaration-field">
                    <input type="checkbox" name="declaration_accepted" value="1" required>
                    I accept the declaration.
                </label>

                <p class="small">The form stores the submitted registration details in the database and records
                    created/updated timestamps.</p>
                <button type="submit">Submit Registration</button>
            </form>
            <dialog id="photo_camera_dialog" class="photo-camera-dialog">
                <div class="photo-camera-content">
                    <h2>Take Photograph</h2>
                    <video id="photo_camera_video" autoplay playsinline muted></video>
                    <canvas id="photo_camera_canvas" hidden></canvas>
                    <p id="photo_camera_message" class="small">Position your face inside the camera view.</p>
                    <div class="photo-camera-actions">
                        <button type="button" id="capture_photo">Capture</button>
                        <button type="button" id="close_photo_camera" class="button-secondary">Cancel</button>
                    </div>
                </div>
            </dialog>
        <?php endif; ?>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var eventSelect = document.getElementById('event_id');
                var main = document.querySelector('.public-register-content');
                if (!eventSelect || !main || eventSelect.dataset.ajaxBound === '1') return;
                eventSelect.dataset.ajaxBound = '1';

                eventSelect.addEventListener('change', async function () {
                    var eventId = eventSelect.value;
                    if (!eventId) return;
                    eventSelect.disabled = true;
                    main.classList.add('event-ajax-loading');
                    try {
                        var response = await fetch(<?php echo json_encode(buildUrl('register.php')); ?> + '?event_id=' + encodeURIComponent(eventId) + '&ajax_view=1', {
                            headers: { 'X-Requested-With': 'XMLHttpRequest' }
                        });
                        if (!response.ok) throw new Error('Unable to load event details.');
                        var html = await response.text();
                        var parsed = new DOMParser().parseFromString(html, 'text/html');
                        var incomingMain = parsed.querySelector('.public-register-content');
                        var incomingEventBox = incomingMain && incomingMain.querySelector('.event-box');
                        var currentEventBox = main.querySelector('.event-box');
                        var selectionPrompt = main.querySelector('.event-selection-prompt');
                        if (incomingEventBox && currentEventBox) {
                            currentEventBox.replaceWith(incomingEventBox);
                        } else if (incomingEventBox) {
                            (selectionPrompt || main.querySelector(':scope > script')).before(incomingEventBox);
                        }
                        selectionPrompt?.remove();

                        main.querySelectorAll(':scope > .schedule-note, :scope > .schedule-list').forEach(function (element) { element.remove(); });
                        var currentForm = main.querySelector('.registration-form');
                        var incomingForm = incomingMain && incomingMain.querySelector('.registration-form');
                        var incomingDialog = incomingMain && incomingMain.querySelector('#photo_camera_dialog');
                        var insertedForm = false;
                        if (!incomingForm) {
                            currentForm?.remove();
                            main.querySelector('#photo_camera_dialog')?.remove();
                            currentForm = null;
                        } else if (!currentForm) {
                            var insertionPoint = main.querySelector(':scope > script');
                            main.insertBefore(incomingForm, insertionPoint);
                            currentForm = incomingForm;
                            insertedForm = true;
                            if (incomingDialog) main.insertBefore(incomingDialog, insertionPoint);
                        }
                        var currentEventInput = currentForm && currentForm.querySelector('input[name="event_id"]');
                        if (currentEventInput) currentEventInput.value = eventId;
                        var pageScript = main.querySelector(':scope > script');

                        incomingMain?.querySelectorAll(':scope > .schedule-note, :scope > .schedule-list').forEach(function (element) {
                            main.insertBefore(element, currentForm || pageScript);
                        });
                        history.replaceState(null, '', <?php echo json_encode(buildUrl('register.php')); ?> + '?event_id=' + encodeURIComponent(eventId));
                        if (insertedForm) document.dispatchEvent(new Event('DOMContentLoaded'));
                    } catch (error) {
                        var alert = document.createElement('div');
                        alert.className = 'alert error';
                        alert.textContent = error.message || 'Unable to load event details.';
                        currentEventBox?.before(alert);
                    } finally {
                        eventSelect.disabled = false;
                        main.classList.remove('event-ajax-loading');
                    }
                });
            });

            document.addEventListener('DOMContentLoaded', function () {
                var searchInput = document.getElementById('event_search');
                var eventIdInput = document.getElementById('event_id');
                var results = Array.from(document.querySelectorAll('.event-result-item'));
                if (!searchInput || !eventIdInput || results.length === 0) return;

                function updateSelected() {
                    results.forEach(function (item) {
                        item.classList.toggle('selected', item.dataset.eventId === eventIdInput.value);
                    });
                }

                function filterResults() {
                    var term = searchInput.value.toLowerCase().trim();
                    var firstVisible = null;
                    results.forEach(function (item) {
                        var label = item.dataset.eventLabel.toLowerCase();
                        var visible = term === '' || label.includes(term);
                        item.style.display = visible ? 'block' : 'none';
                        if (visible && !firstVisible) {
                            firstVisible = item;
                        }
                    });
                    if (firstVisible && !document.querySelector('.event-result-item.selected')) {
                        firstVisible.classList.add('selected');
                    }
                }

                results.forEach(function (item) {
                    item.addEventListener('click', function () {
                        eventIdInput.value = this.dataset.eventId;
                        this.form.submit();
                    });
                });

                searchInput.addEventListener('input', function () {
                    filterResults();
                });

                updateSelected();
            });

            document.addEventListener('DOMContentLoaded', function () {
                var photograph = document.getElementById('photograph');
                var openButton = document.getElementById('open_photo_camera');
                var dialog = document.getElementById('photo_camera_dialog');
                var video = document.getElementById('photo_camera_video');
                var canvas = document.getElementById('photo_camera_canvas');
                var captureButton = document.getElementById('capture_photo');
                var closeButton = document.getElementById('close_photo_camera');
                var message = document.getElementById('photo_camera_message');
                var preview = document.getElementById('photograph_preview');
                var cameraStream = null;

                if (!photograph || !openButton || !dialog) return;

                function stopCamera() {
                    if (cameraStream) cameraStream.getTracks().forEach(function (track) { track.stop(); });
                    cameraStream = null;
                    video.srcObject = null;
                }

                function showPreview(file) {
                    if (!file) return;
                    preview.src = URL.createObjectURL(file);
                    preview.hidden = false;
                }

                photograph.addEventListener('change', function () {
                    showPreview(photograph.files && photograph.files[0]);
                });

                openButton.addEventListener('click', async function () {
                    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                        photograph.setAttribute('capture', 'user');
                        photograph.click();
                        return;
                    }
                    try {
                        cameraStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false });
                        video.srcObject = cameraStream;
                        message.textContent = 'Position your face inside the camera view.';
                        dialog.showModal();
                    } catch (error) {
                        message.textContent = 'Camera access was unavailable. Please select a photograph instead.';
                        photograph.setAttribute('capture', 'user');
                        photograph.click();
                    }
                });

                captureButton.addEventListener('click', function () {
                    if (!video.videoWidth || !video.videoHeight) return;
                    var maxWidth = 1200;
                    var scale = Math.min(1, maxWidth / video.videoWidth);
                    canvas.width = Math.round(video.videoWidth * scale);
                    canvas.height = Math.round(video.videoHeight * scale);
                    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
                    canvas.toBlob(function (blob) {
                        if (!blob) return;
                        var file = new File([blob], 'camera-photograph.jpg', { type: 'image/jpeg', lastModified: Date.now() });
                        var transfer = new DataTransfer();
                        transfer.items.add(file);
                        photograph.files = transfer.files;
                        showPreview(file);
                        stopCamera();
                        dialog.close();
                    }, 'image/jpeg', 0.9);
                });

                closeButton.addEventListener('click', function () {
                    stopCamera();
                    dialog.close();
                });
                dialog.addEventListener('close', stopCamera);
                dialog.addEventListener('cancel', stopCamera);
            });
        </script>
    </main>
    <?php renderPublicFooter(); ?>
</body>

</html>
