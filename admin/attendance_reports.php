<?php
require_once __DIR__ . '/../config/database.php';
header('Location: ' . buildUrl('admin/event_reports.php') . '?type=attendees');
exit;
