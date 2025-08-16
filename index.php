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
    // تعقيم صارم للمسارات مع دعم العربية
    if (empty($path)) return '';
    
    // إزالة محاولات path traversal
    $path = str_replace(['..', "\0", '%00', "\r", "\n"], '', $path);
    
    // السماح بالأحرف العربية والإنجليزية والأرقام
    // نحافظ على المسافات والنقاط والشرطات
    $path = preg_replace('/[^\p{L}\p{N}._\- ]/u', '', $path);
    
    // التحقق من الطول
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
    // إنشاء slug مع دعم العربية
    $slug = preg_replace('/[^\p{L}\p{N}\s-]/u', '', $name);
    $slug = preg_replace('/[\s_]+/', '-', $slug);
    $slug = trim($slug, '-');
    // نحافظ على العربية كما هي
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
        
        $coverPath = ALBUMS_DIR . '/' . $dir . '/cover.jpg';
        $cover = file_exists($coverPath) ? 'cover.jpg' : $images[0];
        
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
    
    // التحقق من المسار الآمن
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
        
        // التحقق من حجم الملف (max 50MB)
        $filePath = $albumPath . '/' . $file;
        if (filesize($filePath) > 50 * 1024 * 1024) continue;
        
        $images[] = $file;
    }
    
    return $images;
}

function serveThumbnail($album, $image, $width) {
    // تعقيم المدخلات
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
    
    // التحقق من المسار الآمن
    $realSource = realpath($sourcePath);
    $realAlbums = realpath(ALBUMS_DIR);
    if (!$realSource || !$realAlbums || strpos($realSource, $realAlbums) !== 0) {
        header('HTTP/1.0 403 Forbidden');
        exit;
    }
    
    // التحقق من نوع الملف
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $sourcePath);
    finfo_close($finfo);
    
    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];
    if (!in_array($mimeType, $allowedMimes)) {
        header('HTTP/1.0 403 Forbidden');
        exit;
    }
    
    $ext = strtolower(pathinfo($image, PATHINFO_EXTENSION));
    $cacheFile = CACHE_DIR . '/' . md5($album . '_' . $image . '_' . $width . '_v4') . '.jpg';
    
    // التحقق من الكاش
    if (file_exists($cacheFile) && filemtime($cacheFile) >= filemtime($sourcePath)) {
        serveImage($cacheFile, 'image/jpeg');
        return;
    }
    
    // توليد المصغر
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
    
    // تحقق من حجم الصورة المعقول
    if ($info[0] > 10000 || $info[1] > 10000) return false;
    
    $srcWidth = $info[0];
    $srcHeight = $info[1];
    $ratio = $srcHeight / $srcWidth;
    $height = round($width * $ratio);
    
    // إنشاء الصورة حسب النوع
    switch ($info['mime']) {
        case 'image/jpeg':
            $srcImg = @imagecreatefromjpeg($source);
            break;
        case 'image/png':
            $srcImg = @imagecreatefrompng($source);
            break;
        case 'image/webp':
            if (function_exists('imagecreatefromwebp')) {
                $srcImg = @imagecreatefromwebp($source);
            } else {
                return false;
            }
            break;
        case 'image/gif':
            $srcImg = @imagecreatefromgif($source);
            break;
        default:
            return false;
    }
    
    if (!$srcImg) return false;
    
    $dstImg = imagecreatetruecolor($width, $height);
    
    // تحسين جودة الـ resampling
    imagecopyresampled($dstImg, $srcImg, 0, 0, 0, 0, $width, $height, $srcWidth, $srcHeight);
    
    // تحسين الجودة مع progressive encoding
    imageinterlace($dstImg, true);
    
    // حفظ بجودة عالية للثمبنيل
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
    
    // التحقق من الكاش
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

function getMimeType($ext) {
    $mimes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'gif' => 'image/gif'
    ];
    return $mimes[$ext] ?? 'application/octet-stream';
}

// خدمة الصورة الأصلية مع دعم Progressive Loading
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
    
    // التحقق من نوع الملف
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $path);
    finfo_close($finfo);
    
    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];
    if (!in_array($mimeType, $allowedMimes)) {
        header('HTTP/1.0 403 Forbidden');
        exit;
    }
    
    // إضافة headers للأداء
    $etag = md5_file($path);
    $lastModified = gmdate('D, d M Y H:i:s', filemtime($path)) . ' GMT';
    
    // التحقق من الكاش
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
    
    // Stream الملف للأداء الأفضل
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
    // البحث عن الألبوم بالـ slug
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
    
    <!-- Fallback PNG لللمتصفحات القديمة -->
    <link rel="alternate icon" type="image/png" href="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAACXBIWXMAAAsTAAALEwEAmpwYAAABuklEQVRYhe2XsU7DMBBAX4tYEBMDA2JhQCwMiAWxsPADfAE/wAfwA3wBP8AHsLCwIBYGxMLAgFgYEBMDA2JBVbH0pJ6VXO3ETRqEeNJJae/e+3w+n50kyf+WUuoIWAfmgUlgAOgH+oBeoBvQQqm0ngEv4AW8gjfwCF7AHbgGLsEZOAXH4BAcmPM4kiRZAlaAOWAKGAIGRcRdQFc60HHyDbgHt8A1uATOwAk4FDmxZJrNZrPAErAIzADDIvoBoNceei3fgGtwAc7BIXDQ7XQ6nc5ut9vpdDqrxWLx8D/NP4A+UC/6Er4AN+ACnIFD0T9wB+7EfwmcgVNxB/bMAcr1er2+1Wq1tppMl8vlslI2+wCmRfT7pL8HJ2BfRD9drVaretltJDiW2WQKGBWRj5Hoe0TkNRN5Wxc/mbzR5EOO0Y0Q8V5VfCp5o8kHyPRGJI8lbxhF8LZiROQ1I9EbRvKH/CHHaJJM7wfGsyJfIX7fJPLJJPqmiL5vTy7kdbJn89ls9jRJkhVgBpgAxoR8PwWoWyhFqRQtdAMvwBu4B7fgCpyDU7BnzuH43/UB5gswJQB0LY8AAAAASUVORK5CYII=">
    <link rel="apple-touch-icon" href="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAACAAAAAgCAYAAABzenr0AAAACXBIWXMAAAsTAAALEwEAmpwYAAABuklEQVRYhe2XsU7DMBBAX4tYEBMDA2JhQCwMiAWxsPADfAE/wAfwA3wBP8AHsLCwIBYGxMLAgFgYEBMDA2JBVbH0pJ6VXO3ETRqEeNJJae/e+3w+n50kyf+WUuoIWAfmgUlgAOgH+oBeoBvQQqm0ngEP4AW8gjfwCF7AHbgGLsEZOAXH4BAcmPM4kiRZAlaAOWAKGAIGRcRdQFc60HHyDbgHt8A1uATOwAk4FDmxZJrNZrPAErAIzADDIvoBoNceei3fgGtwAc7BIXDQ7XQ6nc5ut9vpdDqrxWLx8D/NP4A+UC/6Er4AN+ACnIFD0T9wB+7EfwmcgVNxB/bMAcr1er2+1Wq1tppMl8vlslI2+wCmRfT7pL8HJ2BfRD9drVaretltJDiW2WQKGBWRj5Hoe0TkNRN5Wxc/mbzR5EOO0Y0Q8V5VfCp5o8kHyPRGJI8lbxhF8LZiROQ1I9EbRvKH/CHHaJJM7wfGsyJfIX7fJPLJJPqmiL5vTy7kdbJn89ls9jRJkhVgBpgAxoR8PwWoWyhFqRQtdAMvwBu4B7fgCpyDU7BnzuH43/UB5gswJQB0LY8AAAAASUVORK5CYII="
    
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
    
    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($pageTitle) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($pageDesc) ?>">
    <?php if ($currentAlbum && $albumData && !empty($albumImages)): ?>
    <meta name="twitter:image" content="<?= SITE_URL ?>/?thumb=1&a=<?= urlencode($albumData['name']) ?>&i=<?= urlencode($albumData['cover']) ?>&w=1200">
    <?php else: ?>
    <meta name="twitter:image" content="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='1200' height='630' viewBox='0 0 1200 630'%3E%3Crect fill='%230a0a0a' width='1200' height='630'/%3E%3Cg transform='translate(600,315)'%3E%3Cpath fill='%234a9eff' d='M-100 -100h200c11.046 0 20 8.954 20 20v160c0 11.046-8.954 20-20 20h-200c-11.046 0-20-8.954-20-20v-160c0-11.046 8.954-20 20-20zm0 40v160h200v-160h-200zm50 70l-30 37.2L-100 -30l-30 40h120l-40-50z' transform='scale(2)'/%3E%3C/g%3E%3C/svg%3E">
    <?php endif; ?>
    
    <!-- Performance -->
    <link rel="dns-prefetch" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://a9ii.com">
    
    <!-- CSRF Token -->
    <meta name="csrf-token" content="<?= $_SESSION['csrf_token'] ?>">
    
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;900&display=swap');
        
        /* ==================== متغيرات الألوان الداكنة ==================== */
        :root {
            --bg-primary: #0a0a0a;
            --bg-secondary: #1a1a1a;
            --bg-tertiary: #252525;
            --bg-card: #161616;
            --text-primary: #f0f0f0;
            --text-secondary: #b8b8b8;
            --text-tertiary: #808080;
            --border: rgba(255, 255, 255, 0.06);
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.4);
            --shadow-md: 0 4px 16px rgba(0, 0, 0, 0.5);
            --shadow-lg: 0 8px 32px rgba(0, 0, 0, 0.6);
            --shadow-xl: 0 20px 60px rgba(0, 0, 0, 0.8);
            --accent: #4a9eff;
            --accent-hover: #357dd8;
            --accent-light: rgba(74, 158, 255, 0.15);
            --accent-lighter: rgba(74, 158, 255, 0.08);
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --glass-bg: rgba(22, 22, 22, 0.85);
            --glass-border: rgba(255, 255, 255, 0.08);
            --overlay: rgba(0, 0, 0, 0.95);
            --radius-sm: 8px;
            --radius: 12px;
            --radius-lg: 20px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --transition-fast: all 0.15s cubic-bezier(0.4, 0, 0.2, 1);
            --font-primary: 'Tajawal', system-ui, sans-serif;
        }
        
        /* ==================== أساسيات ==================== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: var(--font-primary);
            background: var(--bg-primary);
            color: var(--text-primary);
            line-height: 1.7;
            min-height: 100vh;
            position: relative;
            overflow-x: hidden;
            /* منع التكبير على الهاتف */
            touch-action: pan-x pan-y;
        }
        
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 100vh;
            background: radial-gradient(circle at 20% 80%, var(--accent-lighter) 0%, transparent 50%),
                        radial-gradient(circle at 80% 20%, var(--accent-lighter) 0%, transparent 50%);
            pointer-events: none;
            z-index: 0;
        }
        
        /* ==================== الهيدر ==================== */
        .header {
            position: sticky;
            top: 0;
            z-index: 100;
            background: var(--glass-bg);
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            border-bottom: 1px solid var(--border);
            padding: 0.75rem 1rem;
            animation: slideDown 0.5s ease;
        }
        
        @keyframes slideDown {
            from {
                transform: translateY(-100%);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
        }
        
        .logo {
            font-size: clamp(1.25rem, 3vw, 1.75rem);
            font-weight: 900;
            background: linear-gradient(135deg, var(--accent), var(--accent-hover));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            transition: var(--transition);
            position: relative;
            line-height: 1;
        }
        
        .logo:hover {
            transform: scale(1.05);
        }
        
        .logo .icon {
            width: 28px;
            height: 28px;
            fill: var(--accent);
            flex-shrink: 0;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.1); }
        }
        
        .search-box {
            flex: 1;
            max-width: 450px;
            position: relative;
            display: flex;
            align-items: center;
        }
        
        .search-input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 3rem;
            border: 2px solid transparent;
            border-radius: var(--radius-lg);
            background: var(--bg-tertiary);
            color: var(--text-primary);
            font-size: 16px; /* منع التكبير على iOS */
            font-family: var(--font-primary);
            transition: var(--transition);
            box-shadow: var(--shadow-sm);
            direction: rtl;
            text-align: right;
        }
        
        .search-input:focus {
            outline: none;
            border-color: var(--accent);
            background: var(--bg-secondary);
            box-shadow: 0 0 0 4px var(--accent-light), var(--shadow-md);
            transform: translateY(-2px);
        }
        
        .search-input::placeholder {
            color: var(--text-tertiary);
            direction: rtl;
            text-align: right;
        }
        
        .search-icon {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-tertiary);
            pointer-events: none;
            transition: var(--transition);
            width: 20px;
            height: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1;
        }
        
        .search-input:focus ~ .search-icon {
            color: var(--accent);
        }
        
        @media (max-width: 768px) {
            .search-box {
                max-width: 100%;
            }
        }
        
        /* ==================== الحاوية الرئيسية ==================== */
        .container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 1rem;
            position: relative;
            z-index: 1;
        }
        
        /* ==================== شريط التنقل ==================== */
        .breadcrumb {
            margin-bottom: 2rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: var(--text-secondary);
            font-size: 0.95rem;
            padding: 1rem 1.5rem;
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            animation: fadeInUp 0.5s ease;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .breadcrumb a {
            color: var(--accent);
            text-decoration: none;
            font-weight: 600;
            transition: var(--transition);
            position: relative;
        }
        
        .breadcrumb a::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--accent);
            transition: var(--transition);
        }
        
        .breadcrumb a:hover::after {
            width: 100%;
        }
        
        /* ==================== شبكة الألبومات ==================== */
        .albums-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 2rem;
            animation: fadeIn 0.6s ease;
            will-change: transform;
        }
        
        @media (max-width: 768px) {
            .albums-grid {
                grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
                gap: 1rem;
            }
        }
        
        .album-card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            overflow: hidden;
            transition: var(--transition);
            cursor: pointer;
            text-decoration: none;
            color: inherit;
            display: block;
            box-shadow: var(--shadow-md);
            position: relative;
            animation: scaleIn 0.5s ease backwards;
            border: 1px solid var(--border);
            will-change: transform;
        }
        
        .album-card:nth-child(n) {
            animation-delay: calc(0.05s * var(--i));
        }
        
        @keyframes scaleIn {
            from {
                opacity: 0;
                transform: scale(0.8);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }
        
        .album-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, var(--accent-light), transparent);
            opacity: 0;
            transition: var(--transition);
            z-index: 1;
        }
        
        .album-card:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: var(--shadow-xl);
            border-color: var(--accent);
        }
        
        .album-card:hover::before {
            opacity: 1;
        }
        
        .album-card:active {
            transform: scale(0.98);
        }
        
        .album-cover {
            aspect-ratio: 16 / 10;
            overflow: hidden;
            background: var(--bg-tertiary);
            position: relative;
        }
        
        .album-cover img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: var(--transition);
        }
        
        .album-card:hover .album-cover img {
            transform: scale(1.1) rotate(2deg);
        }
        
        .album-info {
            padding: 1.25rem;
            position: relative;
            z-index: 2;
            background: var(--bg-card);
        }
        
        .album-name {
            font-size: 1.15rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--text-primary);
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .album-count {
            color: var(--text-secondary);
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .album-count::before {
            content: '📸';
            font-size: 1.1rem;
        }
        
        /* ==================== أدوات التحكم ==================== */
        .controls {
            margin-bottom: 2rem;
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            padding: 1rem;
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            animation: fadeInUp 0.5s ease 0.1s backwards;
        }
        
        .control-group {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .control-group span {
            font-weight: 600;
            color: var(--text-secondary);
            margin-left: 0.5rem;
        }
        
        .control-btn {
            background: var(--bg-tertiary);
            border: 2px solid transparent;
            border-radius: var(--radius);
            padding: 0.6rem 1.2rem;
            cursor: pointer;
            transition: var(--transition-fast);
            color: var(--text-primary);
            font-size: 0.9rem;
            font-weight: 600;
            font-family: var(--font-primary);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            position: relative;
            overflow: hidden;
        }
        
        .control-btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            background: var(--bg-secondary);
            border-color: var(--border);
        }
        
        .control-btn:active {
            transform: scale(0.95);
        }
        
        .control-btn.active {
            background: var(--accent);
            color: #ffffff;
            border-color: var(--accent);
            box-shadow: 0 0 0 4px var(--accent-light);
            font-weight: 700;
        }
        
        .control-btn.active:hover {
            background: var(--accent-hover);
            border-color: var(--accent-hover);
        }
        
        /* ==================== شبكة الصور ==================== */
        .images-grid {
            display: grid;
            gap: 1.5rem;
            animation: fadeIn 0.6s ease;
            will-change: transform;
        }
        
        .images-grid.small {
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 0.75rem;
        }
        
        .images-grid.medium {
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
        }
        
        .images-grid.large {
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
        }
        
        @media (max-width: 768px) {
            .images-grid.small {
                grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            }
            
            .images-grid.medium {
                grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            }
            
            .images-grid.large {
                grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            }
        }
        
        .image-item {
            position: relative;
            aspect-ratio: 1;
            overflow: hidden;
            border-radius: var(--radius);
            background: var(--bg-tertiary);
            cursor: pointer;
            transition: var(--transition);
            box-shadow: var(--shadow-md);
            animation: zoomIn 0.5s ease backwards;
            border: 1px solid var(--border);
            will-change: transform;
        }
        
        .image-item:nth-child(n) {
            animation-delay: calc(0.03s * var(--i));
        }
        
        @keyframes zoomIn {
            from {
                opacity: 0;
                transform: scale(0.5) rotate(-10deg);
            }
            to {
                opacity: 1;
                transform: scale(1) rotate(0);
            }
        }
        
        .image-item::after {
            content: '🔍';
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) scale(0);
            font-size: 2rem;
            opacity: 0;
            transition: var(--transition);
            z-index: 2;
        }
        
        .image-item:hover {
            transform: scale(1.05) rotate(2deg);
            box-shadow: var(--shadow-xl);
            border-color: var(--accent);
        }
        
        .image-item:hover::after {
            transform: translate(-50%, -50%) scale(1);
            opacity: 1;
        }
        
        .image-item:active {
            transform: scale(0.98);
        }
        
        .image-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: var(--transition);
        }
        
        .image-item:hover img {
            transform: scale(1.2);
            filter: brightness(0.9);
        }
        
        .image-item.loading {
            background: linear-gradient(
                90deg,
                var(--bg-secondary) 0%,
                var(--bg-tertiary) 20%,
                var(--bg-secondary) 40%,
                var(--bg-secondary) 100%
            );
            background-size: 1000px 100%;
            animation: shimmer 2s infinite linear;
        }
        
        @keyframes shimmer {
            0% {
                background-position: -1000px 0;
            }
            100% {
                background-position: 1000px 0;
            }
        }
        
        /* ==================== اللايت بوكس المحسن ==================== */
        .lightbox {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: var(--overlay);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s ease;
            padding: 1rem;
            /* منع التكبير على الهاتف */
            touch-action: none;
        }
        
        .lightbox.active {
            display: flex;
            opacity: 1;
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .lightbox-content {
            position: relative;
            width: 100%;
            height: 100%;
            max-width: 95vw;
            max-height: 95vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        
        .lightbox-image-container {
            position: relative;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        
        .lightbox-image {
            max-width: 100%;
            max-height: 85vh;
            object-fit: contain;
            border-radius: var(--radius);
            box-shadow: var(--shadow-xl);
            transition: transform 0.1s ease, opacity 0.1s ease;
            animation: zoomInImage 0.3s ease;
            cursor: grab;
            transform-origin: center center;
            position: relative;
            /* منع التحديد */
            user-select: none;
            -webkit-user-select: none;
            -webkit-user-drag: none;
        }
        
        .lightbox-image.dragging {
            cursor: grabbing;
            transition: none;
        }
        
        .lightbox-image.loading {
            filter: blur(5px);
            opacity: 0.6;
        }
        
        @keyframes zoomInImage {
            from {
                transform: scale(0.8);
                opacity: 0;
            }
            to {
                transform: scale(1);
                opacity: 1;
            }
        }
        
        /* Loading spinner للايت بوكس - محسّن */
        .lightbox-spinner {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 80px;
            height: 80px;
            z-index: 10;
            display: none;
        }
        
        .lightbox-spinner.active {
            display: block;
        }
        
        /* الدائرة الخارجية الثابتة */
        .lightbox-spinner::before {
            content: '';
            position: absolute;
            width: 100%;
            height: 100%;
            border: 3px solid rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            box-shadow: 0 0 20px rgba(74, 158, 255, 0.2);
        }
        
        /* الدائرة المتحركة */
        .lightbox-spinner::after {
            content: '';
            position: absolute;
            width: 100%;
            height: 100%;
            border: 3px solid transparent;
            border-top-color: var(--accent);
            border-right-color: var(--accent);
            border-radius: 50%;
            animation: spinLoader 0.8s cubic-bezier(0.5, 0, 0.5, 1) infinite;
            box-shadow: 0 0 20px var(--accent);
        }
        
        /* دائرة داخلية إضافية للتأثير */
        .lightbox-spinner .inner-circle {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 40px;
            height: 40px;
            border: 2px solid transparent;
            border-left-color: var(--accent-hover);
            border-bottom-color: var(--accent-hover);
            border-radius: 50%;
            animation: spinLoaderReverse 1s cubic-bezier(0.5, 0, 0.5, 1) infinite;
        }
        
        @keyframes spinLoader {
            0% {
                transform: rotate(0deg);
            }
            100% {
                transform: rotate(360deg);
            }
        }
        
        @keyframes spinLoaderReverse {
            0% {
                transform: translate(-50%, -50%) rotate(0deg);
            }
            100% {
                transform: translate(-50%, -50%) rotate(-360deg);
            }
        }
        
        /* نص التحميل */
        .loading-text {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, calc(-50% + 65px));
            color: var(--accent);
            font-size: 1rem;
            font-weight: 700;
            display: none;
            animation: pulse 1.5s ease-in-out infinite;
            text-shadow: 0 2px 10px rgba(0,0,0,0.5);
            background: var(--glass-bg);
            padding: 10px 24px;
            border-radius: var(--radius-lg);
            backdrop-filter: blur(10px);
            border: 1px solid var(--accent-light);
        }
        
        .loading-text.active {
            display: block;
        }
        
        @keyframes pulse {
            0%, 100% {
                opacity: 0.8;
                transform: translate(-50%, calc(-50% + 60px)) scale(0.95);
            }
            50% {
                opacity: 1;
                transform: translate(-50%, calc(-50% + 60px)) scale(1);
            }
        }
        
        .lightbox-controls {
            position: fixed;
            bottom: 2rem;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 0.75rem;
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            padding: 0.75rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            z-index: 1001;
            border: 1px solid var(--border);
        }
        
        @media (max-width: 768px) {
            .lightbox-controls {
                bottom: 1rem;
                gap: 0.5rem;
                padding: 0.5rem;
                width: calc(100% - 2rem);
                max-width: 400px;
                justify-content: center;
            }
        }
        
        .lightbox-btn {
            background: var(--bg-tertiary);
            border: none;
            border-radius: var(--radius);
            padding: 0.75rem;
            cursor: pointer;
            transition: var(--transition-fast);
            color: var(--text-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            width: 44px;
            height: 44px;
            position: relative;
            overflow: hidden;
        }
        
        .lightbox-btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background: var(--accent);
            border-radius: 50%;
            transform: translate(-50%, -50%);
            transition: var(--transition);
        }
        
        .lightbox-btn:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-lg);
            background: var(--bg-secondary);
        }
        
        .lightbox-btn:hover::before {
            width: 100%;
            height: 100%;
        }
        
        .lightbox-btn:hover .icon {
            fill: white;
            transform: scale(1.2);
            z-index: 1;
        }
        
        .lightbox-btn:active {
            transform: scale(0.95);
        }
        
        .lightbox-close {
            position: fixed;
            top: 1rem;
            right: 1rem;
            background: var(--danger);
            z-index: 1002;
        }
        
        .lightbox-close:hover {
            background: var(--danger);
            transform: rotate(90deg);
        }
        
        .lightbox-nav {
            position: fixed;
            top: 50%;
            transform: translateY(-50%);
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1rem;
            cursor: pointer;
            transition: var(--transition);
            color: var(--text-primary);
            opacity: 0.7;
            z-index: 1001;
        }
        
        .lightbox-nav:hover {
            opacity: 1;
            background: var(--accent);
            color: white;
            transform: translateY(-50%) scale(1.1);
            border-color: var(--accent);
        }
        
        .lightbox-prev {
            left: 1rem;
        }
        
        .lightbox-prev:hover {
            transform: translateY(-50%) translateX(-3px) scale(1.1);
        }
        
        .lightbox-next {
            right: 1rem;
        }
        
        .lightbox-next:hover {
            transform: translateY(-50%) translateX(3px) scale(1.1);
        }
        
        @media (max-width: 768px) {
            .lightbox-nav {
                padding: 0.75rem;
                opacity: 0.5;
            }
            
            .lightbox-prev {
                left: 0.5rem;
            }
            
            .lightbox-next {
                right: 0.5rem;
            }
        }
        
        /* ==================== حالات فارغة ==================== */
        .empty-state {
            text-align: center;
            padding: 4rem 1rem;
            color: var(--text-secondary);
            animation: fadeInUp 0.6s ease;
        }
        
        .empty-icon {
            font-size: 5rem;
            margin-bottom: 1rem;
            animation: bounce 2s infinite;
        }
        
        @keyframes bounce {
            0%, 100% {
                transform: translateY(0);
            }
            50% {
                transform: translateY(-20px);
            }
        }
        
        .empty-title {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--text-primary);
        }
        
        .empty-state p {
            font-size: 1.1rem;
            opacity: 0.8;
        }
        
        /* ==================== تقليل الحركة ==================== */
        @media (prefers-reduced-motion: reduce) {
            * {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
        }
        
        /* ==================== أيقونات SVG ==================== */
        .icon {
            width: 20px;
            height: 20px;
            fill: currentColor;
            transition: var(--transition-fast);
            position: relative;
            display: inline-block;
            vertical-align: middle;
        }
        
        .header .logo .icon {
            width: 28px;
            height: 28px;
        }
        
        .lightbox-btn .icon,
        .lightbox-nav .icon {
            width: 24px;
            height: 24px;
        }
        
        /* ==================== Loading Spinner ==================== */
        .spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid var(--border);
            border-radius: 50%;
            border-top-color: var(--accent);
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* ==================== Toast Notifications ==================== */
        .toast {
            position: fixed;
            bottom: 2rem;
            left: 50%;
            transform: translateX(-50%);
            background: var(--accent);
            color: white;
            padding: 1rem 2rem;
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
            z-index: 2000;
            animation: slideUp 0.3s ease;
            font-weight: 600;
        }
        
        @keyframes slideUp {
            from {
                transform: translateX(-50%) translateY(100%);
                opacity: 0;
            }
            to {
                transform: translateX(-50%) translateY(0);
                opacity: 1;
            }
        }
        
        @keyframes slideDownToast {
            from {
                transform: translateX(-50%) translateY(0);
                opacity: 1;
            }
            to {
                transform: translateX(-50%) translateY(100%);
                opacity: 0;
            }
        }
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
                <a href="?">🏠 الرئيسية</a>
                <span>←</span>
                <span>📁 <?= htmlspecialchars($albumData['name']) ?></span>
            </nav>
            
            <div class="controls">
                <div class="control-group">
                    <span>📐 الحجم:</span>
                    <button class="control-btn grid-size" data-size="small">صغير</button>
                    <button class="control-btn grid-size active" data-size="medium">متوسط</button>
                    <button class="control-btn grid-size" data-size="large">كبير</button>
                </div>
                
                <div class="control-group">
                    <span>🔄 الترتيب:</span>
                    <button class="control-btn sort-btn active" data-sort="newest">الأحدث</button>
                    <button class="control-btn sort-btn" data-sort="oldest">الأقدم</button>
                    <button class="control-btn sort-btn" data-sort="name">الاسم</button>
                </div>
            </div>
            
            <?php if (empty($albumImages)): ?>
                <div class="empty-state">
                    <div class="empty-icon">📷</div>
                    <h2 class="empty-title">لا توجد صور في هذا الألبوم</h2>
                    <p>الألبوم فارغ حالياً، قم بإضافة صور للمجلد</p>
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
                    <h2 class="empty-title">لا توجد ألبومات بعد</h2>
                    <p>قم بإضافة مجلدات تحتوي على صور في مجلد albums</p>
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
                                <p class="album-count"><?= $album['count'] ?> صورة</p>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>
    
    <!-- اللايت بوكس المحسن -->
    <div class="lightbox" id="lightbox">
        <div class="lightbox-content">
            <div class="lightbox-image-container" id="imageContainer">
                <img class="lightbox-image" id="lightboxImage" alt="">
                <div class="lightbox-spinner" id="lightboxSpinner">
                    <div class="inner-circle"></div>
                </div>
                <div class="loading-text" id="loadingText">جاري التحميل...</div>
            </div>
            
            <button class="lightbox-nav lightbox-prev" id="prevBtn" aria-label="السابق">
                <svg class="icon" viewBox="0 0 24 24">
                    <path d="M14.71 15.71L10.83 12l3.88-3.71c.39-.39.39-1.02 0-1.41-.39-.39-1.02-.39-1.41 0l-4.59 4.59c-.39.39-.39 1.02 0 1.41l4.59 4.59c.39.39 1.02.39 1.41 0 .38-.39.38-1.03-.01-1.42z"/>
                </svg>
            </button>
            
            <button class="lightbox-nav lightbox-next" id="nextBtn" aria-label="التالي">
                <svg class="icon" viewBox="0 0 24 24">
                    <path d="M9.29 15.71l3.88-3.71-3.88-3.71c-.39-.39-.39-1.02 0-1.41.39-.39 1.02-.39 1.41 0l4.59 4.59c.39.39.39 1.02 0 1.41l-4.59 4.59c-.39.39-1.02.39-1.41 0-.38-.39-.38-1.03.01-1.42z"/>
                </svg>
            </button>
            
            <button class="lightbox-btn lightbox-close" id="closeBtn" aria-label="إغلاق">
                <svg class="icon" viewBox="0 0 24 24">
                    <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
                </svg>
            </button>
            
            <div class="lightbox-controls">
                <button class="lightbox-btn" id="zoomBtn" aria-label="تكبير">
                    <svg class="icon" viewBox="0 0 24 24">
                        <path d="M15.5 14h-.79l-.28-.27A6.471 6.471 0 0 0 16 9.5 6.5 6.5 0 1 0 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14zM12 10h-2v2H9v-2H7V9h2V7h1v2h2v1z"/>
                    </svg>
                </button>
                
                <button class="lightbox-btn" id="downloadBtn" aria-label="تحميل">
                    <svg class="icon" viewBox="0 0 24 24">
                        <path d="M19 9h-4V3H9v6H5l7 7 7-7zm-14 9v2h14v-2H5z"/>
                    </svg>
                </button>
                
                <button class="lightbox-btn" id="shareBtn" aria-label="مشاركة">
                    <svg class="icon" viewBox="0 0 24 24">
                        <path d="M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7c.05-.23.09-.46.09-.7s-.04-.47-.09-.7l7.05-4.11c.54.5 1.25.81 2.04.81 1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3c0 .24.04.47.09.7L8.04 9.81C7.5 9.31 6.79 9 6 9c-1.66 0-3 1.34-3 3s1.34 3 3 3c.79 0 1.5-.31 2.04-.81l7.12 4.16c-.05.21-.08.43-.08.65 0 1.61 1.31 2.92 2.92 2.92 1.61 0 2.92-1.31 2.92-2.92s-1.31-2.92-2.92-2.92z"/>
                    </svg>
                </button>
                
                <button class="lightbox-btn" id="slideshowBtn" aria-label="عرض شرائح">
                    <svg class="icon" viewBox="0 0 24 24">
                        <path d="M8 5v14l11-7z"/>
                    </svg>
                </button>
                
                <button class="lightbox-btn" id="fullscreenBtn" aria-label="ملء الشاشة">
                    <svg class="icon" viewBox="0 0 24 24">
                        <path d="M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z"/>
                    </svg>
                </button>
            </div>
        </div>
    </div>
    
    <script>
        'use strict';
        
        // ==================== الأداء - requestAnimationFrame ====================
        const raf = window.requestAnimationFrame || function(callback) {
            return setTimeout(callback, 16);
        };
        
        // ==================== Debounce للأداء ====================
        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }
        
        // ==================== متغيرات عامة ====================
        let currentImageIndex = 0;
        let imageElements = [];
        let isPlaying = false;
        let slideshowInterval = null;
        let slideshowDuration = 5000;
        let isZoomed = false;
        let imageCache = new Map();
        let fullImageCache = new Map();
        let zoomLevel = 1;
        let isDragging = false;
        let currentX = 0;
        let currentY = 0;
        let initialX = 0;
        let initialY = 0;
        let xOffset = 0;
        let yOffset = 0;
        
        // ==================== البحث المحسّن ====================
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            const performSearch = debounce(function(query) {
                query = query.toLowerCase();
                
                // البحث في الألبومات
                raf(() => {
                    const albumCards = document.querySelectorAll('.album-card');
                    albumCards.forEach(function(card) {
                        const name = card.querySelector('.album-name').textContent.toLowerCase();
                        if (name.includes(query)) {
                            card.style.display = '';
                            card.style.animation = 'scaleIn 0.3s ease';
                        } else {
                            card.style.display = 'none';
                        }
                    });
                });
                
                // البحث في الصور
                raf(() => {
                    const imageItems = document.querySelectorAll('.image-item');
                    imageItems.forEach(function(item) {
                        const name = item.dataset.image ? item.dataset.image.toLowerCase() : '';
                        if (name.includes(query)) {
                            item.style.display = '';
                            item.style.animation = 'zoomIn 0.3s ease';
                        } else {
                            item.style.display = 'none';
                        }
                    });
                });
            }, 300);
            
            searchInput.addEventListener('input', function(e) {
                performSearch(e.target.value);
            });
        }
        
        // ==================== حجم الشبكة ====================
        const gridButtons = document.querySelectorAll('.grid-size');
        gridButtons.forEach(function(btn) {
            btn.addEventListener('click', function() {
                const size = btn.dataset.size;
                const grid = document.getElementById('imagesGrid');
                if (!grid) return;
                
                raf(() => {
                    gridButtons.forEach(function(b) {
                        b.classList.remove('active');
                    });
                    btn.classList.add('active');
                    
                    grid.className = 'images-grid ' + size;
                    localStorage.setItem('gridSize', size);
                });
            });
        });
        
        // استرجاع حجم الشبكة المحفوظ
        const savedGridSize = localStorage.getItem('gridSize');
        if (savedGridSize) {
            const grid = document.getElementById('imagesGrid');
            if (grid) {
                grid.className = 'images-grid ' + savedGridSize;
                gridButtons.forEach(function(btn) {
                    btn.classList.remove('active');
                    if (btn.dataset.size === savedGridSize) {
                        btn.classList.add('active');
                    }
                });
            }
        }
        
        // ==================== الفرز ====================
        const sortButtons = document.querySelectorAll('.sort-btn');
        sortButtons.forEach(function(btn) {
            btn.addEventListener('click', function() {
                const sort = btn.dataset.sort;
                const grid = document.getElementById('imagesGrid');
                if (!grid) return;
                
                sortButtons.forEach(function(b) {
                    b.classList.remove('active');
                });
                btn.classList.add('active');
                
                const items = Array.from(grid.querySelectorAll('.image-item'));
                
                items.sort(function(a, b) {
                    if (sort === 'name') {
                        const nameA = a.dataset.image || '';
                        const nameB = b.dataset.image || '';
                        return nameA.localeCompare(nameB);
                    } else if (sort === 'oldest') {
                        return parseInt(a.dataset.index) - parseInt(b.dataset.index);
                    } else {
                        return parseInt(b.dataset.index) - parseInt(a.dataset.index);
                    }
                });
                
                const fragment = document.createDocumentFragment();
                items.forEach(function(item, index) {
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
        
        // ==================== Lazy Loading محسّن ====================
        function initLazyLoad() {
            const imageObserver = new IntersectionObserver(function(entries) {
                entries.forEach(function(entry) {
                    if (entry.isIntersecting) {
                        const item = entry.target;
                        const img = item.querySelector('img');
                        if (img && !img.src && img.dataset.src) {
                            // تحميل الصورة
                            const tempImg = new Image();
                            tempImg.onload = function() {
                                raf(() => {
                                    img.src = tempImg.src;
                                    item.classList.remove('loading');
                                });
                            };
                            tempImg.src = img.dataset.src;
                            imageObserver.unobserve(item);
                        }
                    }
                });
            }, {
                rootMargin: '50px',
                threshold: 0.01
            });
            
            const imageItems = document.querySelectorAll('.image-item');
            imageItems.forEach(function(item) {
                imageObserver.observe(item);
            });
            
            // تحميل أغلفة الألبومات
            const albumCovers = document.querySelectorAll('.album-cover img');
            albumCovers.forEach(function(img) {
                if (!img.src && img.dataset.src) {
                    const tempImg = new Image();
                    tempImg.onload = function() {
                        raf(() => {
                            img.src = tempImg.src;
                        });
                    };
                    tempImg.src = img.dataset.src;
                }
            });
        }
        
        // ==================== اللايت بوكس المحسن مع التحميل الفوري ====================
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
            
            if (album && image) {
                lightbox.classList.add('active');
                document.body.style.overflow = 'hidden';
                
                // إعادة تعيين الزووم والموضع
                resetZoom();
                
                // استخدام الثمبنيل الموجود كبريفيو فوري (لأنه محمل بالفعل)
                const thumbImg = item.querySelector('img');
                if (thumbImg && thumbImg.src) {
                    // عرض الثمبنيل فوراً بدون تأخير
                    img.src = thumbImg.src;
                    img.style.opacity = '1';
                    img.classList.add('loading');
                    
                    // عرض السبينر لكن بشكل خفيف
                    spinner.classList.add('active');
                    loadingText.textContent = 'جاري تحسين الجودة...';
                    loadingText.classList.add('active');
                } else {
                    // في حالة عدم وجود ثمبنيل
                    img.style.opacity = '0';
                    spinner.classList.add('active');
                    loadingText.classList.add('active');
                }
                
                // الآن نحمل الصورة الكاملة مباشرة (بدون preview متوسط)
                const fullSrc = '?img=1&a=' + encodeURIComponent(album) + '&i=' + encodeURIComponent(image);
                
                // تحقق من الكاش أولاً
                if (fullImageCache.has(fullSrc)) {
                    // إذا كانت في الكاش، اعرضها فوراً
                    img.src = fullImageCache.get(fullSrc);
                    img.classList.remove('loading');
                    img.style.opacity = '1';
                    spinner.classList.remove('active');
                    loadingText.classList.remove('active');
                } else {
                    // تحميل الصورة الكاملة
                    const fullImg = new Image();
                    
                    fullImg.onload = function() {
                        // عرض الصورة الكاملة بسرعة
                        img.src = fullImg.src;
                        img.classList.remove('loading');
                        img.style.opacity = '1';
                        spinner.classList.remove('active');
                        loadingText.classList.remove('active');
                        fullImageCache.set(fullSrc, fullImg.src);
                    };
                    
                    fullImg.onerror = function() {
                        img.classList.remove('loading');
                        img.style.opacity = '1';
                        spinner.classList.remove('active');
                        loadingText.classList.remove('active');
                        showToast('خطأ في تحميل الصورة');
                    };
                    
                    // بدء التحميل
                    fullImg.src = fullSrc;
                }
                
                // Preload adjacent images
                preloadAdjacentImages();
            }
        }
        
        function preloadAdjacentImages() {
            // تحميل استباقي للصور المجاورة
            const preloadIndexes = [1, -1, 2, -2]; // ترتيب الأولوية
            
            preloadIndexes.forEach((offset) => {
                const index = currentImageIndex + offset;
                if (index >= 0 && index < imageElements.length) {
                    const item = imageElements[index];
                    const album = item.dataset.album;
                    const image = item.dataset.image;
                    
                    if (album && image) {
                        const fullSrc = '?img=1&a=' + encodeURIComponent(album) + '&i=' + encodeURIComponent(image);
                        
                        // تحميل الصورة الكاملة مباشرة إذا لم تكن في الكاش
                        if (!fullImageCache.has(fullSrc)) {
                            const img = new Image();
                            img.onload = function() {
                                fullImageCache.set(fullSrc, fullSrc);
                            };
                            img.src = fullSrc;
                        }
                    }
                }
            });
        }
        
        function closeLightbox() {
            const lightbox = document.getElementById('lightbox');
            lightbox.classList.remove('active');
            document.body.style.overflow = '';
            stopSlideshow();
            resetZoom();
        }
        
        function navigateImage(direction) {
            currentImageIndex += direction;
            if (currentImageIndex < 0) currentImageIndex = imageElements.length - 1;
            if (currentImageIndex >= imageElements.length) currentImageIndex = 0;
            
            const img = document.getElementById('lightboxImage');
            const spinner = document.getElementById('lightboxSpinner');
            const loadingText = document.getElementById('loadingText');
            const item = imageElements[currentImageIndex];
            
            if (item) {
                const album = item.dataset.album;
                const image = item.dataset.image;
                
                if (album && image) {
                    // استخدام الثمبنيل الموجود كبريفيو فوري
                    const thumbImg = item.querySelector('img');
                    if (thumbImg && thumbImg.src) {
                        img.src = thumbImg.src;
                        img.style.opacity = '1';
                        img.classList.add('loading');
                    } else {
                        img.style.opacity = '0.3';
                    }
                    
                    // عرض السبينر
                    spinner.classList.add('active');
                    loadingText.textContent = 'جاري تحسين الجودة...';
                    loadingText.classList.add('active');
                    
                    const fullSrc = '?img=1&a=' + encodeURIComponent(album) + '&i=' + encodeURIComponent(image);
                    
                    // تحقق من الكاش
                    if (fullImageCache.has(fullSrc)) {
                        img.src = fullImageCache.get(fullSrc);
                        img.classList.remove('loading');
                        img.style.opacity = '1';
                        spinner.classList.remove('active');
                        loadingText.classList.remove('active');
                    } else {
                        const fullImg = new Image();
                        
                        fullImg.onload = function() {
                            img.src = fullImg.src;
                            img.classList.remove('loading');
                            img.style.opacity = '1';
                            spinner.classList.remove('active');
                            loadingText.classList.remove('active');
                            fullImageCache.set(fullSrc, fullImg.src);
                        };
                        
                        fullImg.onerror = function() {
                            img.classList.remove('loading');
                            img.style.opacity = '1';
                            spinner.classList.remove('active');
                            loadingText.classList.remove('active');
                            showToast('خطأ في تحميل الصورة');
                        };
                        
                        fullImg.src = fullSrc;
                    }
                    
                    // إعادة تعيين الزووم
                    resetZoom();
                    
                    // Preload adjacent
                    preloadAdjacentImages();
                }
            }
        }
        
        function startSlideshow() {
            isPlaying = true;
            const btn = document.getElementById('slideshowBtn');
            btn.innerHTML = '<svg class="icon" viewBox="0 0 24 24"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>';
            
            slideshowInterval = setInterval(function() {
                navigateImage(1);
            }, slideshowDuration);
        }
        
        function stopSlideshow() {
            isPlaying = false;
            const btn = document.getElementById('slideshowBtn');
            btn.innerHTML = '<svg class="icon" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>';
            
            if (slideshowInterval) {
                clearInterval(slideshowInterval);
                slideshowInterval = null;
            }
        }
        
        function toggleSlideshow() {
            if (isPlaying) {
                stopSlideshow();
            } else {
                startSlideshow();
            }
        }
        
        function downloadImage() {
            const item = imageElements[currentImageIndex];
            if (!item) return;
            
            const album = item.dataset.album;
            const image = item.dataset.image;
            
            if (album && image) {
                const link = document.createElement('a');
                link.href = '?img=1&a=' + encodeURIComponent(album) + '&i=' + encodeURIComponent(image);
                link.download = image;
                link.click();
            }
        }
        
        function shareImage() {
            const url = window.location.href;
            if (navigator.share) {
                navigator.share({
                    title: document.title,
                    url: url
                }).then(() => {
                    showToast('تم المشاركة بنجاح!');
                }).catch(() => {});
            } else {
                navigator.clipboard.writeText(url).then(() => {
                    showToast('تم نسخ الرابط!');
                }).catch(() => {
                    showToast('فشل نسخ الرابط');
                });
            }
        }
        
        function showToast(message) {
            const toast = document.createElement('div');
            toast.className = 'toast';
            toast.textContent = message;
            document.body.appendChild(toast);
            
            setTimeout(() => {
                toast.style.animation = 'slideDownToast 0.3s ease';
                setTimeout(() => {
                    document.body.removeChild(toast);
                }, 300);
            }, 2000);
        }
        
        function toggleFullscreen() {
            if (!document.fullscreenElement) {
                const lightbox = document.getElementById('lightbox');
                if (lightbox.requestFullscreen) {
                    lightbox.requestFullscreen();
                } else if (lightbox.webkitRequestFullscreen) {
                    lightbox.webkitRequestFullscreen();
                }
            } else {
                if (document.exitFullscreen) {
                    document.exitFullscreen();
                } else if (document.webkitExitFullscreen) {
                    document.webkitExitFullscreen();
                }
            }
        }
        
        // ==================== وظائف التكبير المحسنة ====================
        function resetZoom() {
            const img = document.getElementById('lightboxImage');
            zoomLevel = 1;
            isZoomed = false;
            xOffset = 0;
            yOffset = 0;
            currentX = 0;
            currentY = 0;
            img.style.transform = 'scale(1) translate(0, 0)';
            img.style.cursor = 'grab';
        }
        
        function setZoom(level, centerX, centerY) {
            const img = document.getElementById('lightboxImage');
            const container = document.getElementById('imageContainer');
            
            zoomLevel = Math.max(1, Math.min(4, level));
            isZoomed = zoomLevel > 1;
            
            if (centerX !== undefined && centerY !== undefined) {
                const rect = container.getBoundingClientRect();
                const x = (centerX - rect.left) / rect.width;
                const y = (centerY - rect.top) / rect.height;
                
                xOffset = (0.5 - x) * rect.width * (zoomLevel - 1);
                yOffset = (0.5 - y) * rect.height * (zoomLevel - 1);
            }
            
            img.style.transform = `scale(${zoomLevel}) translate(${xOffset/zoomLevel}px, ${yOffset/zoomLevel}px)`;
            img.style.cursor = isZoomed ? 'grab' : 'default';
        }
        
        function toggleZoom() {
            if (!isZoomed) {
                setZoom(2);
            } else {
                resetZoom();
            }
        }
        
        // ==================== التحكم باللمس للتكبير والسحب ====================
        let touchStartDistance = 0;
        let touchStartScale = 1;
        
        function handleTouchStart(e) {
            const img = document.getElementById('lightboxImage');
            
            if (e.touches.length === 2) {
                // Pinch zoom
                touchStartDistance = Math.hypot(
                    e.touches[0].clientX - e.touches[1].clientX,
                    e.touches[0].clientY - e.touches[1].clientY
                );
                touchStartScale = zoomLevel;
            } else if (e.touches.length === 1 && isZoomed) {
                // Drag
                isDragging = true;
                img.classList.add('dragging');
                initialX = e.touches[0].clientX - xOffset;
                initialY = e.touches[0].clientY - yOffset;
            }
        }
        
        function handleTouchMove(e) {
            if (e.touches.length === 2) {
                // Pinch zoom
                e.preventDefault();
                const currentDistance = Math.hypot(
                    e.touches[0].clientX - e.touches[1].clientX,
                    e.touches[0].clientY - e.touches[1].clientY
                );
                
                const scale = touchStartScale * (currentDistance / touchStartDistance);
                const centerX = (e.touches[0].clientX + e.touches[1].clientX) / 2;
                const centerY = (e.touches[0].clientY + e.touches[1].clientY) / 2;
                
                setZoom(scale, centerX, centerY);
            } else if (e.touches.length === 1 && isDragging && isZoomed) {
                // Drag
                e.preventDefault();
                currentX = e.touches[0].clientX - initialX;
                currentY = e.touches[0].clientY - initialY;
                xOffset = currentX;
                yOffset = currentY;
                
                const img = document.getElementById('lightboxImage');
                img.style.transform = `scale(${zoomLevel}) translate(${xOffset/zoomLevel}px, ${yOffset/zoomLevel}px)`;
            }
        }
        
        function handleTouchEnd(e) {
            const img = document.getElementById('lightboxImage');
            isDragging = false;
            img.classList.remove('dragging');
            initialX = currentX;
            initialY = currentY;
        }
        
        // ==================== التحكم بالماوس للسحب ====================
        function handleMouseDown(e) {
            if (!isZoomed) return;
            
            const img = document.getElementById('lightboxImage');
            isDragging = true;
            img.classList.add('dragging');
            initialX = e.clientX - xOffset;
            initialY = e.clientY - yOffset;
        }
        
        function handleMouseMove(e) {
            if (!isDragging || !isZoomed) return;
            
            e.preventDefault();
            currentX = e.clientX - initialX;
            currentY = e.clientY - initialY;
            xOffset = currentX;
            yOffset = currentY;
            
            const img = document.getElementById('lightboxImage');
            img.style.transform = `scale(${zoomLevel}) translate(${xOffset/zoomLevel}px, ${yOffset/zoomLevel}px)`;
        }
        
        function handleMouseUp() {
            const img = document.getElementById('lightboxImage');
            isDragging = false;
            img.classList.remove('dragging');
            initialX = currentX;
            initialY = currentY;
        }
        
        // ==================== ربط الأحداث ====================
        document.addEventListener('DOMContentLoaded', function() {
            initLazyLoad();
            
            // جمع عناصر الصور
            imageElements = Array.from(document.querySelectorAll('.image-item'));
            
            // أحداث الصور
            imageElements.forEach(function(item, index) {
                item.addEventListener('click', function() {
                    openLightbox(index);
                });
            });
            
            // أحداث اللايت بوكس
            const closeBtn = document.getElementById('closeBtn');
            if (closeBtn) closeBtn.addEventListener('click', closeLightbox);
            
            const prevBtn = document.getElementById('prevBtn');
            if (prevBtn) prevBtn.addEventListener('click', function() { navigateImage(-1); });
            
            const nextBtn = document.getElementById('nextBtn');
            if (nextBtn) nextBtn.addEventListener('click', function() { navigateImage(1); });
            
            const zoomBtn = document.getElementById('zoomBtn');
            if (zoomBtn) zoomBtn.addEventListener('click', toggleZoom);
            
            const downloadBtn = document.getElementById('downloadBtn');
            if (downloadBtn) downloadBtn.addEventListener('click', downloadImage);
            
            const shareBtn = document.getElementById('shareBtn');
            if (shareBtn) shareBtn.addEventListener('click', shareImage);
            
            const slideshowBtn = document.getElementById('slideshowBtn');
            if (slideshowBtn) slideshowBtn.addEventListener('click', toggleSlideshow);
            
            const fullscreenBtn = document.getElementById('fullscreenBtn');
            if (fullscreenBtn) fullscreenBtn.addEventListener('click', toggleFullscreen);
            
            // أحداث التكبير والسحب
            const img = document.getElementById('lightboxImage');
            if (img) {
                // Touch events
                img.addEventListener('touchstart', handleTouchStart, {passive: false});
                img.addEventListener('touchmove', handleTouchMove, {passive: false});
                img.addEventListener('touchend', handleTouchEnd, {passive: false});
                
                // Mouse events
                img.addEventListener('mousedown', handleMouseDown);
                document.addEventListener('mousemove', handleMouseMove);
                document.addEventListener('mouseup', handleMouseUp);
                
                // Wheel zoom
                img.addEventListener('wheel', function(e) {
                    e.preventDefault();
                    const delta = e.deltaY > 0 ? 0.9 : 1.1;
                    setZoom(zoomLevel * delta, e.clientX, e.clientY);
                }, {passive: false});
            }
            
            // لوحة المفاتيح
            document.addEventListener('keydown', function(e) {
                const lightbox = document.getElementById('lightbox');
                if (!lightbox.classList.contains('active')) return;
                
                switch(e.key) {
                    case 'Escape':
                        closeLightbox();
                        break;
                    case 'ArrowLeft':
                        navigateImage(1);
                        break;
                    case 'ArrowRight':
                        navigateImage(-1);
                        break;
                    case ' ':
                        e.preventDefault();
                        toggleSlideshow();
                        break;
                    case 'z':
                    case 'Z':
                        toggleZoom();
                        break;
                    case 'f':
                    case 'F':
                        toggleFullscreen();
                        break;
                    case 'd':
                    case 'D':
                        downloadImage();
                        break;
                    case '+':
                    case '=':
                        e.preventDefault();
                        setZoom(zoomLevel * 1.2);
                        break;
                    case '-':
                    case '_':
                        e.preventDefault();
                        setZoom(zoomLevel * 0.8);
                        break;
                }
            });
            
            // السحب على الجوال للتنقل
            let touchStartX = 0;
            let touchEndX = 0;
            
            const lightbox = document.getElementById('lightbox');
            if (lightbox) {
                lightbox.addEventListener('touchstart', function(e) {
                    if (e.touches.length === 1 && !isZoomed) {
                        touchStartX = e.changedTouches[0].screenX;
                    }
                }, {passive: true});
                
                lightbox.addEventListener('touchend', function(e) {
                    if (e.changedTouches.length === 1 && !isZoomed) {
                        touchEndX = e.changedTouches[0].screenX;
                        handleSwipe();
                    }
                }, {passive: true});
            }
            
            function handleSwipe() {
                const swipeThreshold = 50;
                const diff = touchStartX - touchEndX;
                
                if (Math.abs(diff) > swipeThreshold) {
                    if (diff > 0) {
                        navigateImage(1);
                    } else {
                        navigateImage(-1);
                    }
                }
            }
            
            // Double tap to zoom on mobile
            let lastTap = 0;
            const imageContainer = document.getElementById('imageContainer');
            imageContainer?.addEventListener('touchend', function(e) {
                if (e.touches.length > 0) return;
                
                const currentTime = new Date().getTime();
                const tapLength = currentTime - lastTap;
                if (tapLength < 300 && tapLength > 0) {
                    e.preventDefault();
                    toggleZoom();
                }
                lastTap = currentTime;
            });
        });
    </script>
</body>
</html>
