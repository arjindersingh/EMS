<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/playback_funcs.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!($_SESSION['admin_authenticated'] ?? false)) {
    header('Location: ' . buildUrl('admin'));
    exit;
}

$pdo = null;
$adminError = '';
$adminSuccess = '';
$files = [];
$config = [
    'enabled' => false,
    'volume' => 0.35,
    'shuffle' => false,
    'repeat' => true,
    'fadeSeconds' => 1.5,
    'selectedNames' => [],
];

try {
    $pdo = createDbConnection();
    ensureSettingsTable($pdo);
    ensurePlaybackSettings($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'upload_track') {
            $upload = $_FILES['audio_file'] ?? [];
            $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($error !== UPLOAD_ERR_OK) {
                $uploadErrors = [
                    UPLOAD_ERR_INI_SIZE => 'The audio file exceeds the PHP upload limit. Set upload_max_filesize to at least 32M and restart Apache.',
                    UPLOAD_ERR_FORM_SIZE => 'The audio file exceeds this form\'s 25 MB limit.',
                    UPLOAD_ERR_PARTIAL => 'The audio file was only partially uploaded. Please try again.',
                    UPLOAD_ERR_NO_FILE => 'Please select an audio file to upload.',
                    UPLOAD_ERR_NO_TMP_DIR => 'PHP has no temporary upload directory configured.',
                    UPLOAD_ERR_CANT_WRITE => 'PHP could not write the audio file to disk.',
                    UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the audio upload.',
                ];
                throw new InvalidArgumentException($uploadErrors[$error] ?? 'The audio file upload failed.');
            }
            $size = (int) ($upload['size'] ?? 0);
            $temporaryPath = (string) ($upload['tmp_name'] ?? '');
            if ($size <= 0 || $size > 25 * 1024 * 1024 || !is_uploaded_file($temporaryPath)) {
                throw new InvalidArgumentException('The audio file must be valid and no larger than 25 MB.');
            }
            $extension = strtolower(pathinfo((string) ($upload['name'] ?? ''), PATHINFO_EXTENSION));
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
            $allowedExtensions = ['mp3', 'wav', 'ogg', 'm4a', 'flac', 'aac', 'webm', 'opus'];
            $allowedMimeTypes = [
                'audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav', 'audio/vnd.wave',
                'audio/ogg', 'audio/webm', 'audio/mp4', 'audio/x-m4a', 'audio/flac',
                'audio/aac', 'audio/opus', 'audio/x-aac', 'application/ogg',
                // fileinfo commonly classifies audio-only WebM as video/webm.
                'video/webm', 'application/octet-stream',
            ];
            $mimeIsAudio = is_string($mime) && (str_starts_with($mime, 'audio/') || in_array($mime, $allowedMimeTypes, true));
            if (!in_array($extension, $allowedExtensions, true) || !$mimeIsAudio) {
                throw new InvalidArgumentException('Only audio files are allowed. Supported types: MP3, WAV, OGG, M4A, FLAC, AAC, WEBM, OPUS.');
            }
            $baseName = pathinfo((string) $upload['name'], PATHINFO_FILENAME);
            $safeName = trim((string) preg_replace('/[^A-Za-z0-9 _.-]+/', '', $baseName));
            $safeName = $safeName !== '' ? $safeName : 'audio-' . date('Ymd-His');
            $directory = getPlaybackDirectory();
            if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new RuntimeException('The playback audio directory could not be created.');
            }
            $duplicate = findDuplicatePlaybackFile($directory, (string) ($upload['name'] ?? ''), $temporaryPath, $size);
            if ($duplicate !== null) {
                throw new InvalidArgumentException('This audio file has already been uploaded as "' . $duplicate . '".');
            }
            $destination = $directory . DIRECTORY_SEPARATOR . $safeName . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
            if (!move_uploaded_file($temporaryPath, $destination)) {
                throw new RuntimeException('The audio file could not be saved.');
            }
            $adminSuccess = 'Audio file uploaded successfully.';
        } elseif ($action === 'save_playback') {
            $availableNames = array_column(getPlaybackFiles(), 'name');
            $selected = array_values(array_intersect(
                array_map('strval', (array) ($_POST['playlist'] ?? [])),
                $availableNames
            ));
            $values = [
                'playback_enabled' => ['boolean', isset($_POST['playback_enabled']) ? '1' : '0'],
                'playback_playlist' => ['text', json_encode($selected)],
                'playback_volume' => ['number', (string) max(0, min(1, (float) ($_POST['playback_volume'] ?? 0.35)))],
                'playback_shuffle' => ['boolean', isset($_POST['playback_shuffle']) ? '1' : '0'],
                'playback_repeat' => ['boolean', isset($_POST['playback_repeat']) ? '1' : '0'],
                'playback_fade_seconds' => ['number', (string) max(0, min(10, (float) ($_POST['playback_fade_seconds'] ?? 1.5)))],
            ];
            foreach ($values as $name => [$type, $value]) {
                $existing = getSettingByName($pdo, $name);
                saveSetting($pdo, [
                    'setting_id' => (int) ($existing['setting_id'] ?? 0),
                    'setting_name' => $name,
                    'setting_type' => $type,
                    'setting_value' => $value,
                    'setting_description' => (string) ($existing['setting_description'] ?? ''),
                ]);
            }
            $adminSuccess = 'Playback settings saved.';
        }
    }

    $config = getPlaybackConfig($pdo);
} catch (Throwable $exception) {
    $adminError = $exception->getMessage();
}

// Audio files live on disk and should remain visible even if settings storage
// is temporarily unavailable during a database restart.
$files = getPlaybackFiles();

function formatPlaybackFileSize(int $bytes): string
{
    return number_format($bytes / 1048576, 1) . ' MB';
}

function findDuplicatePlaybackFile(string $directory, string $uploadName, string $temporaryPath, int $uploadSize): ?string
{
    $uploadHash = hash_file('sha256', $temporaryPath);
    if (!is_string($uploadHash)) {
        throw new RuntimeException('The uploaded audio file could not be verified.');
    }

    foreach (new DirectoryIterator($directory) as $file) {
        if (!$file->isFile()) {
            continue;
        }

        // A matching name is enough to prevent accidental repeat uploads.
        if ($uploadName !== '' && strcasecmp($file->getFilename(), $uploadName) === 0) {
            return $file->getFilename();
        }

        // Also detect the same audio content when it has been renamed.
        if ($file->getSize() !== $uploadSize) {
            continue;
        }
        $existingHash = hash_file('sha256', $file->getPathname());
        if (is_string($existingHash) && hash_equals($existingHash, $uploadHash)) {
            return $file->getFilename();
        }
    }

    return null;
}

ob_start();
?>
<div class="playback-page">
    <?php if ($adminError !== ''): ?><div class="alert error"><?php echo htmlspecialchars($adminError, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if ($adminSuccess !== ''): ?><div class="alert success"><?php echo htmlspecialchars($adminSuccess, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

    <div class="playback-intro">
        <div><span>Check-in ambience</span><h2>Instrumental Music Playback</h2><p>Music pauses automatically for spoken check-in announcements and resumes afterward.</p></div>
        <div class="playback-equalizer" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i></div>
    </div>

    <form method="post" enctype="multipart/form-data" class="playback-upload">
        <input type="hidden" name="action" value="upload_track">
        <label for="audio_file"><strong>Add audio file</strong><span>Maximum file size: 25 MB (MP3, WAV, OGG, M4A, FLAC, AAC, WEBM, OPUS)</span></label>
        <input id="audio_file" name="audio_file" type="file" accept="audio/*" required>
        <button type="submit">Upload audio</button>
    </form>

    <form method="post" class="playback-settings">
        <input type="hidden" name="action" value="save_playback">
        <section class="playback-card">
            <div class="playback-card-heading"><div><span>Playback</span><h3>Background music</h3></div>
                <label class="playback-switch"><input type="checkbox" name="playback_enabled" value="1" <?php echo $config['enabled'] ? 'checked' : ''; ?>><span></span><b>Enable playback</b></label>
            </div>
            <div class="playback-controls">
                <label>Volume <output id="volumeOutput"><?php echo (int) round($config['volume'] * 100); ?>%</output>
                    <input id="playbackVolume" name="playback_volume" type="range" min="0" max="1" step="0.05" value="<?php echo htmlspecialchars((string) $config['volume'], ENT_QUOTES, 'UTF-8'); ?>">
                </label>
                <label>Fade in (seconds)
                    <input name="playback_fade_seconds" type="number" min="0" max="10" step="0.5" value="<?php echo htmlspecialchars((string) $config['fadeSeconds'], ENT_QUOTES, 'UTF-8'); ?>">
                </label>
                <label class="playback-check"><input type="checkbox" name="playback_shuffle" value="1" <?php echo $config['shuffle'] ? 'checked' : ''; ?>> Shuffle tracks</label>
                <label class="playback-check"><input type="checkbox" name="playback_repeat" value="1" <?php echo $config['repeat'] ? 'checked' : ''; ?>> Repeat playlist</label>
            </div>
        </section>

        <section class="playback-card">
            <div class="playback-card-heading"><div><span>Playlist</span><h3>Select audio to play</h3></div><small><?php echo count($files); ?> audio file(s)</small></div>
            <?php if ($files): ?>
                <div class="playback-track-list">
                    <?php foreach ($files as $index => $file): ?>
                        <label class="playback-track">
                            <input type="checkbox" name="playlist[]" value="<?php echo htmlspecialchars($file['name'], ENT_QUOTES, 'UTF-8'); ?>" <?php echo in_array($file['name'], $config['selectedNames'], true) ? 'checked' : ''; ?>>
                            <span class="playback-track-number"><?php echo str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT); ?></span>
                            <span class="playback-track-name"><?php echo htmlspecialchars($file['name'], ENT_QUOTES, 'UTF-8'); ?><small><?php echo formatPlaybackFileSize((int) $file['size']); ?></small></span>
                            <audio controls preload="none" src="<?php echo htmlspecialchars($file['url'], ENT_QUOTES, 'UTF-8'); ?>"></audio>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="playback-empty">Upload an audio file to create the playback playlist.</div>
            <?php endif; ?>
        </section>
        <button type="submit" class="playback-save">Save playback settings</button>
    </form>
</div>
<script>
    const volumeInput = document.getElementById('playbackVolume');
    const volumeOutput = document.getElementById('volumeOutput');
    volumeInput?.addEventListener('input', () => volumeOutput.textContent = Math.round(Number(volumeInput.value) * 100) + '%');
    document.querySelectorAll('.playback-track audio').forEach(audio => {
        audio.addEventListener('play', () => document.querySelectorAll('.playback-track audio').forEach(other => {
            if (other !== audio) other.pause();
        }));
    });
</script>
<?php
$content = ob_get_clean();
renderAdminLayout('dPlayback', $content, [
    'current_path' => 'playback',
    'page_heading' => 'dPlayback',
    'body_class' => 'playback-admin-page',
]);
