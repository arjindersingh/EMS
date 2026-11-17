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
                ['users', 'admin/users.php', 'Users'],
                ['admin_users', 'admin/admin_users.php', 'Admin Users'],
                ['settings', 'admin/settings.php', 'Settings'],
                ['registration_options', 'admin/registration_options.php', 'Registration Dropdown Options'],
                ['playback', 'admin/playback.php', 'dPlayback'],
            ],
            'Pre' => [
                'Event' => [
                    ['create_event', 'admin/create_event.php', 'Create Event'],
                    ['event_schedule', 'admin/event_schedule.php', 'Registration Schedule'],
                    ['event_itinerary', 'admin/event_itinerary.php', 'Itinerary'],
                ],
                'Applicants' => [
                    ['admin_registration', 'admin/registration.php', 'Registration'],
                    ['registration_bulk_upload', 'admin/registration_bulk_upload.php', 'Bulk Upload'],
                    ['fetch_registration', 'admin/fetch_registration.php', 'Fetch Registrations'],
                ],
                'Invitation' => [
                    ['registration_approvals', 'admin/registration_approvals.php', 'Approval'],
                    ['event_pass', 'admin/event_pass.php', 'Pass'],
                ],
            ],
            'On' => [
                ['event_checkin', 'admin/event_checkin.php', 'Event Check-in'],
                ['attendee_list', 'admin/attendee_list.php', 'Live Checkin'],
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
            $menuPaths = [];
            $flattenPaths = function ($items) use (&$flattenPaths, &$menuPaths) {
                foreach ($items as $key => $value) {
                    if (is_array($value) && isset($value[0]) && is_string($value[0])) {
                        // This is a menu item [path, url, label]
                        $menuPaths[] = $value[0];
                    } elseif (is_array($value) && !isset($value[0])) {
                        // This is a nested category, recurse into it
                        $flattenPaths($value);
                    }
                }
            };
            $flattenPaths($menuItems);
            
            $menuIsActive = in_array($currentPath, $menuPaths, true);
            if ($menuIsActive) {
                $activeTopMenu = $menuLabel;
            }
            echo '<details class="top-menu" data-menu="' . htmlspecialchars(strtolower($menuLabel), ENT_QUOTES, 'UTF-8') . '">';
            echo '<summary' . ($menuIsActive ? ' class="active"' : '') . '>' . htmlspecialchars($menuLabel, ENT_QUOTES, 'UTF-8') . '</summary>';
            echo '<div class="top-submenu">';
            
            // Check if this menu has nested structure
            $hasNestedStructure = false;
            foreach ($menuItems as $key => $item) {
                // If the key is a string (not numeric), it's a category label
                if (!is_int($key)) {
                    $hasNestedStructure = true;
                    break;
                }
            }
            
            if ($hasNestedStructure) {
                // Render nested structure with categories
                foreach ($menuItems as $categoryLabel => $categoryItems) {
                    if (!is_array($categoryItems)) {
                        continue;
                    }
                    // Check if this category item is a proper nested item (has [path, url, label] structure)
                    $isValidCategory = true;
                    foreach ($categoryItems as $item) {
                        if (!is_array($item) || !isset($item[0], $item[1], $item[2])) {
                            $isValidCategory = false;
                            break;
                        }
                    }
                    if (!$isValidCategory) {
                        continue;
                    }
                    
                    echo '<div class="submenu-category">';
                    echo '<span class="category-label">' . htmlspecialchars($categoryLabel, ENT_QUOTES, 'UTF-8') . '</span>';
                    foreach ($categoryItems as $item) {
                        // Defensive check - ensure this is actually a 3-element array
                        if (!is_array($item) || !isset($item[0], $item[1], $item[2])) {
                            continue;
                        }
                        $itemPath = $item[0];
                        $itemUrl = $item[1];
                        $itemLabel = $item[2];
                        
                        if ($itemUrl === '') {
                            echo '<span class="disabled-link" aria-disabled="true" title="Coming soon">' . htmlspecialchars($itemLabel, ENT_QUOTES, 'UTF-8') . '</span>';
                            continue;
                        }
                        echo '<a href="' . htmlspecialchars(buildUrl($itemUrl), ENT_QUOTES, 'UTF-8') . '"' . ($currentPath === $itemPath ? ' class="active"' : '') . '>' . htmlspecialchars($itemLabel, ENT_QUOTES, 'UTF-8') . '</a>';
                    }
                    echo '</div>';
                }
            } else {
                // Render flat structure
                foreach ($menuItems as $item) {
                    if (!is_array($item) || !isset($item[0], $item[1], $item[2])) {
                        continue;
                    }
                    $itemPath = $item[0];
                    $itemUrl = $item[1];
                    $itemLabel = $item[2];
                    
                    if ($itemUrl === '') {
                        echo '<span class="disabled-link" aria-disabled="true" title="Coming soon">' . htmlspecialchars($itemLabel, ENT_QUOTES, 'UTF-8') . '</span>';
                        continue;
                    }
                    echo '<a href="' . htmlspecialchars(buildUrl($itemUrl), ENT_QUOTES, 'UTF-8') . '"' . ($currentPath === $itemPath ? ' class="active"' : '') . '>' . htmlspecialchars($itemLabel, ENT_QUOTES, 'UTF-8') . '</a>';
                }
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
            
            // Check if this menu has nested structure
            $hasNestedStructure = false;
            foreach ($menuItems as $key => $item) {
                // If the key is a string (not numeric), it's a category label
                if (!is_int($key)) {
                    $hasNestedStructure = true;
                    break;
                }
            }
            
            if ($hasNestedStructure) {
                // Render nested structure with categories
                foreach ($menuItems as $categoryLabel => $categoryItems) {
                    if (!is_array($categoryItems)) {
                        continue;
                    }
                    // Check if this category item is a proper nested item
                    $isValidCategory = true;
                    foreach ($categoryItems as $item) {
                        if (!is_array($item) || !isset($item[0], $item[1], $item[2])) {
                            $isValidCategory = false;
                            break;
                        }
                    }
                    if (!$isValidCategory) {
                        continue;
                    }
                    
                    echo '<div class="sidebar-category">';
                    echo '<h3 class="category-title">' . htmlspecialchars($categoryLabel, ENT_QUOTES, 'UTF-8') . '</h3>';
                    foreach ($categoryItems as $item) {
                        // Defensive check - ensure this is actually a 3-element array
                        if (!is_array($item) || !isset($item[0], $item[1], $item[2])) {
                            continue;
                        }
                        $itemPath = $item[0];
                        $itemUrl = $item[1];
                        $itemLabel = $item[2];
                        
                        if ($itemUrl === '') {
                            echo '<span class="sidebar-disabled" aria-disabled="true" title="Coming soon">' . htmlspecialchars($itemLabel, ENT_QUOTES, 'UTF-8') . '</span>';
                            continue;
                        }
                        echo '<a href="' . htmlspecialchars(buildUrl($itemUrl), ENT_QUOTES, 'UTF-8') . '"' . ($currentPath === $itemPath ? ' class="active"' : '') . '>' . htmlspecialchars($itemLabel, ENT_QUOTES, 'UTF-8') . '</a>';
                    }
                    echo '</div>';
                }
            } else {
                // Render flat structure
                foreach ($menuItems as $item) {
                    if (!is_array($item) || !isset($item[0], $item[1], $item[2])) {
                        continue;
                    }
                    $itemPath = $item[0];
                    $itemUrl = $item[1];
                    $itemLabel = $item[2];
                    
                    if ($itemUrl === '') {
                        echo '<span class="sidebar-disabled" aria-disabled="true" title="Coming soon">' . htmlspecialchars($itemLabel, ENT_QUOTES, 'UTF-8') . '</span>';
                        continue;
                    }
                    echo '<a href="' . htmlspecialchars(buildUrl($itemUrl), ENT_QUOTES, 'UTF-8') . '"' . ($currentPath === $itemPath ? ' class="active"' : '') . '>' . htmlspecialchars($itemLabel, ENT_QUOTES, 'UTF-8') . '</a>';
                }
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
