<?php
// This page is superseded by report-newer-versions.php
require_once __DIR__ . '/app/Helpers.php';
$base = Formatter::baseUrl();
header('Location: ' . $base . 'report-newer-versions.php', true, 301);
exit;
