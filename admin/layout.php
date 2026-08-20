<?php

if (!function_exists('buildUrl')) {
    require_once __DIR__ . '/../config/database.php';
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function renderAdminLayout(string $pageTitle, string $content, array $pageData = []): void
{
    $currentPath = $pageData['current_path'] ?? '';
    $pageHeading = $pageData['page_heading'] ?? $pageTitle;
    $showSidebar = $pageData['show_sidebar'] ?? true;
    $showFooter = $pageData['show_footer'] ?? true;
    $extraHead = $pageData['extra_head'] ?? '';
    $extraTop = $pageData['extra_top'] ?? '';
    $extraBottom = $pageData['extra_bottom'] ?? '';
    $bodyClass = $pageData['body_class'] ?? '';
    $isAuthenticated = !empty($_SESSION['admin_authenticated']);

    echo '<!DOCTYPE html>';
    echo '<html lang="en">';
    echo '<head>';
    echo '<meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
    echo '<title>' . htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') . '</title>';
    $styleVersion = (string) (@filemtime(__DIR__ . '/../assets/css/styles.css') ?: time());
    echo '<link rel="stylesheet" href="' . htmlspecialchars(buildUrl('assets/css/styles.css') . '?v=' . $styleVersion, ENT_QUOTES, 'UTF-8') . '">';
    echo $extraHead;
    echo '</head>';
    echo '<body' . ($bodyClass !== '' ? ' class="' . htmlspecialchars($bodyClass, ENT_QUOTES, 'UTF-8') . '"' : '') . '>';
    echo '<div class="admin-shell">';
    echo '<header class="admin-header">';
    echo '<div class="brand"><a href="' . htmlspecialchars(buildUrl('admin'), ENT_QUOTES, 'UTF-8') . '">EMS Admin</a></div>';
    echo '<div class="top-links">';
    if ($isAuthenticated) {
        $topMenus = [
            'Administration' => [
                ['admin_users', 'admin/admin_users.php', 'Admin Users'],
                ['settings', 'admin/settings.php', 'Settings'],
                ['registration_options', 'admin/registration_options.php', 'Registration Dropdown Options'],
                ['playback', 'admin/playback.php', 'dPlayback'],
            ],
            'Pre' => [
                ['create_event', 'admin/create_event.php', 'Create Event'],
                ['event_schedule', 'admin/event_schedule.php', 'Registration Schedule'],
                ['event_itinerary', 'admin/event_itinerary.php', 'Itinerary'],
                ['registration_approvals', 'admin/registration_approvals.php', 'Approval'],
                ['event_pass', 'admin/event_pass.php', 'Event Pass'],
                ['admin_registration', 'admin/registration.php', 'Registration'],
                ['registration_bulk_upload', 'admin/registration_bulk_upload.php', 'Bulk Upload'],
            ],
            'On' => [
                ['event_checkin', 'admin/event_checkin.php', 'Event Check-in'],
                ['attendance_view', 'admin/attendance_view.php', 'Live Attendance'],
            ],
            'Post' => [
                ['event_reports', 'admin/event_reports.php', 'Event Reports'],
                ['feedback', 'admin/feedback.php', 'Feedback'],
                ['feedback_analysis', 'admin/feedback_analysis.php', 'Feedback Analysis'],
                ['', '', 'Certificate'],
            ],
        ];

        $activeTopMenu = 'Administration';
        foreach ($topMenus as $menuLabel => $menuItems) {
            $menuPaths = array_column($menuItems, 0);
            $menuIsActive = in_array($currentPath, $menuPaths, true);
            if ($menuIsActive) {
                $activeTopMenu = $menuLabel;
            }
            echo '<details class="top-menu" data-menu="' . htmlspecialchars(strtolower($menuLabel), ENT_QUOTES, 'UTF-8') . '">';
            echo '<summary' . ($menuIsActive ? ' class="active"' : '') . '>' . htmlspecialchars($menuLabel, ENT_QUOTES, 'UTF-8') . '</summary>';
            echo '<div class="top-submenu">';
            foreach ($menuItems as [$itemPath, $itemUrl, $itemLabel]) {
                if ($itemUrl === '') {
                    echo '<span class="disabled-link" aria-disabled="true" title="Coming soon">' . htmlspecialchars($itemLabel, ENT_QUOTES, 'UTF-8') . '</span>';
                    continue;
                }
                echo '<a href="' . htmlspecialchars(buildUrl($itemUrl), ENT_QUOTES, 'UTF-8') . '"' . ($currentPath === $itemPath ? ' class="active"' : '') . '>' . htmlspecialchars($itemLabel, ENT_QUOTES, 'UTF-8') . '</a>';
            }
            echo '</div></details>';
        }

        echo '<span class="signed-in-user">Signed in as ' . htmlspecialchars((string) ($_SESSION['admin_name'] ?? $_SESSION['admin_username'] ?? 'admin'), ENT_QUOTES, 'UTF-8') . '</span>';
        echo '<form class="top-logout" method="post" action="' . htmlspecialchars(buildUrl('admin'), ENT_QUOTES, 'UTF-8') . '">';
        echo '<input type="hidden" name="action" value="logout">';
        echo '<button type="submit">Logout</button>';
        echo '</form>';
    } else {
        echo '<a href="' . htmlspecialchars(buildUrl('admin'), ENT_QUOTES, 'UTF-8') . '">Login</a>';
    }
    echo '</div>';
    echo '</header>';
    echo '<div class="admin-body">';
    if ($showSidebar) {
        echo '<aside class="admin-sidebar">';
        foreach (($topMenus ?? []) as $menuLabel => $menuItems) {
            $menuKey = strtolower($menuLabel);
            $isVisible = ($activeTopMenu ?? 'Administration') === $menuLabel;
            echo '<section class="sidebar-menu-section" data-menu="' . htmlspecialchars($menuKey, ENT_QUOTES, 'UTF-8') . '"' . ($isVisible ? '' : ' hidden') . '>';
            echo '<h2>' . htmlspecialchars($menuLabel, ENT_QUOTES, 'UTF-8') . '</h2>';
            foreach ($menuItems as [$itemPath, $itemUrl, $itemLabel]) {
                if ($itemUrl === '') {
                    echo '<span class="sidebar-disabled" aria-disabled="true" title="Coming soon">' . htmlspecialchars($itemLabel, ENT_QUOTES, 'UTF-8') . '</span>';
                    continue;
                }
                echo '<a href="' . htmlspecialchars(buildUrl($itemUrl), ENT_QUOTES, 'UTF-8') . '"' . ($currentPath === $itemPath ? ' class="active"' : '') . '>' . htmlspecialchars($itemLabel, ENT_QUOTES, 'UTF-8') . '</a>';
            }
            echo '</section>';
        }
        echo '</aside>';
    }
    echo '<main class="admin-content">';
    echo '<div class="admin-card">';
    echo $extraTop;
    echo '<h1>' . htmlspecialchars($pageHeading, ENT_QUOTES, 'UTF-8') . '</h1>';
    echo $content;
    echo $extraBottom;
    echo '</div>';
    echo '</main>';
    echo '</div>';
    if ($showFooter) {
        echo '<footer class="admin-footer">EMS Admin Panel &copy; ' . date('Y') . '</footer>';
    }
    echo '</div>';
    echo '<script>';
    echo 'document.querySelectorAll(".top-menu").forEach(function (menu) {';
    echo 'menu.querySelector("summary").addEventListener("click", function () {';
    echo 'var menuName = menu.getAttribute("data-menu");';
    echo 'document.querySelectorAll(".sidebar-menu-section").forEach(function (section) {';
    echo 'section.hidden = section.getAttribute("data-menu") !== menuName;';
    echo '});';
    echo '});';
    echo 'menu.addEventListener("toggle", function () {';
    echo 'if (!menu.open) return;';
    echo 'document.querySelectorAll(".top-menu[open]").forEach(function (otherMenu) {';
    echo 'if (otherMenu !== menu) otherMenu.removeAttribute("open");';
    echo '});';
    echo '});';
    echo 'menu.querySelectorAll(".top-submenu a").forEach(function (link) {';
    echo 'link.addEventListener("click", function () { menu.removeAttribute("open"); });';
    echo '});';
    echo '});';
    echo 'document.addEventListener("click", function (event) {';
    echo 'if (event.target.closest(".top-menu")) return;';
    echo 'document.querySelectorAll(".top-menu[open]").forEach(function (menu) {';
    echo 'menu.removeAttribute("open");';
    echo '});';
    echo '});';
    echo '</script>';
    echo '</body>';
    echo '</html>';
}
