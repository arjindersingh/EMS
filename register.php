<?php

require_once __DIR__ . '/config/database.php';

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
            declaration_accepted TINYINT(1) DEFAULT 0,
            qr_code_path VARCHAR(500),
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
}

function getActiveEvent(PDO $pdo): ?array
{
    $statement = $pdo->prepare(<<<'SQL'
        SELECT e.*
        FROM events e
        WHERE e.registration_required = 1
          AND e.start_date >= CURDATE()
          AND EXISTS (
              SELECT 1
              FROM event_schedules es
              WHERE es.event_id = e.event_id
                AND es.schedule_start_date <= CURDATE()
                AND (es.schedule_end_date IS NULL OR es.schedule_end_date >= CURDATE())
          )
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
        ':declaration_accepted' => !empty($data['declaration_accepted']) ? 1 : 0,
    ];

    if ($payload[':name'] === '' || $payload[':event_id'] <= 0 || $payload[':mobile'] === '' || $payload[':official_email'] === '') {
        throw new InvalidArgumentException('Please fill in the required fields: name, mobile, official email, and event selection.');
    }

    $statement = $pdo->prepare(<<<'SQL'
        INSERT INTO event_registrations (
            event_id, salutation, name, designation, teacher_post, role, photograph_name, photograph_path,
            institution_name, affiliation_number, institution_level, experience, mobile, whatsapp_number,
            official_email, institution_address, city, district, state, declaration_accepted
        ) VALUES (
            :event_id, :salutation, :name, :designation, :teacher_post, :role, :photograph_name, :photograph_path,
            :institution_name, :affiliation_number, :institution_level, :experience, :mobile, :whatsapp_number,
            :official_email, :institution_address, :city, :district, :state, :declaration_accepted
        )
SQL);
    $statement->execute($payload);
    return (int) $pdo->lastInsertId();
}

$pdo = null;
$event = null;
$eventSchedule = null;
$errorMessage = '';
$successMessage = '';

try {
    $pdo = createDbConnection();
    ensureEventRegistrationsTable($pdo);
    $event = getActiveEvent($pdo);
    if ($event) {
        $eventSchedule = getEventScheduleForToday($pdo, (int) $event['event_id']);
    }
} catch (PDOException $exception) {
    $errorMessage = 'Database error: ' . $exception->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $registrationId = saveEventRegistration($pdo, $_POST);
        $successMessage = 'Registration submitted successfully. Reference ID: ' . $registrationId;
    } catch (InvalidArgumentException $exception) {
        $errorMessage = $exception->getMessage();
    } catch (PDOException $exception) {
        $errorMessage = 'Unable to save registration: ' . $exception->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register for Event</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 2rem; line-height: 1.6; color: #222; }
        .card { max-width: 900px; margin: 0 auto; padding: 1.5rem 2rem; border: 1px solid #d0d7de; border-radius: 8px; background: #f8f9fa; }
        .alert { padding: 0.75rem 1rem; margin-bottom: 1rem; border-radius: 6px; }
        .alert.error { background: #ffe8e8; color: #9c1c1c; }
        .alert.success { background: #e8f7eb; color: #20653d; }
        label { display: block; margin-top: 0.8rem; font-weight: bold; }
        input[type="text"], input[type="email"], input[type="number"], input[type="tel"], textarea, select { width: 100%; padding: 0.7rem; box-sizing: border-box; margin: 0.35rem 0 0.8rem; }
        textarea { min-height: 100px; }
        button { padding: 0.65rem 1rem; cursor: pointer; }
        .event-box { background: #fff; border: 1px solid #d0d7de; padding: 1rem; border-radius: 6px; margin-bottom: 1rem; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 0.5rem 1rem; }
        .small { font-size: 0.95rem; color: #555; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Register for Event</h1>
        <?php if ($errorMessage !== ''): ?>
            <div class="alert error"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($successMessage !== ''): ?>
            <div class="alert success"><?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if ($event): ?>
            <div class="event-box">
                <h2>Event Details</h2>
                <p><strong>Title:</strong> <?php echo htmlspecialchars((string) ($event['event_title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                <p><strong>Code:</strong> <?php echo htmlspecialchars((string) ($event['event_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                <p><strong>Theme:</strong> <?php echo htmlspecialchars((string) ($event['event_theme'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                <p><strong>Type:</strong> <?php echo htmlspecialchars((string) ($event['event_type'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                <p><strong>Date:</strong> <?php echo htmlspecialchars((string) ($event['start_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> <?php echo $event['end_date'] ? 'to ' . htmlspecialchars((string) $event['end_date'], ENT_QUOTES, 'UTF-8') : ''; ?></p>
                <p><strong>Venue:</strong> <?php echo htmlspecialchars((string) ($event['venue_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                <p><strong>Description:</strong> <?php echo htmlspecialchars((string) ($event['event_description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
            </div>
        <?php else: ?>
            <div class="alert error">
                Registration is currently closed for this event.
                <?php if (!empty($eventSchedule)): ?>
                    The active schedule is from <?php echo htmlspecialchars((string) ($eventSchedule['schedule_start_date'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> to <?php echo htmlspecialchars((string) ($eventSchedule['schedule_end_date'] ?? 'ongoing'), ENT_QUOTES, 'UTF-8'); ?>.
                <?php else: ?>
                    No valid registration schedule is available for today.
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($event): ?>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="event_id" value="<?php echo (int) $event['event_id']; ?>">
                <div class="grid">
                    <div>
                        <label for="salutation">Salutation</label>
                        <select id="salutation" name="salutation">
                            <option value="Mr">Mr</option>
                            <option value="Ms">Ms</option>
                            <option value="Mrs">Mrs</option>
                            <option value="Dr">Dr</option>
                            <option value="Prof">Prof</option>
                        </select>
                    </div>
                    <div>
                        <label for="name">Name</label>
                        <input id="name" name="name" type="text" required>
                    </div>
                    <div>
                        <label for="designation">Designation</label>
                        <input id="designation" name="designation" type="text" placeholder="Principal, Teacher, Administrator">
                    </div>
                    <div>
                        <label for="teacher_post">Post (if teacher)</label>
                        <input id="teacher_post" name="teacher_post" type="text" placeholder="PGT, TGT, etc.">
                    </div>
                    <div>
                        <label for="role">Role</label>
                        <input id="role" name="role" type="text" placeholder="Delegate, Speaker, Panelist, Moderator">
                    </div>
                    <div>
                        <label for="photograph">Photograph</label>
                        <input id="photograph" name="photograph" type="file" accept="image/*">
                    </div>
                    <div>
                        <label for="institution_name">Institution Name</label>
                        <input id="institution_name" name="institution_name" type="text">
                    </div>
                    <div>
                        <label for="affiliation_number">Institution Affiliation Number</label>
                        <input id="affiliation_number" name="affiliation_number" type="text">
                    </div>
                    <div>
                        <label for="institution_level">Institution Level</label>
                        <input id="institution_level" name="institution_level" type="text" placeholder="Senior Secondary, Secondary, College, Primary">
                    </div>
                    <div>
                        <label for="experience">Experience</label>
                        <input id="experience" name="experience" type="text">
                    </div>
                    <div>
                        <label for="mobile">Mobile</label>
                        <input id="mobile" name="mobile" type="tel" required>
                    </div>
                    <div>
                        <label for="whatsapp_number">WhatsApp Number</label>
                        <input id="whatsapp_number" name="whatsapp_number" type="tel">
                    </div>
                    <div>
                        <label for="official_email">Official Email</label>
                        <input id="official_email" name="official_email" type="email" required>
                    </div>
                    <div>
                        <label for="institution_address">Address of Institution</label>
                        <textarea id="institution_address" name="institution_address"></textarea>
                    </div>
                    <div>
                        <label for="city">City</label>
                        <input id="city" name="city" type="text">
                    </div>
                    <div>
                        <label for="district">District</label>
                        <input id="district" name="district" type="text">
                    </div>
                    <div>
                        <label for="state">State</label>
                        <input id="state" name="state" type="text">
                    </div>
                </div>

                <label>
                    <input type="checkbox" name="declaration_accepted" value="1">
                    I accept the declaration.
                </label>

                <p class="small">The form stores the submitted registration details in the database and records created/updated timestamps.</p>
                <button type="submit">Submit Registration</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
