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

function ensureEventAttendanceTable(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS event_attendance (
            attendance_id INT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            registration_id INT NOT NULL,
            checked_in_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            checked_in_by VARCHAR(100) NULL,
            mode VARCHAR(20) NOT NULL DEFAULT 'qr',
            welcome_message TEXT NULL,
            whatsapp_sent TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_event_attendance_event (event_id),
            INDEX idx_event_attendance_registration (registration_id)
        )
SQL);
}

function isAjaxRequest(): bool
{
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function parseQrPayload(string $payload): array
{
    $parts = [];
    if ($payload === '') {
        return $parts;
    }

    if (str_contains($payload, '?')) {
        [$payload] = explode('?', $payload, 2);
    }

    foreach (explode('&', $payload) as $segment) {
        if ($segment === '') {
            continue;
        }
        [$key, $value] = array_pad(explode('=', $segment, 2), 2, '');
        $parts[urldecode($key)] = urldecode($value);
    }

    return $parts;
}

function getAllEvents(PDO $pdo): array
{
    $statement = $pdo->query('SELECT event_id, event_title, start_date FROM events ORDER BY start_date DESC, event_title ASC');
    return $statement ? $statement->fetchAll() : [];
}

function getEventById(PDO $pdo, int $eventId): ?array
{
    $statement = $pdo->prepare('SELECT event_id, event_title, start_date FROM events WHERE event_id = :event_id LIMIT 1');
    $statement->execute([':event_id' => $eventId]);
    $event = $statement->fetch();
    return $event ?: null;
}

function getRegistrationById(PDO $pdo, int $registrationId): ?array
{
    $statement = $pdo->prepare('SELECT registration_id, event_id, name, official_email, whatsapp_number FROM event_registrations WHERE registration_id = :registration_id LIMIT 1');
    $statement->execute([':registration_id' => $registrationId]);
    $registration = $statement->fetch();
    return $registration ?: null;
}

function findRegistrationsByName(PDO $pdo, int $eventId, string $searchTerm): array
{
    $term = '%' . trim($searchTerm) . '%';
    $statement = $pdo->prepare(<<<'SQL'
        SELECT registration_id, event_id, name, official_email, whatsapp_number
        FROM event_registrations
        WHERE event_id = :event_id
          AND name LIKE :name
        ORDER BY name ASC, registration_id ASC
        LIMIT 20
SQL);
    $statement->execute([':event_id' => $eventId, ':name' => $term]);
    return $statement->fetchAll() ?: [];
}

function getAttendanceRecord(PDO $pdo, int $eventId, int $registrationId): ?array
{
    $statement = $pdo->prepare('SELECT * FROM event_attendance WHERE event_id = :event_id AND registration_id = :registration_id ORDER BY attendance_id DESC LIMIT 1');
    $statement->execute([':event_id' => $eventId, ':registration_id' => $registrationId]);
    $attendance = $statement->fetch();
    return $attendance ?: null;
}

function getRecentAttendance(PDO $pdo, int $eventId): array
{
    $statement = $pdo->prepare(<<<'SQL'
        SELECT ea.*, er.name
        FROM event_attendance ea
        INNER JOIN event_registrations er ON er.registration_id = ea.registration_id
        WHERE ea.event_id = :event_id
        ORDER BY ea.checked_in_at DESC, ea.attendance_id DESC
        LIMIT 20
SQL);
    $statement->execute([':event_id' => $eventId]);
    return $statement->fetchAll() ?: [];
}

function buildWelcomeAnnouncement(array $registration, array $event): string
{
    $name = trim((string) ($registration['name'] ?? 'guest'));
    $eventTitle = trim((string) ($event['event_title'] ?? 'the event'));

    return 'Welcome ' . $name . '! We are delighted to have you with us at ' . $eventTitle . '. Please enjoy the event and make the most of your visit.';
}

function sendWhatsappCheckinMessage(PDO $pdo, array $registration, string $message): bool
{
    $to = trim((string) ($registration['whatsapp_number'] ?? ''));
    if ($to === '') {
        return false;
    }

    $apiUrl = trim((string) getSettingValue($pdo, 'whatsapp_api_url', ''));
    if ($apiUrl === '') {
        return false;
    }

    $payload = [
        'username' => trim((string) getSettingValue($pdo, 'whatsapp_username', '')),
        'password' => trim((string) getSettingValue($pdo, 'whatsapp_password', '')),
        'sender' => trim((string) getSettingValue($pdo, 'whatsapp_sender', '')),
        'to' => $to,
        'message' => $message,
    ];

    if (function_exists('curl_init')) {
        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_exec($ch);
        $error = curl_errno($ch);
        curl_close($ch);
        return $error === 0;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/json',
            'content' => json_encode($payload),
        ],
    ]);

    $result = @file_get_contents($apiUrl, false, $context);
    return $result !== false;
}

function recordAttendance(PDO $pdo, int $eventId, int $registrationId, string $mode, string $checkedInBy, string $welcomeMessage): array
{
    $existing = getAttendanceRecord($pdo, $eventId, $registrationId);
    if ($existing) {
        return [
            'success' => false,
            'already_checked_in' => true,
            'message' => 'This candidate is already checked in.',
            'attendance' => $existing,
        ];
    }

    $statement = $pdo->prepare(<<<'SQL'
        INSERT INTO event_attendance (event_id, registration_id, checked_in_by, mode, welcome_message, whatsapp_sent)
        VALUES (:event_id, :registration_id, :checked_in_by, :mode, :welcome_message, :whatsapp_sent)
SQL);
    $whatsappSent = 0;
    $registration = getRegistrationById($pdo, $registrationId);
    if ($registration && !empty($registration['whatsapp_number'])) {
        $whatsappSent = sendWhatsappCheckinMessage($pdo, $registration, $welcomeMessage) ? 1 : 0;
    }

    $statement->execute([
        ':event_id' => $eventId,
        ':registration_id' => $registrationId,
        ':checked_in_by' => $checkedInBy === '' ? null : $checkedInBy,
        ':mode' => $mode,
        ':welcome_message' => $welcomeMessage,
        ':whatsapp_sent' => $whatsappSent,
    ]);

    $attendanceId = (int) $pdo->lastInsertId();
    $attendance = getAttendanceRecord($pdo, $eventId, $registrationId);

    return [
        'success' => true,
        'already_checked_in' => false,
        'message' => 'Attendance marked successfully.',
        'attendance' => $attendance,
        'announcement' => $welcomeMessage,
        'whatsapp_sent' => $whatsappSent,
        'attendance_id' => $attendanceId,
    ];
}

$pdo = null;
$events = [];
$selectedEventId = 0;
$searchTerm = '';
$searchResults = [];
$recentAttendance = [];
$adminError = '';
$adminSuccess = '';
$announcement = '';
$checkedInName = '';

try {
    $pdo = createDbConnection();
    ensureSettingsTable($pdo);
    ensureEventAttendanceTable($pdo);
    $events = getAllEvents($pdo);
} catch (PDOException $exception) {
    $adminError = 'Database connection failed: ' . $exception->getMessage();
}

if (!empty($events) && $selectedEventId === 0) {
    $selectedEventId = (int) ($events[0]['event_id'] ?? 0);
}

if (isset($_GET['event']) && $pdo !== null) {
    $selectedEventId = (int) $_GET['event'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedEventId = (int) ($_POST['event_id'] ?? $selectedEventId);
    $searchTerm = trim((string) ($_POST['search_term'] ?? ''));
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'search_candidates' && $selectedEventId > 0 && $pdo !== null) {
        $searchResults = findRegistrationsByName($pdo, $selectedEventId, $searchTerm);
        if ($searchResults === []) {
            $adminError = 'No candidate matched your search.';
        } else {
            $adminSuccess = 'Found ' . count($searchResults) . ' matching candidate(s).';
        }
    } elseif ($action === 'mark_manual' && $selectedEventId > 0 && $pdo !== null) {
        $registrationId = (int) ($_POST['registration_id'] ?? 0);
        if ($registrationId > 0) {
            $registration = getRegistrationById($pdo, $registrationId);
            $event = getEventById($pdo, $selectedEventId);
            if ($registration && $event) {
                $announcement = buildWelcomeAnnouncement($registration, $event);
                $result = recordAttendance($pdo, $selectedEventId, $registrationId, 'manual', $_SESSION['admin_username'] ?? 'admin', $announcement);
                $checkedInName = trim((string) ($registration['name'] ?? ''));
                if ($result['success']) {
                    $adminSuccess = 'Checked in ' . $checkedInName . ' manually.';
                } else {
                    $adminError = $result['message'] ?? 'Unable to mark attendance.';
                }
            } else {
                $adminError = 'Candidate or event could not be found.';
            }
        } else {
            $adminError = 'Please choose a candidate first.';
        }
    } elseif ($action === 'mark_qr' && $selectedEventId > 0 && $pdo !== null) {
        $payload = trim((string) ($_POST['qr_payload'] ?? ''));
        $scanData = parseQrPayload($payload);
        $registrationId = (int) ($scanData['registration_id'] ?? 0);
        $eventIdFromScan = (int) ($scanData['event_id'] ?? 0);
        $eventIdToUse = $eventIdFromScan > 0 ? $eventIdFromScan : $selectedEventId;

        if ($registrationId > 0 && $eventIdToUse > 0) {
            $registration = getRegistrationById($pdo, $registrationId);
            $event = getEventById($pdo, $eventIdToUse);
            if ($registration && $event) {
                $announcement = buildWelcomeAnnouncement($registration, $event);
                $result = recordAttendance($pdo, $eventIdToUse, $registrationId, 'qr', $_SESSION['admin_username'] ?? 'admin', $announcement);
                $checkedInName = trim((string) ($registration['name'] ?? ''));
                if ($result['success']) {
                    $adminSuccess = 'Checked in ' . $checkedInName . ' via QR.';
                    $announcement = $result['announcement'];
                } else {
                    $adminError = $result['message'] ?? 'Unable to mark attendance.';
                }
            } else {
                $adminError = 'QR payload did not match a valid registration.';
            }
        } else {
            $adminError = 'QR payload was empty or invalid.';
        }
    }

    if ($selectedEventId > 0 && $pdo !== null) {
        $recentAttendance = getRecentAttendance($pdo, $selectedEventId);
    }

    if (isAjaxRequest()) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => $adminError === '' && $adminSuccess !== '',
            'message' => $adminSuccess !== '' ? $adminSuccess : $adminError,
            'announcement' => $announcement,
            'name' => $checkedInName,
        ]);
        exit;
    }
}

if ($selectedEventId > 0 && $pdo !== null) {
    $recentAttendance = getRecentAttendance($pdo, $selectedEventId);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Check-in</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 2rem; line-height: 1.6; color: #222; }
        .card { max-width: 1200px; margin: 0 auto; padding: 1.5rem 2rem; border: 1px solid #d0d7de; border-radius: 8px; background: #f8f9fa; }
        .alert { padding: 0.75rem 1rem; margin-bottom: 1rem; border-radius: 6px; }
        .alert.error { background: #ffe8e8; color: #9c1c1c; }
        .alert.success { background: #e8f7eb; color: #20653d; }
        label { display: block; margin-top: 0.75rem; font-weight: bold; }
        input, select, button { width: 100%; padding: 0.65rem; margin: 0.35rem 0 0.8rem; box-sizing: border-box; }
        button { cursor: pointer; }
        .actions { display: flex; gap: 0.75rem; flex-wrap: wrap; margin-top: 1rem; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1rem; margin-top: 1rem; }
        .panel { background: #fff; border: 1px solid #d0d7de; border-radius: 8px; padding: 1rem; }
        .video-frame { max-width: 100%; border: 2px solid #d0d7de; border-radius: 8px; background: #000; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th, td { border: 1px solid #d0d7de; padding: 0.6rem; text-align: left; vertical-align: top; }
        th { background: #eef2f7; }
        .muted { color: #666; }
        .result { margin-top: 0.75rem; padding: 0.8rem; border-radius: 6px; background: #f3f5f7; }
    </style>
</head>
<body>
<div class="card">
    <h1>Event Check-in</h1>
    <p>Scan a QR code, search for a candidate by name, mark attendance, and send a WhatsApp check-in message.</p>

    <?php if ($adminError !== ''): ?><div class="alert error"><?php echo htmlspecialchars($adminError, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if ($adminSuccess !== ''): ?><div class="alert success"><?php echo htmlspecialchars($adminSuccess, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

    <form method="get" class="actions">
        <label for="event_id">Select event</label>
        <select id="event_id" name="event" onchange="this.form.submit()">
            <?php foreach ($events as $event): ?>
                <option value="<?php echo (int) $event['event_id']; ?>" <?php echo (int) $event['event_id'] === $selectedEventId ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) ($event['event_title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
        </select>
    </form>

    <div class="grid">
        <div class="panel">
            <h2>1. Scan QR / Search name</h2>
            <div class="actions">
                <button type="button" id="startCameraBtn">Start camera</button>
                <button type="button" id="stopCameraBtn">Stop camera</button>
            </div>
            <video id="video" class="video-frame" autoplay playsinline muted></video>
            <canvas id="canvas" hidden></canvas>
            <form id="qrForm" method="post">
                <input type="hidden" name="action" value="mark_qr">
                <input type="hidden" name="event_id" value="<?php echo (int) $selectedEventId; ?>">
                <input type="hidden" id="qr_payload" name="qr_payload" value="">
            </form>

            <form method="post" class="actions">
                <input type="hidden" name="action" value="search_candidates">
                <input type="hidden" name="event_id" value="<?php echo (int) $selectedEventId; ?>">
                <label for="search_term">Search by name</label>
                <input id="search_term" name="search_term" type="text" value="<?php echo htmlspecialchars($searchTerm, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Enter candidate name">
                <button type="submit">Find candidate</button>
            </form>

            <?php if (!empty($searchResults)): ?>
                <div class="result">
                    <h3>Matching candidates</h3>
                    <ul>
                        <?php foreach ($searchResults as $candidate): ?>
                            <li>
                                <strong><?php echo htmlspecialchars((string) ($candidate['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></strong>
                                <form method="post" class="actions">
                                    <input type="hidden" name="action" value="mark_manual">
                                    <input type="hidden" name="event_id" value="<?php echo (int) $selectedEventId; ?>">
                                    <input type="hidden" name="registration_id" value="<?php echo (int) $candidate['registration_id']; ?>">
                                    <button type="submit">Check in manually</button>
                                </form>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>

        <div class="panel">
            <h2>2. Mark check-in</h2>
            <p class="muted">A candidate can be checked in by QR scan or by selecting them from the search results.</p>
            <?php if ($announcement !== ''): ?>
                <div class="result">
                    <strong>Welcome announcement:</strong><br>
                    <?php echo htmlspecialchars($announcement, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>
            <div id="statusBox" class="result">Waiting for a scan or manual search.</div>
        </div>
    </div>

    <div class="panel" style="margin-top: 1rem;">
        <h2>3. Recent check-ins</h2>
        <?php if (!empty($recentAttendance)): ?>
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Mode</th>
                        <th>Checked in</th>
                        <th>WhatsApp</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentAttendance as $attendance): ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string) ($attendance['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) ($attendance['mode'] ?? 'qr'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string) ($attendance['checked_in_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo (int) ($attendance['whatsapp_sent'] ?? 0) === 1 ? 'Sent' : 'Not sent'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="muted">No check-ins yet for this event.</p>
        <?php endif; ?>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
<script>
    const video = document.getElementById('video');
    const canvas = document.getElementById('canvas');
    const ctx = canvas.getContext('2d');
    const qrPayloadInput = document.getElementById('qr_payload');
    const qrForm = document.getElementById('qrForm');
    const statusBox = document.getElementById('statusBox');
    const startCameraBtn = document.getElementById('startCameraBtn');
    const stopCameraBtn = document.getElementById('stopCameraBtn');
    let stream = null;
    let scanning = false;
    let lastScanAt = 0;

    function setStatus(message) {
        statusBox.textContent = message;
    }

    async function startCamera() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            setStatus('Camera access is not available in this browser.');
            return;
        }

        if (stream) {
            stream.getTracks().forEach(track => track.stop());
        }

        try {
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
            video.srcObject = stream;
            await video.play();
            scanning = true;
            setStatus('Camera is ready. Point it at a QR code.');
            scanLoop();
        } catch (error) {
            setStatus('Camera access was denied or unavailable.');
        }
    }

    function stopCamera() {
        scanning = false;
        if (stream) {
            stream.getTracks().forEach(track => track.stop());
            stream = null;
        }
        if (video.srcObject) {
            video.srcObject = null;
        }
        setStatus('Camera stopped.');
    }

    function scanLoop() {
        if (!scanning) {
            return;
        }

        if (video.readyState === video.HAVE_CURRENT_DATA) {
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
            const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
            const code = window.jsQR(imageData.data, imageData.width, imageData.height);
            if (code && Date.now() - lastScanAt > 1500) {
                lastScanAt = Date.now();
                qrPayloadInput.value = code.data;
                setStatus('QR scanned. Marking attendance...');
                submitQrForm();
                return;
            }
        }

        requestAnimationFrame(scanLoop);
    }

    function submitQrForm() {
        const formData = new FormData(qrForm);
        fetch(window.location.href, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: new URLSearchParams(formData)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                setStatus(data.message + ' ' + (data.announcement || ''));
                if ('speechSynthesis' in window) {
                    const utterance = new SpeechSynthesisUtterance(data.announcement || 'Welcome!');
                    window.speechSynthesis.cancel();
                    window.speechSynthesis.speak(utterance);
                }
            } else {
                setStatus(data.message || 'Unable to mark attendance.');
            }
        })
        .catch(() => {
            setStatus('The check-in request could not be completed.');
        });
    }

    startCameraBtn.addEventListener('click', startCamera);
    stopCameraBtn.addEventListener('click', stopCamera);
</script>
</body>
</html>
