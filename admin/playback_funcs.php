<?php

require_once __DIR__ . '/settings_funcs.php';

function ensurePlaybackSettings(PDO $pdo): void
{
    $defaults = [
        ['playback_enabled', 'boolean', '0', 'Enable instrumental background music during event check-in.'],
        ['playback_playlist', 'text', '[]', 'JSON list of selected MP3 filenames.'],
        ['playback_volume', 'number', '0.35', 'Background music volume from 0 to 1.'],
        ['playback_shuffle', 'boolean', '0', 'Shuffle the selected playlist.'],
        ['playback_repeat', 'boolean', '1', 'Repeat the playlist after the final track.'],
        ['playback_fade_seconds', 'number', '1.5', 'Fade-in duration when playback begins or resumes.'],
    ];

    foreach ($defaults as [$name, $type, $value, $description]) {
        if (!getSettingByName($pdo, $name)) {
            saveSetting($pdo, [
                'setting_name' => $name,
                'setting_type' => $type,
                'setting_value' => $value,
                'setting_description' => $description,
            ]);
        }
    }
}

function getPlaybackDirectory(): string
{
    return __DIR__ . '/../assets/audio/playback';
}

function getPlaybackFiles(): array
{
    $directory = getPlaybackDirectory();
    if (!is_dir($directory)) {
        return [];
    }

    $allowedExtensions = ['mp3', 'wav', 'ogg', 'm4a', 'flac', 'aac', 'webm', 'opus'];
    $files = [];
    foreach (new DirectoryIterator($directory) as $file) {
        if (!$file->isFile()) {
            continue;
        }

        $extension = strtolower($file->getExtension());
        if (!in_array($extension, $allowedExtensions, true)) {
            continue;
        }

        $files[] = [
            'name' => $file->getFilename(),
            'size' => $file->getSize(),
            // buildUrl() URL-encodes every path segment. Encoding the filename
            // here as well produces URLs such as %2520, which Apache treats as
            // a different (non-existent) file and browsers report as an
            // unsupported audio source.
            'url' => buildUrl('assets/audio/playback/' . $file->getFilename()),
        ];
    }
    usort($files, static fn(array $left, array $right): int => strcasecmp($left['name'], $right['name']));
    return $files;
}

function getPlaybackConfig(PDO $pdo): array
{
    $availableFiles = getPlaybackFiles();
    $availableByName = [];
    foreach ($availableFiles as $file) {
        $availableByName[$file['name']] = $file;
    }

    $savedPlaylist = json_decode((string) getSettingValue($pdo, 'playback_playlist', '[]'), true);
    $savedPlaylist = is_array($savedPlaylist) ? $savedPlaylist : [];
    $tracks = [];
    foreach ($savedPlaylist as $fileName) {
        if (isset($availableByName[$fileName])) {
            $tracks[] = $availableByName[$fileName];
        }
    }

    return [
        'enabled' => (bool) getSettingValue($pdo, 'playback_enabled', false),
        'volume' => max(0, min(1, (float) getSettingValue($pdo, 'playback_volume', 0.35))),
        'shuffle' => (bool) getSettingValue($pdo, 'playback_shuffle', false),
        'repeat' => (bool) getSettingValue($pdo, 'playback_repeat', true),
        'fadeSeconds' => max(0, min(10, (float) getSettingValue($pdo, 'playback_fade_seconds', 1.5))),
        'tracks' => $tracks,
        'selectedNames' => array_column($tracks, 'name'),
    ];
}
