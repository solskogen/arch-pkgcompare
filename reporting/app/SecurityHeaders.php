<?php
/**
 * Security headers must be set before any output is sent
 * This should be included at the very beginning of each PHP script
 */

if (!function_exists('setSecurityHeaders')) {
    function setSecurityHeaders() {
        // Prevent clickjacking attacks
        header('X-Frame-Options: DENY', true);
        // Prevent MIME type sniffing
        header('X-Content-Type-Options: nosniff', true);
        // Enable XSS protection in older browsers
        header('X-XSS-Protection: 1; mode=block', true);
        // Content Security Policy - prevent inline scripts and external script execution
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; object-src 'none'; frame-ancestors 'none';", true);
        // Referrer Policy
        header('Referrer-Policy: strict-origin-when-cross-origin', true);
        // Permissions Policy - disable unnecessary features
        header('Permissions-Policy: microphone=(), camera=(), geolocation=(), payment=()', true);
    }
}

setSecurityHeaders();
?>
