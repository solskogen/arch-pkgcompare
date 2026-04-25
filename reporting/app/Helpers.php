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
    public static function header($title = 'Arch Linux Package Reporting') {
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo Formatter::escape($title); ?> - Arch Linux Package Reporting</title>
    <link rel="stylesheet" href="<?php echo Formatter::baseUrl(); ?>css/style.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: #1a1a1a;
            color: #e0e0e0;
            line-height: 1.6;
        }
        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        .header {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            padding: 30px 0;
            margin-bottom: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
        }
        .header h1 {
            font-size: 28px;
            margin-bottom: 5px;
        }
        .header p {
            opacity: 0.9;
            font-size: 14px;
        }
        .nav {
            background: #252525;
            padding: 15px 0;
            margin-bottom: 30px;
            border-bottom: 1px solid #333;
        }
        .nav a {
            color: #64b5f6;
            text-decoration: none;
            margin-right: 20px;
            font-size: 14px;
        }
        .nav a:hover {
            color: #90caf9;
            text-decoration: underline;
        }
        .card {
            background: #252525;
            border: 1px solid #333;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }
        .card h2 {
            margin-bottom: 15px;
            color: #64b5f6;
            font-size: 18px;
        }
        .card h3 {
            margin: 15px 0 10px 0;
            font-size: 14px;
            color: #90caf9;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        thead {
            background: #1a1a1a;
        }
        th, td {
            padding: 10px;
            text-align: left;
            border-bottom: 1px solid #333;
        }
        th {
            font-weight: 600;
            color: #64b5f6;
        }
        tr:hover {
            background: rgba(100, 181, 246, 0.05);
        }
        a {
            color: #64b5f6;
            text-decoration: none;
        }
        a:hover {
            text-decoration: underline;
        }
        .badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
            margin-right: 5px;
        }
        .badge-success {
            background: rgba(76, 175, 80, 0.2);
            color: #4caf50;
        }
        .badge-warning {
            background: rgba(255, 152, 0, 0.2);
            color: #ff9800;
        }
        .badge-info {
            background: rgba(33, 150, 243, 0.2);
            color: #2196f3;
        }
        .badge-error {
            background: rgba(244, 67, 54, 0.2);
            color: #f44336;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-box {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }
        .stat-box .value {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .stat-box .label {
            font-size: 12px;
            opacity: 0.8;
        }
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .alert-info {
            background: rgba(33, 150, 243, 0.2);
            border: 1px solid #2196f3;
            color: #2196f3;
        }
        .alert-warning {
            background: rgba(255, 152, 0, 0.2);
            border: 1px solid #ff9800;
            color: #ff9800;
        }
        .pagination {
            margin-top: 20px;
            text-align: center;
        }
        .pagination a, .pagination span {
            padding: 5px 10px;
            margin: 0 2px;
            border: 1px solid #333;
            border-radius: 4px;
            display: inline-block;
        }
        .pagination a {
            background: #2a5298;
            color: white;
        }
        .pagination a:hover {
            background: #1e3c72;
        }
        .pagination .current {
            background: #333;
            color: white;
        }
        .footer {
            text-align: center;
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #333;
            opacity: 0.6;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="container">
            <h1><?php echo Formatter::escape($title); ?></h1>
            <p><?php echo isset($header_subtitle) ? $header_subtitle : 'Arch Linux Package Reporting'; ?></p>
        </div>
    </div>
    <div class="nav">
        <div class="container">
            <a href="<?php echo Formatter::url('index.php'); ?>">Home</a>
            <a href="<?php echo Formatter::url('analysis.php'); ?>">Analysis</a>
            <a href="<?php echo Formatter::url('comparison.php'); ?>">Comparison</a>
        </div>
    </div>
        <?php
    }

    public static function footer() {
        ?>
    <div class="footer">
        <div class="container">
            <p>Arch Linux Package Database Comparison Tool | Last updated: <?php echo date('Y-m-d H:i:s'); ?></p>
        </div>
    </div>
</body>
</html>
        <?php
    }
}
?>
