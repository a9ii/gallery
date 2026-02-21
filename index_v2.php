<?php
// ==================== إعدادات الأمان ====================
session_start();
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https://a9ii.com;");

// CSRF Token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Rate Limiting - رفع الحد الأقصى
define('ENABLE_RATE_LIMIT', true); // يمكن تعطيله في بيئة التطوير
define('RATE_LIMIT_MAX_REQUESTS', 1000); // 1000 طلب
define('RATE_LIMIT_TIME_WINDOW', 60); // 60 ثانية

if (ENABLE_RATE_LIMIT) {
    $ip = $_SERVER['REMOTE_ADDR'];
    $rateLimitFile = sys_get_temp_dir() . '/gallery_rate_' . md5($ip);
    
    if (file_exists($rateLimitFile)) {
        $data = json_decode(file_get_contents($rateLimitFile), true);
        if (time() - $data['time'] < RATE_LIMIT_TIME_WINDOW) {
            if ($data['count'] >= RATE_LIMIT_MAX_REQUESTS) {
                $remainingTime = RATE_LIMIT_TIME_WINDOW - (time() - $data['time']);
                header('HTTP/1.1 429 Too Many Requests');
                header('Retry-After: ' . $remainingTime);
                header('Content-Type: text/html; charset=UTF-8');
                die('
                    <!DOCTYPE html>
                    <html lang="ar" dir="rtl">
                    <head>
                        <meta charset="UTF-8">
                        <title>تجاوز حد الطلبات</title>
                        <style>
                            body {
                                font-family: Tajawal, Arial, sans-serif;
                                background: #0a0a0a;
                                color: #f0f0f0;
                                display: flex;
                                align-items: center;
                                justify-content: center;
                                height: 100vh;
                                margin: 0;
                                text-align: center;
                            }
                            .error-box {
                                background: #1a1a1a;
                                border: 2px solid #4a9eff;
                                border-radius: 20px;
                                padding: 40px;
                                max-width: 500px;
                                box-shadow: 0 10px 40px rgba(0,0,0,0.5);
                            }
                            h1 { color: #4a9eff; margin-bottom: 20px; }
                            p { line-height: 1.8; color: #b8b8b8; }
                            .countdown { 
                                font-size: 2em; 
                                color: #f59e0b; 
                                margin: 20px 0;
                                font-weight: bold;
                            }
                            button {
                                background: #4a9eff;
                                color: white;
                                border: none;
                                padding: 12px 30px;
                                border-radius: 10px;
                                font-size: 16px;
                                cursor: pointer;
                                margin-top: 20px;
                            }
                            button:hover { background: #357dd8; }
                        </style>
                    </head>
                    <body>
                        <div class="error-box">
                            <h1>⚠️ تجاوز حد الطلبات</h1>
                            <p>لقد تجاوزت الحد المسموح به من الطلبات (' . RATE_LIMIT_MAX_REQUESTS . ' طلب في ' . RATE_LIMIT_TIME_WINDOW . ' ثانية)</p>
                            <p>يرجى الانتظار قبل المحاولة مرة أخرى</p>
                            <div class="countdown" id="countdown">' . $remainingTime . ' ثانية</div>
                            <button onclick="location.reload()">تحديث الصفحة</button>
                        </div>
                        <script>
                            let seconds = ' . $remainingTime . ';
                            const interval = setInterval(() => {
                                seconds--;
                                document.getElementById("countdown").textContent = seconds + " ثانية";
                                if (seconds <= 0) {
                                    clearInterval(interval);
                                    location.reload();
                                }
                            }, 1000);
                        </script>
                    </body>
                    </html>
                ');
            }
            $data['count']++;
        } else {
            $data = ['time' => time(), 'count' => 1];
        }
    } else {
        $data = ['time' => time(), 'count' => 1];
    }
    file_put_contents($rateLimitFile, json_encode($data));
}

// ==================== إعدادات عامة ====================
define('ALBUMS_DIR', __DIR__ . '/albums');
define('CACHE_DIR', __DIR__ . '/cache/thumbs');
define('ALLOWED_EXTS', ['jpg', 'jpeg', 'png', 'webp', 'avif', 'gif']);
define('DEFAULT_THUMB_WIDTH', 520);
define('MIN_THUMB_WIDTH', 120);
define('MAX_THUMB_WIDTH', 2000);
define('SITE_NAME', 'معرض الصور');
define('SITE_DESC', 'معرض صور احترافي بتصميم عصري');
define('SITE_URL', 'https://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));

// إنشاء مجلد الكاش إن لم يوجد
if (!is_dir(CACHE_DIR)) {
    @mkdir(CACHE_DIR, 0755, true);
}

// ==================== دوال مساعدة ====================
function sanitizePath($path) {
    if (empty($path)) return '';
    $path = str_replace(['..', "\0", '%00', "\r", "\n"], '', $path);
    $path = preg_replace('/[^\p{L}\p{N}._\- ]/u', '', $path);
    if (mb_strlen($path, 'UTF-8') > 255) {
        return '';
    }
    return $path;
}

function validateInput($input, $type = 'string', $maxLength = 255) {
    if ($type === 'int') {
        return filter_var($input, FILTER_VALIDATE_INT) !== false ? (int)$input : 0;
    }
    $input = strip_tags($input);
    $input = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
    if (mb_strlen($input, 'UTF-8') > $maxLength) {
        $input = mb_substr($input, 0, $maxLength, 'UTF-8');
    }
    return $input;
}

function generateSlug($name) {
    $slug = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $name);
    $slug = preg_replace('/[\s_]+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug;
}

function getAlbums() {
    $albums = [];
    if (!is_dir(ALBUMS_DIR)) return $albums;
    
    $dirs = scandir(ALBUMS_DIR);
    foreach ($dirs as $dir) {
        if ($dir[0] === '.' || !is_dir(ALBUMS_DIR . '/' . $dir)) continue;
        
        $images = getImagesInAlbum($dir);
        if (empty($images)) continue;
        
        $coverPath = ALBUMS_DIR . '/' . $dir . '/cover.png';
        $cover = file_exists($coverPath) ? 'cover.png' : $images[0];
        
        $albums[] = [
            'name' => $dir,
            'slug' => generateSlug($dir),
            'cover' => $cover,
            'count' => count($images),
            'modified' => filemtime(ALBUMS_DIR . '/' . $dir)
        ];
    }
    
    usort($albums, fn($a, $b) => $b['modified'] - $a['modified']);
    return $albums;
}

function getImagesInAlbum($albumName) {
    $images = [];
    $albumPath = ALBUMS_DIR . '/' . $albumName;
    
    if (!is_dir($albumPath)) return $images;
    
    $realPath = realpath($albumPath);
    $realAlbums = realpath(ALBUMS_DIR);
    if (!$realPath || !$realAlbums || strpos($realPath, $realAlbums) !== 0) {
        return $images;
    }
    
    $files = scandir($albumPath);
    foreach ($files as $file) {
        if ($file[0] === '.') continue;
        
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($ext, ALLOWED_EXTS)) continue;
        
        if (filesize($albumPath . '/' . $file) > 50 * 1024 * 1024) continue;
        
        $images[] = $file;
    }
    
    return $images;
}

function serveThumbnail($album, $image, $width) {
    $album = sanitizePath($album);
    $image = sanitizePath($image);
    $width = validateInput($width, 'int');
    $width = max(MIN_THUMB_WIDTH, min(MAX_THUMB_WIDTH, $width));
    
    if (empty($album) || empty($image)) {
        header('HTTP/1.0 400 Bad Request');
        exit;
    }
    
    $sourcePath = ALBUMS_DIR . '/' . $album . '/' . $image;
    if (!file_exists($sourcePath)) {
        header('HTTP/1.0 404 Not Found');
        exit;
    }
    
    $realSource = realpath($sourcePath);
    $realAlbums = realpath(ALBUMS_DIR);
    if (!$realSource || !$realAlbums || strpos($realSource, $realAlbums) !== 0) {
        header('HTTP/1.0 403 Forbidden');
        exit;
    }
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $sourcePath);
    finfo_close($finfo);
    
    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];
    if (!in_array($mimeType, $allowedMimes)) {
        header('HTTP/1.0 403 Forbidden');
        exit;
    }
    
    $cacheFile = CACHE_DIR . '/' . md5($album . '_' . $image . '_' . $width . '_v4') . '.jpg';
    
    if (file_exists($cacheFile) && filemtime($cacheFile) >= filemtime($sourcePath)) {
        serveImage($cacheFile, 'image/jpeg');
        return;
    }
    
    if (extension_loaded('gd')) {
        if (generateThumbnailGD($sourcePath, $cacheFile, $width)) {
            serveImage($cacheFile, 'image/jpeg');
        } else {
            serveImage($sourcePath, $mimeType);
        }
    } else {
        serveImage($sourcePath, $mimeType);
    }
}

function generateThumbnailGD($source, $dest, $width) {
    $info = @getimagesize($source);
    if (!$info) return false;
    
    if ($info[0] > 10000 || $info[1] > 10000) return false;
    
    $srcWidth = $info[0];
    $srcHeight = $info[1];
    $ratio = $srcHeight / $srcWidth;
    $height = round($width * $ratio);
    
    switch ($info['mime']) {
        case 'image/jpeg': $srcImg = @imagecreatefromjpeg($source); break;
        case 'image/png': $srcImg = @imagecreatefrompng($source); break;
        case 'image/webp':
            if (function_exists('imagecreatefromwebp')) $srcImg = @imagecreatefromwebp($source);
            else return false;
            break;
        case 'image/gif': $srcImg = @imagecreatefromgif($source); break;
        default: return false;
    }
    
    if (!$srcImg) return false;
    
    $dstImg = imagecreatetruecolor($width, $height);
    imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $width, $height, $srcWidth, $srcHeight);
    imageinterlace($dstImg, true);
    imagejpeg($dstImg, $dest, 90);
    
    imagedestroy($srcImg);
    imagedestroy($dstImg);
    
    return true;
}

function serveImage($path, $mime) {
    if (!file_exists($path)) {
        header('HTTP/1.0 404 Not Found');
        exit;
    }
    
    $etag = md5_file($path);
    $lastModified = gmdate('D, d M Y H:i:s', filemtime($path)) . ' GMT';
    
    if (
        (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === '"' . $etag . '"') ||
        (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && $_SERVER['HTTP_IF_MODIFIED_SINCE'] === $lastModified)
    ) {
        header('HTTP/1.1 304 Not Modified');
        exit;
    }
    
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('ETag: "' . $etag . '"');
    header('Last-Modified: ' . $lastModified);
    header('Cache-Control: public, max-age=31536000, immutable');
    header('X-Content-Type-Options: nosniff');
    
    readfile($path);
    exit;
}

function serveOriginalImage($album, $image) {
    $album = sanitizePath($album);
    $image = sanitizePath($image);
    
    if (empty($album) || empty($image)) {
        header('HTTP/1.0 400 Bad Request');
        exit;
    }
    
    $path = ALBUMS_DIR . '/' . $album . '/' . $image;
    if (!file_exists($path)) {
        header('HTTP/1.0 404 Not Found');
        exit;
    }
    
    $realPath = realpath($path);
    $realAlbums = realpath(ALBUMS_DIR);
    if (!$realPath || !$realAlbums || strpos($realPath, $realAlbums) !== 0) {
        header('HTTP/1.0 403 Forbidden');
        exit;
    }
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $path);
    finfo_close($finfo);
    
    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];
    if (!in_array($mimeType, $allowedMimes)) {
        header('HTTP/1.0 403 Forbidden');
        exit;
    }
    
    $etag = md5_file($path);
    $lastModified = gmdate('D, d M Y H:i:s', filemtime($path)) . ' GMT';
    
    if (
        (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === '"' . $etag . '"') ||
        (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && $_SERVER['HTTP_IF_MODIFIED_SINCE'] === $lastModified)
    ) {
        header('HTTP/1.1 304 Not Modified');
        exit;
    }
    
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="' . $image . '"');
    header('X-Content-Type-Options: nosniff');
    header('ETag: "' . $etag . '"');
    header('Last-Modified: ' . $lastModified);
    header('Cache-Control: public, max-age=31536000, immutable');
    
    $handle = fopen($path, 'rb');
    if ($handle) {
        while (!feof($handle)) {
            echo fread($handle, 8192);
            flush();
        }
        fclose($handle);
    } else {
        readfile($path);
    }
    exit;
}

// ==================== الراوتر ====================
if (isset($_GET['thumb'])) {
    $album = $_GET['a'] ?? '';
    $image = $_GET['i'] ?? '';
    $width = $_GET['w'] ?? DEFAULT_THUMB_WIDTH;
    serveThumbnail($album, $image, $width);
    exit;
}

if (isset($_GET['img'])) {
    $album = $_GET['a'] ?? '';
    $image = $_GET['i'] ?? '';
    serveOriginalImage($album, $image);
    exit;
}

// ==================== منطق الصفحة ====================
$currentAlbum = isset($_GET['album']) ? validateInput($_GET['album']) : null;
$albums = getAlbums();
$pageTitle = SITE_NAME;
$pageDesc = SITE_DESC;
$albumImages = [];

if ($currentAlbum) {
    $albumData = null;
    foreach ($albums as $album) {
        if ($album['slug'] === $currentAlbum) {
            $albumData = $album;
            break;
        }
    }
    
    if ($albumData) {
        $albumImages = getImagesInAlbum($albumData['name']);
        $pageTitle = htmlspecialchars($albumData['name']) . ' — ' . SITE_NAME;
        $pageDesc = 'ألبوم ' . htmlspecialchars($albumData['name']) . ' يحتوي على ' . count($albumImages) . ' صورة';
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <meta name="description" content="<?= htmlspecialchars($pageDesc) ?>">
    
    <!-- Favicon SVG -->
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%234a9eff'%3E%3Cpath d='M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V5h14v14zm-5-7l-3 3.72L9 13l-3 4h12l-4-5z'/%3E%3C/svg%3E">
    <link rel="alternate icon" type="image/png" href="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAACXBIWXMAAAsTAAALEwEAmpwYAAABuklEQVRYhe2XsU7DMBBAX4tYEBMDA2JhQCwMiAWxsPADfAE/wAfwA3wBP8AHsLCwIBYGxMLAgFgYEBMDA2JBVbH0pJ6VXO3ETRqEeNJJae/e+3w+n50kyf+WUuoIWAfmgUlgAOgH+oBeoBvQQqm0ngEv4AW8gjfwCF7AHbgGLsEZOAXH4BAcmPM4kiRZAlaAOWAKGAIGRcRdQFc60HHyDbgHt8A1uATOwAk4FDmxZJrNZrPAErAIzADDIvoBoNceei3fgGtwAc7BIXDQ7XQ6nc5ut9vpdDqrxWLx8D/NP4A+UC/6Er4AN+ACnIFD0T9wB+7EfwmcgVNxB/bMAcr1er2+1Wq1tppMl8vlslI2+wCmRfT7pL8HJ2BfRD9drVaretltJDiW2WQKGBWRj5Hoe0TkNRN5Wxc/mbzR5EOO0Y0Q8V5VfCp5o8kHyPRGJI8lbxhF8LZiROQ1I9EbRvKH/CHHaJJM7wfGsyJfIX7fJPLJJPqmiL5vTy7kdbJn89ls9jRJkhVgBpgAxoR8PwWoWyhFqRQtdAMvwBu4B7fgCpyDU7BnzuH43/UB5gswJQB0LY8AAAAASUVORK5CYII=">
    
    <!-- Open Graph -->
    <meta property="og:title" content="<?= htmlspecialchars($pageTitle) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($pageDesc) ?>">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?= SITE_URL . $_SERVER['REQUEST_URI'] ?>">
    <?php if ($currentAlbum && $albumData && !empty($albumImages)): ?>
    <meta property="og:image" content="<?= SITE_URL ?>/?thumb=1&a=<?= urlencode($albumData['name']) ?>&i=<?= urlencode($albumData['cover']) ?>&w=1200">
    <?php else: ?>
    <meta property="og:image" content="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='1200' height='630' viewBox='0 0 1200 630'%3E%3Crect fill='%230a0a0a' width='1200' height='630'/%3E%3Cg transform='translate(600,315)'%3E%3Cpath fill='%234a9eff' d='M-100 -100h200c11.046 0 20 8.954 20 20v160c0 11.046-8.954 20-20 20h-200c-11.046 0-20-8.954-20-20v-160c0-11.046 8.954-20 20-20zm0 40v160h200v-160h-200zm50 70l-30 37.2L-100 -30l-30 40h120l-40-50z' transform='scale(2)'/%3E%3C/g%3E%3C/svg%3E">
    <?php endif; ?>
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    
    <!-- Performance -->
    <link rel="dns-prefetch" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    
    <!-- CSRF Token -->
    <meta name="csrf-token" content="<?= $_SESSION['csrf_token'] ?>">
    
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;900&display=swap');
        
        /* ==================== Premium Dark Theme Variables ==================== */
        :root {
            /* Deeper, more sophisticated backgrounds */
            --bg-primary: #09090b;
            --bg-secondary: #111115;
            --bg-tertiary: #18181c;
            --bg-card: #131318;
            
            /* High contrast typography */
            --text-primary: #f8f9fa;
            --text-secondary: #a1a1aa;
            --text-tertiary: #71717a;
            
            /* Borders & Glass */
            --border: rgba(255, 255, 255, 0.05);
            --border-hover: rgba(255, 255, 255, 0.12);
            --glass-bg: rgba(9, 9, 11, 0.75);
            --glass-element: rgba(255, 255, 255, 0.03);
            --glass-element-hover: rgba(255, 255, 255, 0.08);
            
            /* Multilayered Premium Shadows */
            --shadow-sm: 0 4px 12px rgba(0, 0, 0, 0.4);
            --shadow-md: 0 8px 24px rgba(0, 0, 0, 0.5), 0 2px 8px rgba(0, 0, 0, 0.3);
            --shadow-lg: 0 16px 40px rgba(0, 0, 0, 0.6), 0 4px 12px rgba(0, 0, 0, 0.4);
            --shadow-xl: 0 24px 48px rgba(0, 0, 0, 0.7), 0 8px 16px rgba(0, 0, 0, 0.5);
            
            /* Accents */
            --accent: #3b82f6;
            --accent-hover: #60a5fa;
            --accent-light: rgba(59, 130, 246, 0.15);
            --accent-lighter: rgba(59, 130, 246, 0.05);
            
            /* Status */
            --danger: #ef4444;
            --overlay: rgba(3, 3, 5, 0.85); /* Darker, slightly tinted overlay */
            
            /* Geometry */
            --radius-sm: 10px;
            --radius: 16px;
            --radius-lg: 24px;
            --radius-pill: 100px;
            
            /* Butter-smooth cubic-bezier transitions */
            --transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            --transition-fast: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
            --font-primary: 'Tajawal', system-ui, sans-serif;
        }
        
        /* ==================== Base ==================== */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: var(--font-primary);
            background: var(--bg-primary);
            color: var(--text-primary);
            line-height: 1.6;
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
            touch-action: pan-x pan-y;
            /* Subtle background noise/gradient for depth */
            background-image: 
                radial-gradient(circle at 15% 50%, rgba(59, 130, 246, 0.03), transparent 25%),
                radial-gradient(circle at 85% 30%, rgba(59, 130, 246, 0.02), transparent 25%);
        }
        
        /* ==================== Header ==================== */
        .header {
            position: sticky;
            top: 0;
            z-index: 100;
            background: var(--glass-bg);
            backdrop-filter: blur(24px) saturate(180%);
            -webkit-backdrop-filter: blur(24px) saturate(180%);
            border-bottom: 1px solid var(--border);
            padding: 1rem;
            animation: slideDown 0.6s var(--transition);
        }
        
        @keyframes slideDown {
            from { transform: translateY(-100%); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1.5rem;
            flex-wrap: wrap;
        }
        
        .logo {
            font-size: clamp(1.4rem, 3vw, 1.8rem);
            font-weight: 900;
            background: linear-gradient(135deg, var(--text-primary), var(--accent-hover));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            transition: var(--transition);
            line-height: 1;
            letter-spacing: 0.5px;
        }
        
        .logo:hover { transform: scale(1.02); }
        .logo .icon { width: 32px; height: 32px; fill: var(--accent); }
        
        /* ==================== Search Box (RTL Fixed) ==================== */
        .search-box {
            flex: 1;
            max-width: 480px;
            position: relative;
            display: flex;
            align-items: center;
        }
        
        .search-input {
            width: 100%;
            /* FIX: RTL Padding - Right: 3rem (for icon), Left: 1.25rem (for text end) */
            padding: 0.85rem 3rem 0.85rem 1.25rem;
            border: 1px solid var(--border);
            border-radius: var(--radius-pill);
            background: var(--bg-tertiary);
            color: var(--text-primary);
            font-size: 1rem;
            font-family: var(--font-primary);
            transition: var(--transition);
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.1);
            direction: rtl;
        }
        
        .search-input:focus {
            outline: none;
            border-color: var(--accent);
            background: var(--bg-secondary);
            box-shadow: 0 0 0 4px var(--accent-light), var(--shadow-md);
            transform: translateY(-1px);
        }
        
        .search-input::placeholder { color: var(--text-tertiary); }
        
        .search-icon {
            position: absolute;
            /* FIX: Positioned on the right for RTL */
            right: 1.25rem; 
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-tertiary);
            pointer-events: none;
            transition: var(--transition);
            width: 20px;
            height: 20px;
            z-index: 1;
        }
        
        .search-input:focus ~ .search-icon { color: var(--accent); }
        
        @media (max-width: 768px) { .search-box { max-width: 100%; } }
        
        /* ==================== Main Container & Breadcrumb ==================== */
        .container {
            max-width: 1400px;
            margin: 2.5rem auto;
            padding: 0 1.5rem;
        }
        
        .breadcrumb {
            margin-bottom: 2.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: var(--text-secondary);
            font-size: 1rem;
            padding: 1rem 1.5rem;
            background: var(--glass-element);
            border: 1px solid var(--border);
            border-radius: var(--radius-pill);
            animation: fadeInUp 0.6s var(--transition);
            backdrop-filter: blur(10px);
        }
        
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .breadcrumb a {
            color: var(--text-primary);
            text-decoration: none;
            font-weight: 700;
            transition: var(--transition);
            padding: 0.2rem 0.5rem;
            border-radius: var(--radius-sm);
        }
        
        .breadcrumb a:hover {
            color: var(--accent);
            background: var(--accent-lighter);
        }
        
        /* ==================== Controls ==================== */
        .controls {
            margin-bottom: 2.5rem;
            display: flex;
            gap: 1.5rem;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            padding: 1.25rem;
            background: var(--glass-element);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            animation: fadeInUp 0.6s var(--transition) 0.1s backwards;
        }
        
        .control-group { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; }
        .control-group span { font-weight: 700; color: var(--text-secondary); margin-left: 0.5rem; }
        
        .control-btn {
            background: transparent;
            border: 1px solid var(--border);
            border-radius: var(--radius-pill);
            padding: 0.6rem 1.25rem;
            cursor: pointer;
            transition: var(--transition);
            color: var(--text-secondary);
            font-size: 0.95rem;
            font-weight: 600;
            font-family: var(--font-primary);
        }
        
        .control-btn:hover {
            color: var(--text-primary);
            background: var(--glass-element-hover);
            border-color: var(--border-hover);
            transform: translateY(-2px);
        }
        
        .control-btn.active {
            background: var(--accent);
            color: #fff;
            border-color: var(--accent);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
        }
        
        /* ==================== Grids (Albums & Images) ==================== */
        .albums-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 2rem;
            animation: fadeIn 0.8s var(--transition);
        }
        
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        
        .album-card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            overflow: hidden;
            transition: var(--transition);
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            display: block;
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border);
            animation: scaleIn 0.6s var(--transition) backwards;
        }
        
        .album-card:nth-child(n) { animation-delay: calc(0.05s * var(--i)); }
        
        @keyframes scaleIn {
            from { opacity: 0; transform: scale(0.95) translateY(10px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }
        
        .album-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--shadow-lg);
            border-color: var(--border-hover);
            background: var(--bg-tertiary);
        }
        
        .album-cover {
            aspect-ratio: 16 / 10;
            overflow: hidden;
            background: var(--bg-secondary);
            position: relative;
        }
        
        .album-cover img {
            width: 100%; height: 100%;
            object-fit: cover;
            transition: transform 0.8s cubic-bezier(0.16, 1, 0.3, 1);
        }
        
        .album-card:hover .album-cover img { transform: scale(1.08); }
        
        .album-info { padding: 1.5rem; }
        .album-name {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--text-primary);
            letter-spacing: 0.2px;
        }
        .album-count { color: var(--text-secondary); font-size: 0.95rem; display: flex; align-items: center; gap: 0.5rem; }
        
        /* Images Grid */
        .images-grid {
            display: grid;
            gap: 1.5rem;
            animation: fadeIn 0.8s var(--transition);
        }
        .images-grid.small { grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 1rem; }
        .images-grid.medium { grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 1.5rem; }
        .images-grid.large { grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 2rem; }
        
        .image-item {
            position: relative;
            aspect-ratio: 1;
            overflow: hidden;
            border-radius: var(--radius);
            background: var(--bg-secondary);
            cursor: pointer;
            transition: var(--transition);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border);
            animation: scaleIn 0.5s var(--transition) backwards;
        }
        .image-item:nth-child(n) { animation-delay: calc(0.03s * var(--i)); }
        
        .image-item::after {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(to top, rgba(0,0,0,0.5), transparent);
            opacity: 0;
            transition: var(--transition);
        }
        
        .image-item:hover {
            transform: translateY(-6px);
            box-shadow: var(--shadow-lg);
            border-color: var(--border-hover);
        }
        .image-item:hover::after { opacity: 1; }
        
        .image-item img {
            width: 100%; height: 100%;
            object-fit: cover;
            transition: transform 0.8s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .image-item:hover img { transform: scale(1.1); }
        
        /* Loading Shimmer effect */
        .image-item.loading {
            background: linear-gradient(110deg, var(--bg-secondary) 8%, var(--bg-tertiary) 18%, var(--bg-secondary) 33%);
            background-size: 200% 100%;
            animation: 1.5s shimmer linear infinite;
        }
        @keyframes shimmer { to { background-position-x: -200%; } }
        
        /* ==================== Refined Lightbox (Premium Feel) ==================== */
        .lightbox {
            position: fixed; inset: 0;
            background: var(--overlay);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            z-index: 1000;
            display: none;
            align-items: center; justify-content: center;
            opacity: 0;
            transition: opacity 0.4s var(--transition);
            touch-action: none;
        }
        .lightbox.active { display: flex; opacity: 1; }
        
        .lightbox-content {
            position: relative;
            width: 100%; height: 100%;
            display: flex; align-items: center; justify-content: center;
        }
        
        .lightbox-image-container {
            width: 90vw; height: 85vh;
            display: flex; align-items: center; justify-content: center;
        }
        
        .lightbox-image {
            max-width: 100%; max-height: 100%;
            object-fit: contain;
            border-radius: var(--radius-sm);
            box-shadow: var(--shadow-xl);
            transition: transform 0.1s ease-out, opacity 0.3s ease;
            cursor: grab;
            user-select: none; -webkit-user-drag: none;
        }
        .lightbox-image.dragging { cursor: grabbing; transition: none; }
        
        /* Floating Pill Controls */
        .lightbox-controls {
            position: fixed;
            bottom: 2.5rem;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 0.5rem;
            background: rgba(20, 20, 24, 0.65);
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            padding: 0.5rem 1rem;
            border-radius: var(--radius-pill);
            box-shadow: var(--shadow-lg), inset 0 1px 0 rgba(255,255,255,0.1);
            border: 1px solid var(--border);
            z-index: 1001;
        }
        
        .lightbox-btn {
            background: transparent;
            border: none;
            border-radius: 50%;
            width: 44px; height: 44px;
            color: var(--text-primary);
            cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: var(--transition);
        }
        .lightbox-btn:hover {
            background: var(--glass-element-hover);
            transform: translateY(-2px);
        }
        
        /* Modern Floating Nav & Close Buttons */
        .lightbox-nav, .lightbox-close {
            position: fixed;
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(12px);
            border: 1px solid var(--border);
            border-radius: 50%;
            width: 56px; height: 56px;
            display: flex; align-items: center; justify-content: center;
            color: white;
            cursor: pointer;
            transition: var(--transition);
            z-index: 1002;
        }
        
        .lightbox-nav:hover, .lightbox-close:hover {
            background: rgba(255, 255, 255, 0.15);
            transform: scale(1.05);
            border-color: var(--border-hover);
        }
        
        .lightbox-nav { top: 50%; transform: translateY(-50%); }
        .lightbox-nav:hover { transform: translateY(-50%) scale(1.1); }
        .lightbox-prev { left: 2rem; }
        .lightbox-next { right: 2rem; }
        
        .lightbox-close { top: 2rem; right: 2rem; }
        .lightbox-close:hover { background: var(--danger); border-color: var(--danger); }
        
        @media (max-width: 768px) {
            .lightbox-controls { bottom: 1.5rem; padding: 0.4rem 0.8rem; }
            .lightbox-btn { width: 38px; height: 38px; }
            .lightbox-nav { width: 44px; height: 44px; }
            .lightbox-prev { left: 1rem; }
            .lightbox-next { right: 1rem; }
            .lightbox-close { top: 1rem; right: 1rem; width: 44px; height: 44px; }
        }
        
        /* Lightbox Spinner (Modernized) */
        .lightbox-spinner {
            position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
            width: 60px; height: 60px;
            display: none;
        }
        .lightbox-spinner.active { display: block; }
        .lightbox-spinner::after {
            content: ''; position: absolute; inset: 0;
            border: 3px solid rgba(255,255,255,0.1);
            border-top-color: var(--accent);
            border-radius: 50%;
            animation: spin 0.8s ease-in-out infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        
        .loading-text {
            position: absolute; top: calc(50% + 50px); left: 50%; transform: translateX(-50%);
            color: var(--text-secondary); font-size: 0.95rem; font-weight: 500;
            display: none; letter-spacing: 0.5px;
        }
        .loading-text.active { display: block; animation: pulse 1.5s infinite; }
        
        /* ==================== SVG Icons ==================== */
        .icon { width: 22px; height: 22px; fill: currentColor; transition: var(--transition-fast); }
        
        /* ==================== Empty State ==================== */
        .empty-state {
            text-align: center; padding: 6rem 1rem;
            color: var(--text-secondary); animation: fadeInUp 0.8s var(--transition);
        }
        .empty-icon { font-size: 4rem; margin-bottom: 1.5rem; opacity: 0.5; }
        .empty-title { font-size: 1.5rem; font-weight: 700; color: var(--text-primary); margin-bottom: 0.5rem; }
        
        /* ==================== Toast ==================== */
        .toast {
            position: fixed; bottom: 2rem; left: 50%; transform: translateX(-50%);
            background: var(--text-primary); color: var(--bg-primary);
            padding: 0.8rem 1.5rem; border-radius: var(--radius-pill);
            box-shadow: var(--shadow-lg); z-index: 2000;
            font-weight: 600; font-size: 0.95rem;
            animation: slideUpToast 0.4s var(--transition);
        }
        @keyframes slideUpToast { from { transform: translate(-50%, 100%); opacity: 0; } to { transform: translate(-50%, 0); opacity: 1; } }
    </style>
</head>
<body>
    <!-- الهيدر -->
    <header class="header">
        <div class="header-content">
            <a href="?" class="logo">
                <svg class="icon" viewBox="0 0 24 24">
                    <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V5h14v14zm-5-7l-3 3.72L9 13l-3 4h12l-4-5z"/>
                </svg>
                <?= SITE_NAME ?>
            </a>
            
            <div class="search-box">
                <svg class="search-icon icon" viewBox="0 0 24 24">
                    <path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/>
                </svg>
                <input type="text" class="search-input" id="searchInput" placeholder="ابحث عن الصور أو الألبومات..." autocomplete="off">
            </div>
        </div>
    </header>
    
    <!-- المحتوى الرئيسي -->
    <main class="container">
        <?php if ($currentAlbum && $albumData): ?>
            <!-- صفحة الألبوم -->
            <nav class="breadcrumb">
                <a href="?">الرئيسية</a>
                <span style="opacity: 0.5;">/</span>
                <span style="font-weight: 700; color: var(--text-primary);"><?= htmlspecialchars($albumData['name']) ?></span>
            </nav>
            
            <div class="controls">
                <div class="control-group">
                    <span>حجم العرض:</span>
                    <button class="control-btn grid-size" data-size="small">صغير</button>
                    <button class="control-btn grid-size active" data-size="medium">متوسط</button>
                    <button class="control-btn grid-size" data-size="large">كبير</button>
                </div>
                
                <div class="control-group">
                    <span>الترتيب:</span>
                    <button class="control-btn sort-btn active" data-sort="newest">الأحدث</button>
                    <button class="control-btn sort-btn" data-sort="oldest">الأقدم</button>
                    <button class="control-btn sort-btn" data-sort="name">أ - ي</button>
                </div>
            </div>
            
            <?php if (empty($albumImages)): ?>
                <div class="empty-state">
                    <div class="empty-icon">📂</div>
                    <h2 class="empty-title">لا توجد صور في هذا الألبوم</h2>
                    <p>هذا الألبوم فارغ حالياً، الرجاء إضافة صور إلى المجلد.</p>
                </div>
            <?php else: ?>
                <div class="images-grid medium" id="imagesGrid">
                    <?php foreach ($albumImages as $index => $image): ?>
                        <div class="image-item loading" 
                             style="--i: <?= $index ?>"
                             data-album="<?= htmlspecialchars($albumData['name']) ?>"
                             data-image="<?= htmlspecialchars($image) ?>"
                             data-index="<?= $index ?>">
                            <img 
                                data-src="?thumb=1&a=<?= urlencode($albumData['name']) ?>&i=<?= urlencode($image) ?>&w=520"
                                alt="<?= htmlspecialchars($image) ?>"
                                loading="lazy"
                            >
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
        <?php else: ?>
            <!-- الصفحة الرئيسية -->
            <?php if (empty($albums)): ?>
                <div class="empty-state">
                    <div class="empty-icon">📁</div>
                    <h2 class="empty-title">لا توجد ألبومات</h2>
                    <p>قم بإنشاء مجلدات داخل مجلد albums للبدء.</p>
                </div>
            <?php else: ?>
                <div class="albums-grid">
                    <?php foreach ($albums as $index => $album): ?>
                        <a href="?album=<?= urlencode($album['slug']) ?>" class="album-card" style="--i: <?= $index ?>">
                            <div class="album-cover">
                                <img 
                                    data-src="?thumb=1&a=<?= urlencode($album['name']) ?>&i=<?= urlencode($album['cover']) ?>&w=400"
                                    alt="<?= htmlspecialchars($album['name']) ?>"
                                    loading="lazy"
                                >
                            </div>
                            <div class="album-info">
                                <h3 class="album-name"><?= htmlspecialchars($album['name']) ?></h3>
                                <p class="album-count">
                                    <svg class="icon" style="width:16px; height:16px;" viewBox="0 0 24 24"><path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"/></svg>
                                    <?= $album['count'] ?> صورة
                                </p>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>
    
    <!-- اللايت بوكس المحسن -->
    <div class="lightbox" id="lightbox">
        <button class="lightbox-close" id="closeBtn" aria-label="إغلاق">
            <svg class="icon" viewBox="0 0 24 24">
                <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
            </svg>
        </button>

        <button class="lightbox-nav lightbox-prev" id="prevBtn" aria-label="السابق">
            <svg class="icon" viewBox="0 0 24 24">
                <path d="M14.71 15.71L10.83 12l3.88-3.71c.39-.39.39-1.02 0-1.41-.39-.39-1.02-.39-1.41 0l-4.59 4.59c-.39.39-.39 1.02 0 1.41l4.59 4.59c.39.39 1.02.39 1.41 0 .38-.39.38-1.03-.01-1.42z"/>
            </svg>
        </button>
        
        <div class="lightbox-content">
            <div class="lightbox-image-container" id="imageContainer">
                <img class="lightbox-image" id="lightboxImage" alt="">
                <div class="lightbox-spinner" id="lightboxSpinner"></div>
                <div class="loading-text" id="loadingText">جاري المعالجة...</div>
            </div>
            
            <div class="lightbox-controls">
                <button class="lightbox-btn" id="zoomBtn" aria-label="تكبير">
                    <svg class="icon" viewBox="0 0 24 24"><path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14zM12 10h-2v2H9v-2H7V9h2V7h1v2h2v1z"/></svg>
                </button>
                <button class="lightbox-btn" id="downloadBtn" aria-label="تحميل">
                    <svg class="icon" viewBox="0 0 24 24"><path d="M19 9h-4V3H9v6H5l7 7 7-7zm-14 9v2h14v-2H5z"/></svg>
                </button>
                <button class="lightbox-btn" id="shareBtn" aria-label="مشاركة">
                    <svg class="icon" viewBox="0 0 24 24"><path d="M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7c.05-.23.09-.46.09-.7s-.04-.47-.09-.7l7.05-4.11c.54.5 1.25.81 2.04.81 1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3c0 .24.04.47.09.7L8.04 9.81C7.5 9.31 6.79 9 6 9c-1.66 0-3 1.34-3 3s1.34 3 3 3c.79 0 1.5-.31 2.04-.81l7.12 4.16c-.05.21-.08.43-.08.65 0 1.61 1.31 2.92 2.92 2.92 1.61 0 2.92-1.31 2.92-2.92s-1.31-2.92-2.92-2.92z"/></svg>
                </button>
                <button class="lightbox-btn" id="slideshowBtn" aria-label="عرض شرائح">
                    <svg class="icon" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                </button>
                <button class="lightbox-btn" id="fullscreenBtn" aria-label="ملء الشاشة">
                    <svg class="icon" viewBox="0 0 24 24"><path d="M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z"/></svg>
                </button>
            </div>
        </div>

        <button class="lightbox-nav lightbox-next" id="nextBtn" aria-label="التالي">
            <svg class="icon" viewBox="0 0 24 24">
                <path d="M9.29 15.71l3.88-3.71-3.88-3.71c-.39-.39-.39-1.02 0-1.41.39-.39 1.02-.39 1.41 0l4.59 4.59c.39.39.39 1.02 0 1.41l-4.59 4.59c-.39.39-1.02.39-1.41 0-.38-.39-.38-1.03.01-1.42z"/>
            </svg>
        </button>
    </div>
    
    <script>
        'use strict';
        
        const raf = window.requestAnimationFrame || function(cb) { return setTimeout(cb, 16); };
        
        function debounce(func, wait) {
            let timeout;
            return function(...args) {
                clearTimeout(timeout);
                timeout = setTimeout(() => func(...args), wait);
            };
        }
        
        let currentImageIndex = 0;
        let imageElements = [];
        let isPlaying = false;
        let slideshowInterval = null;
        let slideshowDuration = 5000;
        let isZoomed = false;
        let fullImageCache = new Map();
        let zoomLevel = 1;
        let isDragging = false;
        let currentX = 0, currentY = 0, initialX = 0, initialY = 0, xOffset = 0, yOffset = 0;
        
        // Search Fix & Logic
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            const performSearch = debounce(function(query) {
                query = query.toLowerCase();
                raf(() => {
                    document.querySelectorAll('.album-card').forEach(card => {
                        const name = card.querySelector('.album-name').textContent.toLowerCase();
                        if (name.includes(query)) {
                            card.style.display = '';
                            card.style.animation = 'scaleIn 0.4s cubic-bezier(0.16, 1, 0.3, 1)';
                        } else card.style.display = 'none';
                    });
                    document.querySelectorAll('.image-item').forEach(item => {
                        const name = item.dataset.image ? item.dataset.image.toLowerCase() : '';
                        if (name.includes(query)) {
                            item.style.display = '';
                            item.style.animation = 'scaleIn 0.4s cubic-bezier(0.16, 1, 0.3, 1)';
                        } else item.style.display = 'none';
                    });
                });
            }, 300);
            searchInput.addEventListener('input', e => performSearch(e.target.value));
        }
        
        // Grid Size Logic
        const gridButtons = document.querySelectorAll('.grid-size');
        gridButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                const size = btn.dataset.size;
                const grid = document.getElementById('imagesGrid');
                if (!grid) return;
                raf(() => {
                    gridButtons.forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    grid.className = 'images-grid ' + size;
                    localStorage.setItem('gridSize', size);
                });
            });
        });
        
        const savedGridSize = localStorage.getItem('gridSize');
        if (savedGridSize) {
            const grid = document.getElementById('imagesGrid');
            if (grid) {
                grid.className = 'images-grid ' + savedGridSize;
                gridButtons.forEach(btn => {
                    btn.classList.remove('active');
                    if (btn.dataset.size === savedGridSize) btn.classList.add('active');
                });
            }
        }
        
        // Sorting Logic
        const sortButtons = document.querySelectorAll('.sort-btn');
        sortButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                const sort = btn.dataset.sort;
                const grid = document.getElementById('imagesGrid');
                if (!grid) return;
                
                sortButtons.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                
                const items = Array.from(grid.querySelectorAll('.image-item'));
                items.sort((a, b) => {
                    if (sort === 'name') return (a.dataset.image || '').localeCompare(b.dataset.image || '');
                    if (sort === 'oldest') return parseInt(a.dataset.index) - parseInt(b.dataset.index);
                    return parseInt(b.dataset.index) - parseInt(a.dataset.index);
                });
                
                const fragment = document.createDocumentFragment();
                items.forEach((item, index) => {
                    item.style.setProperty('--i', index);
                    fragment.appendChild(item);
                });
                
                raf(() => {
                    grid.innerHTML = '';
                    grid.appendChild(fragment);
                    initLazyLoad();
                });
                localStorage.setItem('sortOrder', sort);
            });
        });
        
        // Lazy Loading
        function initLazyLoad() {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target.querySelector('img') || entry.target;
                        if (!img.src && img.dataset.src) {
                            const tempImg = new Image();
                            tempImg.onload = () => raf(() => {
                                img.src = tempImg.src;
                                if(entry.target.classList.contains('image-item')) entry.target.classList.remove('loading');
                            });
                            tempImg.src = img.dataset.src;
                            observer.unobserve(entry.target);
                        }
                    }
                });
            }, { rootMargin: '50px', threshold: 0.01 });
            
            document.querySelectorAll('.image-item, .album-cover img').forEach(el => observer.observe(el));
        }
        
        // Premium Lightbox Logic
        function openLightbox(index) {
            currentImageIndex = index;
            const lightbox = document.getElementById('lightbox');
            const img = document.getElementById('lightboxImage');
            const spinner = document.getElementById('lightboxSpinner');
            const loadingText = document.getElementById('loadingText');
            const item = imageElements[index];
            if (!item) return;
            
            const album = item.dataset.album;
            const image = item.dataset.image;
            if (!album || !image) return;
            
            lightbox.classList.add('active');
            document.body.style.overflow = 'hidden';
            resetZoom();
            
            const thumbImg = item.querySelector('img');
            
            // تحميل مصغر الصورة إجبارياً في حال لم يتم عمل سكرول له من قبل
            if (thumbImg && !thumbImg.src && thumbImg.dataset.src) {
                thumbImg.src = thumbImg.dataset.src;
                item.classList.remove('loading');
            }

            if (thumbImg && thumbImg.src) {
                img.src = thumbImg.src;
                img.style.opacity = '1';
                spinner.classList.add('active');
                loadingText.classList.add('active');
            } else {
                img.style.opacity = '0';
                spinner.classList.add('active');
                loadingText.classList.add('active');
            }
            
            const fullSrc = '?img=1&a=' + encodeURIComponent(album) + '&i=' + encodeURIComponent(image);
            if (fullImageCache.has(fullSrc)) {
                img.src = fullImageCache.get(fullSrc);
                img.style.opacity = '1'; // ضمان ظهور الصورة في حال كانت محملة مسبقاً
                spinner.classList.remove('active');
                loadingText.classList.remove('active');
            } else {
                const fullImg = new Image();
                fullImg.onload = () => {
                    img.src = fullImg.src;
                    img.style.opacity = '1'; // ضمان ظهور الصورة بعد التحميل
                    spinner.classList.remove('active');
                    loadingText.classList.remove('active');
                    fullImageCache.set(fullSrc, fullImg.src);
                };
                fullImg.onerror = () => {
                    img.style.opacity = '1';
                    spinner.classList.remove('active');
                    loadingText.classList.remove('active');
                    showToast('خطأ في تحميل الصورة');
                };
                fullImg.src = fullSrc;
            }
            preloadAdjacentImages();
        }
        
        function preloadAdjacentImages() {
            [1, -1, 2].forEach(offset => {
                const idx = currentImageIndex + offset;
                if (idx >= 0 && idx < imageElements.length) {
                    const item = imageElements[idx];
                    
                    // تحميل الصور المصغرة المجاورة إجبارياً في شبكة الصور لتجنب المساحات الفارغة
                    const thumbImg = item.querySelector('img');
                    if (thumbImg && !thumbImg.src && thumbImg.dataset.src) {
                        thumbImg.src = thumbImg.dataset.src;
                        item.classList.remove('loading');
                    }

                    const fullSrc = '?img=1&a=' + encodeURIComponent(item.dataset.album) + '&i=' + encodeURIComponent(item.dataset.image);
                    if (!fullImageCache.has(fullSrc)) {
                        const img = new Image();
                        img.onload = () => fullImageCache.set(fullSrc, fullSrc);
                        img.src = fullSrc;
                    }
                }
            });
        }
        
        function closeLightbox() {
            document.getElementById('lightbox').classList.remove('active');
            document.body.style.overflow = '';
            stopSlideshow();
            resetZoom();
        }
        
        function navigateImage(dir) {
            currentImageIndex += dir;
            if (currentImageIndex < 0) currentImageIndex = imageElements.length - 1;
            if (currentImageIndex >= imageElements.length) currentImageIndex = 0;
            openLightbox(currentImageIndex);
        }
        
        // Slideshow, Share, Download, Fullscreen
        function toggleSlideshow() {
            const btn = document.getElementById('slideshowBtn');
            if (isPlaying) {
                isPlaying = false;
                btn.innerHTML = '<svg class="icon" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>';
                clearInterval(slideshowInterval);
            } else {
                isPlaying = true;
                btn.innerHTML = '<svg class="icon" viewBox="0 0 24 24"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>';
                slideshowInterval = setInterval(() => navigateImage(1), slideshowDuration);
            }
        }
        
        function downloadImage() {
            const item = imageElements[currentImageIndex];
            if (!item) return;
            const link = document.createElement('a');
            link.href = '?img=1&a=' + encodeURIComponent(item.dataset.album) + '&i=' + encodeURIComponent(item.dataset.image);
            link.download = item.dataset.image;
            link.click();
        }
        
        function shareImage() {
            if (navigator.share) {
                navigator.share({ title: document.title, url: window.location.href })
                    .catch(() => {});
            } else {
                navigator.clipboard.writeText(window.location.href)
                    .then(() => showToast('تم نسخ الرابط!'));
            }
        }
        
        function toggleFullscreen() {
            const lb = document.getElementById('lightbox');
            if (!document.fullscreenElement) {
                (lb.requestFullscreen || lb.webkitRequestFullscreen).call(lb);
            } else {
                (document.exitFullscreen || document.webkitExitFullscreen).call(document);
            }
        }
        
        function showToast(msg) {
            const toast = document.createElement('div');
            toast.className = 'toast';
            toast.textContent = msg;
            document.body.appendChild(toast);
            setTimeout(() => {
                toast.style.animation = 'slideUpToast 0.4s reverse';
                setTimeout(() => toast.remove(), 400);
            }, 2500);
        }
        
        // Zoom & Drag Logic
        function resetZoom() {
            const img = document.getElementById('lightboxImage');
            zoomLevel = 1; isZoomed = false; xOffset = 0; yOffset = 0;
            img.style.transform = 'scale(1) translate(0,0)';
            img.style.cursor = 'grab';
        }
        
        function setZoom(level) {
            zoomLevel = Math.max(1, Math.min(4, level));
            isZoomed = zoomLevel > 1;
            if(!isZoomed) { xOffset=0; yOffset=0; }
            const img = document.getElementById('lightboxImage');
            img.style.transform = `scale(${zoomLevel}) translate(${xOffset/zoomLevel}px, ${yOffset/zoomLevel}px)`;
            img.style.cursor = isZoomed ? 'grab' : 'default';
        }
        
        function toggleZoom() { setZoom(isZoomed ? 1 : 2.5); }
        
        // Drag Events (Mouse)
        function onDragStart(e) {
            if (!isZoomed) return;
            isDragging = true;
            document.getElementById('lightboxImage').classList.add('dragging');
            initialX = (e.clientX || e.touches[0].clientX) - xOffset;
            initialY = (e.clientY || e.touches[0].clientY) - yOffset;
        }
        function onDragMove(e) {
            if (!isDragging || !isZoomed) return;
            e.preventDefault();
            xOffset = (e.clientX || e.touches[0].clientX) - initialX;
            yOffset = (e.clientY || e.touches[0].clientY) - initialY;
            document.getElementById('lightboxImage').style.transform = `scale(${zoomLevel}) translate(${xOffset/zoomLevel}px, ${yOffset/zoomLevel}px)`;
        }
        function onDragEnd() {
            isDragging = false;
            document.getElementById('lightboxImage').classList.remove('dragging');
        }

        // Init
        document.addEventListener('DOMContentLoaded', () => {
            initLazyLoad();
            imageElements = Array.from(document.querySelectorAll('.image-item'));
            imageElements.forEach((item, i) => item.addEventListener('click', () => openLightbox(i)));
            
            document.getElementById('closeBtn')?.addEventListener('click', closeLightbox);
            document.getElementById('prevBtn')?.addEventListener('click', () => navigateImage(-1));
            document.getElementById('nextBtn')?.addEventListener('click', () => navigateImage(1));
            document.getElementById('zoomBtn')?.addEventListener('click', toggleZoom);
            document.getElementById('downloadBtn')?.addEventListener('click', downloadImage);
            document.getElementById('shareBtn')?.addEventListener('click', shareImage);
            document.getElementById('slideshowBtn')?.addEventListener('click', toggleSlideshow);
            document.getElementById('fullscreenBtn')?.addEventListener('click', toggleFullscreen);
            
            const img = document.getElementById('lightboxImage');
            if (img) {
                img.addEventListener('mousedown', onDragStart);
                document.addEventListener('mousemove', onDragMove);
                document.addEventListener('mouseup', onDragEnd);
                
                img.addEventListener('touchstart', onDragStart, {passive: false});
                img.addEventListener('touchmove', onDragMove, {passive: false});
                img.addEventListener('touchend', onDragEnd);
                
                img.addEventListener('wheel', e => {
                    e.preventDefault();
                    setZoom(zoomLevel * (e.deltaY > 0 ? 0.9 : 1.1));
                }, {passive: false});
            }
            
            // Keyboard Nav
            document.addEventListener('keydown', e => {
                if (!document.getElementById('lightbox').classList.contains('active')) return;
                const keyMap = { 'Escape': closeLightbox, 'ArrowLeft': ()=>navigateImage(1), 'ArrowRight': ()=>navigateImage(-1), ' ': toggleSlideshow, 'z': toggleZoom, 'f': toggleFullscreen };
                if (keyMap[e.key] || keyMap[e.key.toLowerCase()]) { e.preventDefault(); (keyMap[e.key] || keyMap[e.key.toLowerCase()])(); }
            });
            
            // Mobile Swipe Nav
            let touchStartX = 0;
            document.getElementById('lightbox')?.addEventListener('touchstart', e => { if(e.touches.length===1 && !isZoomed) touchStartX = e.touches[0].screenX; }, {passive:true});
            document.getElementById('lightbox')?.addEventListener('touchend', e => {
                if(e.changedTouches.length===1 && !isZoomed) {
                    const diff = touchStartX - e.changedTouches[0].screenX;
                    if (Math.abs(diff) > 50) navigateImage(diff > 0 ? 1 : -1);
                }
            }, {passive:true});
        });
    </script>
</body>
</html>
