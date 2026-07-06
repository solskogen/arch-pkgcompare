<?php
// This page is superseded by report-x86_64-newer.php
require_once __DIR__ . '/app/Helpers.php';
$base = Formatter::baseUrl();
header('Location: ' . $base . 'report-x86_64-newer.php', true, 301);
exit;
