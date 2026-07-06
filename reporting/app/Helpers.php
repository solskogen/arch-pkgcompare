<?php
/**
 * Helper functions for formatting and utilities
 */
class Formatter {
    public static function escape($str) {
        return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
    }

    public static function number($num) {
        return number_format(intval($num));
    }

    public static function size($bytes) {
        if (!$bytes) return '-';
        return number_format($bytes / (1024 * 1024), 1) . ' MB';
    }

    public static function date($timestamp) {
        if (!$timestamp) return '-';
        return date('Y-m-d H:i:s', intval($timestamp));
    }

    public static function truncate($str, $length = 100) {
        if (strlen($str) > $length) {
            return substr($str, 0, $length) . '...';
        }
        return $str;
    }

    public static function baseUrl() {
        $script_name = $_SERVER['SCRIPT_NAME'];
        
        if (preg_match('|~[^/]+/reporting|', $script_name)) {
            if (preg_match('|(~[^/]+/reporting)/|', $script_name, $matches)) {
                return '/' . $matches[1] . '/';
            }
        }
        
        return '/reporting/';
    }

    public static function url($page, $params = []) {
        $base = self::baseUrl();
        $url = $base . $page;
        if ($params) {
            $url .= '?' . http_build_query($params);
        }
        return $url;
    }
}

/**
 * Layout/page rendering helpers
 */
class Layout {
    public static function header($title = 'Arch Linux Package Reporting', $options = []) {
        $show_page_header = $options['show_page_header'] ?? true;
        $page_header_class = $options['page_header_class'] ?? '';
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo Formatter::escape($title); ?> - Arch Linux Package Reporting</title>
    <link rel="stylesheet" href="<?php echo Formatter::baseUrl(); ?>css/style.css">
</head>
<body>
    <nav class="navbar">
        <div class="container">
            <div class="logo">
                <a href="<?php echo Formatter::url('index.php'); ?>">📊 Arch Comparison</a>
            </div>
            <div class="nav-links">
                <a href="<?php echo Formatter::url('index.php'); ?>">Home</a>
                <a href="<?php echo Formatter::url('analysis.php'); ?>">Analysis</a>
                <a href="<?php echo Formatter::url('comparison.php'); ?>">Comparison</a>
            </div>
        </div>
    </nav>
<?php if ($show_page_header && $title): ?>
    <div class="page-title-bar<?php echo $page_header_class ? ' ' . Formatter::escape($page_header_class) : ''; ?>">
        <div class="container">
            <h1><?php echo Formatter::escape($title); ?></h1>
        </div>
    </div>
<?php endif; ?>
    <?php
    }

    public static function footer() {
        ?>
    <footer class="site-footer">
        <div class="container">
            <p>Arch Linux Package Comparison Tool · Data from official Arch repositories · Generated <?php echo date('Y-m-d H:i'); ?></p>
        </div>
    </footer>
</body>
</html>
        <?php
    }
}
?>
