<?php

if (!function_exists('buildUrl')) {
    require_once __DIR__ . '/config/database.php';
}

function renderPublicHeader(string $activePage = ''): void
{
    $homeUrl = htmlspecialchars(buildUrl(''), ENT_QUOTES, 'UTF-8');
    $registerUrl = htmlspecialchars(buildUrl('register'), ENT_QUOTES, 'UTF-8');
    ?>
    <header class="public-header">
        <div class="public-header-inner">
            <a class="public-brand" href="<?php echo $homeUrl; ?>" aria-label="Innocent Hearts Group home">
                <span class="public-brand-mark" aria-hidden="true">IH</span>
                <span class="public-brand-copy">
                    <strong>Innocent Hearts Group</strong>
                    <small>Managed by BMEMT</small>
                    <small>GMT, Jalandhar</small>
                </span>
            </a>

        </div>
    </header>
    <?php
}

function renderPublicFooter(): void
{
    ?>
    <footer class="public-footer">
        <div class="public-footer-inner">
            <div>
                <strong>Innocent Hearts Group</strong>
                <span>Managed by BMEMT · GMT, Jalandhar</span>
            </div>
            <nav aria-label="Footer navigation">
                <a href="<?php echo htmlspecialchars(buildUrl(''), ENT_QUOTES, 'UTF-8'); ?>">Home</a>
                <a href="<?php echo htmlspecialchars(buildUrl('register'), ENT_QUOTES, 'UTF-8'); ?>">Event Registration</a>
            </nav>
            <p>&copy; <?php echo date('Y'); ?> Innocent Hearts Group. All rights reserved.</p>
        </div>
    </footer>
    <?php
}
