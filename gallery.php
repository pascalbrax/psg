<?php
declare(strict_types=1);

$version            = '2.0';
$version_raw_url    = 'https://raw.githubusercontent.com/pascalbrax/psg/master/latest_version';
$version_update_url = 'https://github.com/pascalbrax/psg';

// ── Extension availability ─────────────────────────────────────────────────────
$has_gd   = extension_loaded('gd');
$has_exif = extension_loaded('exif');

// ── Input sanitization ─────────────────────────────────────────────────────────
function sanitize_dir_path(string $v): string {
    $v = str_replace(["\0", "\r", "\n", "\\"], '', $v);
    if (str_contains($v, '..')) return '';
    if ($v !== '' && !preg_match('#^[a-zA-Z0-9/_\-. ]*$#', $v)) return '';
    return trim($v, '/');
}

function sanitize_filename(string $v): string {
    $v = str_replace(["\0", "\r", "\n", "/", "\\"], '', $v);
    if (str_contains($v, '..')) return '';
    if ($v !== '' && !preg_match('#^[a-zA-Z0-9_\-. ]+$#', $v)) return '';
    return $v;
}

$dir   = sanitize_dir_path($_GET['dir']   ?? '');
$thumb = sanitize_filename($_GET['thumb'] ?? '');

// ── Path setup ─────────────────────────────────────────────────────────────────
$script_name = basename($_SERVER['PHP_SELF']);
$script_url  = rtrim(dirname($_SERVER['PHP_SELF']), '/') . '/';
$workdir     = str_replace('\\', '/', rtrim(getcwd(), '/\\'));
$cachefolder = 'cache';
$cache       = is_dir($cachefolder) && is_writable($cachefolder);
$image_exts  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

function resolve_path(string $base, string $sub): string|false {
    $path = $sub !== '' ? $base . '/' . $sub : $base;
    $real = str_replace('\\', '/', (string) realpath($path));
    if ($real === '' || $real === '/') return false;
    if ($real !== $base && !str_starts_with($real, $base . '/')) return false;
    return $real;
}

// ── Helpers ────────────────────────────────────────────────────────────────────
function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function human_filesize(int $bytes, int $dec = 1): string {
    $units  = ['B', 'KB', 'MB', 'GB'];
    $factor = min(3, max(0, (int) floor((strlen((string) max(1, $bytes)) - 1) / 3)));
    return sprintf("%.{$dec}f %s", $bytes / (1024 ** $factor), $units[$factor]);
}

function get_image_meta(string $path, bool $has_exif): array {
    $m = ['date' => '', 'camera' => ''];
    if (!$has_exif) return $m;
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg'], true)) return $m;
    $exif = @exif_read_data($path);
    if (!$exif) return $m;
    if (!empty($exif['DateTimeOriginal'])) {
        $dt = DateTime::createFromFormat('Y:m:d H:i:s', $exif['DateTimeOriginal']);
        if ($dt) $m['date'] = $dt->format('d M Y');
    }
    $make  = trim((string) ($exif['Make']  ?? ''));
    $model = trim((string) ($exif['Model'] ?? ''));
    if ($make && str_starts_with($model, $make)) $model = trim(substr($model, strlen($make)));
    if ($make || $model) $m['camera'] = trim("$make $model");
    return $m;
}

function generate_thumb(string $path, bool $has_exif): \GdImage|false {
    $tw = 220; $th = 165;
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $src = match ($ext) {
        'png'  => @imagecreatefrompng($path),
        'gif'  => @imagecreatefromgif($path),
        'webp' => @imagecreatefromwebp($path),
        default => @imagecreatefromjpeg($path),
    };
    if (!$src) return false;

    if ($has_exif && in_array($ext, ['jpg', 'jpeg'], true)) {
        $exif = @exif_read_data($path);
        if (!empty($exif['Orientation'])) {
            $src = match ((int) $exif['Orientation']) {
                3 => imagerotate($src, 180, 0),
                6 => imagerotate($src, -90, 0),
                8 => imagerotate($src,  90, 0),
                default => $src,
            };
        }
    }

    $ow = imagesx($src); $oh = imagesy($src);
    if ($ow / $oh > $tw / $th) { $th = (int) round($tw * $oh / $ow); }
    else                        { $tw = (int) round($th * $ow / $oh); }

    $dst = imagecreatetruecolor($tw, $th);
    if (in_array($ext, ['png', 'gif', 'webp'], true)) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $ow, $oh);
    imagedestroy($src);
    return $dst;
}

// ── Thumbnail endpoint ─────────────────────────────────────────────────────────
if ($thumb !== '') {
    if (!$has_gd) {
        http_response_code(503);
        header('Content-Type: image/svg+xml');
        echo '<svg xmlns="http://www.w3.org/2000/svg" width="220" height="165"><rect width="220" height="165" fill="#e5e5f0"/>'
           . '<text x="110" y="89" text-anchor="middle" font-family="sans-serif" font-size="13" fill="#888">GD unavailable</text></svg>';
        exit();
    }

    $fulldir  = resolve_path($workdir, $dir);
    if ($fulldir === false) { http_response_code(403); exit(); }

    $img_real = str_replace('\\', '/', (string) realpath($fulldir . '/' . $thumb));
    if ($img_real === '' || !str_starts_with($img_real, $workdir . '/')) {
        http_response_code(403); exit();
    }
    $ext = strtolower(pathinfo($img_real, PATHINFO_EXTENSION));
    if (!in_array($ext, $image_exts, true)) { http_response_code(403); exit(); }

    header('Content-Type: image/jpeg');
    header('Cache-Control: public, max-age=86400');
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 86400) . ' GMT');
    header('X-Content-Type-Options: nosniff');

    $cfile = $cache ? "{$cachefolder}/t_" . md5("{$dir}/{$thumb}") . '.jpg' : null;
    if ($cfile && file_exists($cfile)) { readfile($cfile); exit(); }

    $img = generate_thumb($img_real, $has_exif);
    if ($img) {
        if ($cfile) { imagejpeg($img, $cfile, 85); readfile($cfile); }
        else        { imagejpeg($img, null, 85); }
        imagedestroy($img);
    }
    exit();
}

// ── Directory listing ──────────────────────────────────────────────────────────
$fulldir = resolve_path($workdir, $dir);
if ($fulldir === false) { $fulldir = $workdir; $dir = ''; }

$dir_path = $fulldir . '/';
$entries  = array_diff(scandir($dir_path, SCANDIR_SORT_ASCENDING) ?: [], ['.', '..', $cachefolder, $script_name]);
$subdirs  = $images = [];

foreach ($entries as $entry) {
    $p = $dir_path . $entry;
    if (is_dir($p)) {
        $subdirs[] = $entry;
    } elseif (is_file($p)) {
        $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
        if (in_array($ext, $image_exts, true) && !str_ends_with(strtolower($entry), '.filepart'))
            $images[] = $entry;
    }
}

$image_data = [];
foreach ($images as $img) {
    $p    = $dir_path . $img;
    $meta = get_image_meta($p, $has_exif);
    $link = $script_url . ($dir ? ltrim($dir, '/') . '/' : '') . rawurlencode($img);
    $image_data[$img] = [
        'link'   => $link,
        'size'   => (int) filesize($p),
        'date'   => $meta['date'],
        'camera' => $meta['camera'],
    ];
}

$js_imgs = array_values(array_map(fn($img) => [
    'src'  => $image_data[$img]['link'],
    'name' => $img,
], $images));
$js_imgs_json = json_encode($js_imgs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES);

$crumbs = [['label' => 'root', 'url' => $script_name]];
if ($dir) {
    $acc = '';
    foreach (explode('/', $dir) as $seg) {
        if ($seg === '') continue;
        $acc = $acc ? "$acc/$seg" : $seg;
        $crumbs[] = ['label' => $seg, 'url' => $script_name . '?dir=' . rawurlencode($acc)];
    }
}

// ── SVG icons ──────────────────────────────────────────────────────────────────
function svg(string $t): string {
    return match ($t) {
        'home'     => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>',
        'folder'   => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M10 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>',
        'grid'     => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/></svg>',
        'list'     => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><rect x="3" y="3" width="6" height="6" rx="1"/><rect x="11" y="4.5" width="10" height="2" rx="1"/><rect x="11" y="7.5" width="7" height="1.5" rx="1"/><rect x="3" y="13" width="6" height="6" rx="1"/><rect x="11" y="14.5" width="10" height="2" rx="1"/><rect x="11" y="17.5" width="7" height="1.5" rx="1"/></svg>',
        'camera'   => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 15.2a3.2 3.2 0 1 0 0-6.4 3.2 3.2 0 0 0 0 6.4zM9 2 7.17 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2h-3.17L15 2H9zm3 15a5 5 0 1 1 0-10 5 5 0 0 1 0 10z"/></svg>',
        'calendar' => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20 3h-1V1h-2v2H7V1H5v2H4c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 18H4V8h16v13z"/></svg>',
        'close'    => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M19 6.41 17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>',
        'prev'     => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M15.41 7.41 14 6l-6 6 6 6 1.41-1.41L10.83 12z"/></svg>',
        'next'     => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M10 6 8.59 7.41 13.17 12l-4.58 4.59L10 18l6-6z"/></svg>',
        // show moon in light mode (→ click to go dark), sun in dark mode (→ click to go light)
        'moon'     => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 3a9 9 0 1 0 9 9c0-.46-.04-.92-.1-1.36a5.39 5.39 0 0 1-4.4 2.26 5.4 5.4 0 0 1-3.14-9.8c-.44-.06-.9-.1-1.36-.1z"/></svg>',
        'sun'      => '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 7a5 5 0 1 0 0 10A5 5 0 0 0 12 7zM2 13h2a1 1 0 0 0 0-2H2a1 1 0 0 0 0 2zm18 0h2a1 1 0 0 0 0-2h-2a1 1 0 0 0 0 2zM11 2v2a1 1 0 0 0 2 0V2a1 1 0 0 0-2 0zm0 18v2a1 1 0 0 0 2 0v-2a1 1 0 0 0-2 0zM5.99 4.58a1 1 0 0 0-1.41 1.41l1.06 1.06a1 1 0 0 0 1.41-1.41zm12.37 12.37a1 1 0 0 0-1.41 1.41l1.06 1.06a1 1 0 0 0 1.41-1.41zm1.06-10.96a1 1 0 0 0-1.41-1.41l-1.06 1.06a1 1 0 0 0 1.41 1.41zM7.05 18.36a1 1 0 0 0-1.41-1.41l-1.06 1.06a1 1 0 0 0 1.41 1.41z"/></svg>',
        default    => '',
    };
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>pSimpleGallery<?= $dir ? ' — ' . e($dir) : '' ?></title>
<meta name="referrer" content="no-referrer">
<!-- Set theme before paint to avoid flash -->
<script>
(function () {
  var s = localStorage.getItem('psg_theme');
  var d = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  document.documentElement.dataset.theme = s || d;
}());
</script>
<style>
/* ── Tokens ───────────────────────────────────────────────────────────────── */
:root {
  --bg:        #f0f0f7;
  --surface:   #ffffff;
  --card-hov:  #eeeef8;
  --primary:   #5558d4;
  --text:      #1a1a2e;
  --muted:     #66668a;
  --border:    #ddddf0;
  --shd-sm:    0 1px 4px rgba(0,0,0,.07);
  --shd:       0 4px 14px rgba(0,0,0,.11);
  --radius:    10px;
  --radius-sm: 6px;
}
/* Dark palette applied by JS (or CSS fallback below) */
[data-theme="dark"] {
  --bg:       #111118;
  --surface:  #1c1c2e;
  --card-hov: #25253a;
  --primary:  #7b7ef0;
  --text:     #e0e0f2;
  --muted:    #8888aa;
  --border:   #2e2e48;
  --shd-sm:   0 1px 4px rgba(0,0,0,.35);
  --shd:      0 4px 14px rgba(0,0,0,.45);
}
/* CSS-only fallback for prefers-color-scheme (no JS) */
@media (prefers-color-scheme: dark) {
  html:not([data-theme="light"]) {
    --bg:       #111118;
    --surface:  #1c1c2e;
    --card-hov: #25253a;
    --primary:  #7b7ef0;
    --text:     #e0e0f2;
    --muted:    #8888aa;
    --border:   #2e2e48;
    --shd-sm:   0 1px 4px rgba(0,0,0,.35);
    --shd:      0 4px 14px rgba(0,0,0,.45);
  }
}

/* ── Reset ────────────────────────────────────────────────────────────────── */
*,*::before,*::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: system-ui, -apple-system, sans-serif;
  background: var(--bg);
  color: var(--text);
  min-height: 100vh;
  padding: 16px;
  transition: background .2s, color .2s;
}
a { color: var(--primary); text-decoration: none; }
a:hover { text-decoration: underline; }
svg { display: block; }

/* ── Header ───────────────────────────────────────────────────────────────── */
.header {
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: 12px;
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--radius); padding: 12px 16px;
  margin-bottom: 16px; box-shadow: var(--shd-sm);
  transition: background .2s, border-color .2s;
}
.breadcrumb {
  display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
  font-size: .92rem;
}
.breadcrumb a {
  display: inline-flex; align-items: center; gap: 4px;
  color: var(--primary); font-weight: 500;
}
.breadcrumb svg { width: 15px; height: 15px; flex-shrink: 0; }
.sep { color: var(--muted); font-size: .8rem; }
.crumb-current { font-weight: 600; }

/* ── Header controls ──────────────────────────────────────────────────────── */
.hdr-controls { display: flex; gap: 6px; align-items: center; }

.view-btn, .dark-btn {
  display: inline-flex; align-items: center; gap: 5px;
  background: none; border: 1px solid var(--border); border-radius: var(--radius-sm);
  padding: 6px 10px; cursor: pointer; color: var(--muted); font-size: .82rem;
  transition: background .15s, color .15s, border-color .15s;
}
.view-btn svg, .dark-btn svg { width: 14px; height: 14px; }
.view-btn:hover, .view-btn.active,
.dark-btn:hover {
  background: var(--primary); color: #fff; border-color: var(--primary);
}
.view-btn:focus-visible,
.dark-btn:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }

/* Show moon icon in light mode, sun icon in dark mode */
.dark-btn .icon-sun  { display: none; }
.dark-btn .icon-moon { display: flex; align-items: center; }
[data-theme="dark"] .dark-btn .icon-sun  { display: flex; align-items: center; }
[data-theme="dark"] .dark-btn .icon-moon { display: none; }
/* CSS fallback */
@media (prefers-color-scheme: dark) {
  html:not([data-theme="light"]) .dark-btn .icon-sun  { display: flex; align-items: center; }
  html:not([data-theme="light"]) .dark-btn .icon-moon { display: none; }
}

/* ── Section titles ───────────────────────────────────────────────────────── */
.section-title {
  font-size: .73rem; font-weight: 700; text-transform: uppercase;
  letter-spacing: .07em; color: var(--muted); margin: 18px 0 8px;
}

/* ── Directories ──────────────────────────────────────────────────────────── */
.dirs-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
  gap: 10px; margin-bottom: 6px;
}
.dir-card {
  display: flex; flex-direction: column; align-items: center; gap: 8px;
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--radius); padding: 16px 10px; text-align: center;
  color: var(--text); text-decoration: none;
  box-shadow: var(--shd-sm); transition: box-shadow .15s, transform .15s, background .15s;
}
.dir-card svg { width: 36px; height: 36px; color: var(--primary); }
.dir-card:hover {
  background: var(--card-hov); box-shadow: var(--shd);
  transform: translateY(-2px); text-decoration: none;
}
.dir-name {
  font-size: .8rem; font-weight: 500; word-break: break-all;
  overflow: hidden; display: -webkit-box;
  -webkit-line-clamp: 2; -webkit-box-orient: vertical;
}

/* ── Gallery ──────────────────────────────────────────────────────────────── */
.gallery-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
  gap: 14px;
}
.gallery-list { display: none; flex-direction: column; gap: 8px; }
.gallery.list-view .gallery-grid { display: none; }
.gallery.list-view .gallery-list { display: flex; }

/* ── Grid card ────────────────────────────────────────────────────────────── */
.img-card {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--radius); overflow: hidden; cursor: pointer;
  box-shadow: var(--shd-sm); transition: box-shadow .15s, transform .15s;
}
.img-card:hover { box-shadow: var(--shd); transform: translateY(-2px); }
.img-card:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
.img-thumb {
  width: 100%; aspect-ratio: 4/3; object-fit: cover;
  display: block; background: var(--bg);
}
.no-thumb {
  width: 100%; aspect-ratio: 4/3; background: var(--bg);
  display: flex; align-items: center; justify-content: center; color: var(--muted);
}
.no-thumb svg { width: 32px; height: 32px; }
.img-info { padding: 8px 10px; }
.img-name {
  font-size: .8rem; font-weight: 600; white-space: nowrap;
  overflow: hidden; text-overflow: ellipsis; color: var(--text);
}
/* Grid shows only the file size, no EXIF */
.img-size { font-size: .72rem; color: var(--muted); margin-top: 3px; }

/* ── List row ─────────────────────────────────────────────────────────────── */
.img-row {
  display: flex; align-items: center; gap: 14px;
  background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--radius); padding: 10px 14px; cursor: pointer;
  box-shadow: var(--shd-sm); transition: background .15s, box-shadow .15s;
}
.img-row:hover { background: var(--card-hov); box-shadow: var(--shd); }
.img-row:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
.img-row-thumb {
  width: 80px; height: 60px; object-fit: cover;
  border-radius: var(--radius-sm); flex-shrink: 0; background: var(--bg);
}
.img-row-thumb.no-thumb { aspect-ratio: unset; }
.img-row-details { flex: 1; min-width: 0; }
.img-row-name {
  font-size: .88rem; font-weight: 600;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.img-row-meta {
  font-size: .76rem; color: var(--muted); margin-top: 5px;
  display: flex; flex-wrap: wrap; gap: 10px;
}
.meta-row { display: inline-flex; align-items: center; gap: 4px; }
.meta-row svg { width: 11px; height: 11px; flex-shrink: 0; }
.img-row-size { font-size: .76rem; color: var(--muted); white-space: nowrap; flex-shrink: 0; }

/* ── Empty state ──────────────────────────────────────────────────────────── */
.empty { padding: 40px; text-align: center; color: var(--muted); font-size: .9rem; }

/* ── Lightbox ─────────────────────────────────────────────────────────────── */
.lightbox {
  display: none; position: fixed; inset: 0;
  background: rgba(0,0,0,.92); z-index: 1000;
  align-items: center; justify-content: center;
}
.lightbox.open { display: flex; }
.lb-img {
  max-width: min(95vw, 1280px); max-height: 85vh;
  object-fit: contain; border-radius: 4px; display: block;
}
.lb-btn {
  position: fixed; background: rgba(255,255,255,.12); border: none;
  color: #fff; cursor: pointer; border-radius: 6px;
  display: flex; align-items: center; justify-content: center;
  transition: background .15s;
}
.lb-btn:hover { background: rgba(255,255,255,.28); }
.lb-btn:focus-visible { outline: 2px solid #fff; outline-offset: 2px; }
.lb-close { top: 14px; right: 14px; padding: 8px; }
.lb-close svg, .lb-prev svg, .lb-next svg { width: 24px; height: 24px; }
.lb-nav { top: 50%; transform: translateY(-50%); padding: 18px 12px; }
.lb-prev { left: 12px; }
.lb-next { right: 12px; }
.lb-caption {
  position: fixed; bottom: 0; left: 0; right: 0; padding: 14px 24px;
  background: linear-gradient(transparent, rgba(0,0,0,.72));
  color: #fff; font-size: .85rem; text-align: center; pointer-events: none;
}

/* ── Footer ───────────────────────────────────────────────────────────────── */
.footer {
  margin-top: 40px; padding: 14px 4px;
  border-top: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between;
  flex-wrap: wrap; gap: 10px;
}
.footer-badges { display: flex; gap: 6px; flex-wrap: wrap; }
.badge {
  display: inline-flex; align-items: center; gap: 4px;
  font-size: .72rem; font-weight: 600; padding: 3px 8px;
  border-radius: 999px; white-space: nowrap;
}
.badge svg { width: 11px; height: 11px; }
.ok   { background: #d1fae5; color: #065f46; }
.warn { background: #fef3c7; color: #92400e; }
.err  { background: #fee2e2; color: #991b1b; }
[data-theme="dark"] .ok   { background: #064e3b; color: #6ee7b7; }
[data-theme="dark"] .warn { background: #78350f; color: #fcd34d; }
[data-theme="dark"] .err  { background: #7f1d1d; color: #fca5a5; }
@media (prefers-color-scheme: dark) {
  html:not([data-theme="light"]) .ok   { background: #064e3b; color: #6ee7b7; }
  html:not([data-theme="light"]) .warn { background: #78350f; color: #fcd34d; }
  html:not([data-theme="light"]) .err  { background: #7f1d1d; color: #fca5a5; }
}
.footer-right { display: flex; align-items: center; gap: 10px; }
.footer-ver { font-size: .75rem; color: var(--muted); }
.footer-update {
  font-size: .75rem; font-weight: 600;
  color: var(--primary); text-decoration: none;
}
.footer-update:hover { text-decoration: underline; }

/* ── Responsive ───────────────────────────────────────────────────────────── */
@media (max-width: 580px) {
  body { padding: 10px; }
  .gallery-grid { grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 10px; }
  .dirs-grid    { grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); }
  .lb-nav       { display: none; }
  .view-btn span, .dark-btn span { display: none; }
}
</style>
</head>
<body>

<div class="header">
  <nav class="breadcrumb" aria-label="Location">
    <?php foreach ($crumbs as $i => $c): ?>
      <?= $i ? '<span class="sep" aria-hidden="true">›</span>' : '' ?>
      <?php if ($i === count($crumbs) - 1): ?>
        <span class="crumb-current">
          <?= $i === 0 ? svg('home') : '' ?><?= e($c['label']) ?>
        </span>
      <?php else: ?>
        <a href="<?= e($c['url']) ?>">
          <?= $i === 0 ? svg('home') : '' ?><span><?= e($c['label']) ?></span>
        </a>
      <?php endif; ?>
    <?php endforeach; ?>
  </nav>

  <div class="hdr-controls">
    <?php if (!empty($images)): ?>
    <div class="view-toggle" role="group" aria-label="View mode">
      <button class="view-btn active" data-view="grid" title="Grid view">
        <?= svg('grid') ?><span>Grid</span>
      </button>
      <button class="view-btn" data-view="list" title="List view">
        <?= svg('list') ?><span>List</span>
      </button>
    </div>
    <?php endif; ?>
    <button class="dark-btn" id="dark-btn" aria-label="Toggle dark mode">
      <span class="icon-moon"><?= svg('moon') ?></span>
      <span class="icon-sun"><?= svg('sun') ?></span>
    </button>
  </div>
</div>

<!-- Subdirectories -->
<?php if (!empty($subdirs)): ?>
<p class="section-title">Folders</p>
<div class="dirs-grid">
  <?php foreach ($subdirs as $entry):
    $sub = $dir ? "$dir/$entry" : $entry;
  ?>
  <a class="dir-card" href="<?= e($script_name . '?dir=' . rawurlencode($sub)) ?>">
    <?= svg('folder') ?>
    <span class="dir-name"><?= e($entry) ?></span>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Images -->
<?php if (!empty($images)): ?>
<p class="section-title">Images (<?= count($images) ?>)</p>
<div class="gallery" id="gallery">

  <!-- Grid: thumbnail + filename + size only -->
  <div class="gallery-grid">
    <?php foreach ($images as $idx => $img):
      $d    = $image_data[$img];
      $tsrc = e($script_name . '?dir=' . rawurlencode($dir) . '&thumb=' . rawurlencode($img));
    ?>
    <div class="img-card" data-img="<?= $idx ?>" role="button" tabindex="0"
         aria-label="View <?= e($img) ?>">
      <?php if ($has_gd): ?>
        <img class="img-thumb" src="<?= $tsrc ?>" alt="<?= e($img) ?>" loading="lazy">
      <?php else: ?>
        <div class="no-thumb"><?= svg('camera') ?></div>
      <?php endif; ?>
      <div class="img-info">
        <div class="img-name"><?= e($img) ?></div>
        <div class="img-size"><?= human_filesize($d['size']) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- List: thumbnail + filename + size + date + camera -->
  <div class="gallery-list">
    <?php foreach ($images as $idx => $img):
      $d    = $image_data[$img];
      $tsrc = e($script_name . '?dir=' . rawurlencode($dir) . '&thumb=' . rawurlencode($img));
    ?>
    <div class="img-row" data-img="<?= $idx ?>" role="button" tabindex="0"
         aria-label="View <?= e($img) ?>">
      <?php if ($has_gd): ?>
        <img class="img-row-thumb" src="<?= $tsrc ?>" alt="" loading="lazy">
      <?php else: ?>
        <div class="img-row-thumb no-thumb"><?= svg('camera') ?></div>
      <?php endif; ?>
      <div class="img-row-details">
        <div class="img-row-name"><?= e($img) ?></div>
        <div class="img-row-meta">
          <?php if ($d['date']): ?>
            <span class="meta-row"><?= svg('calendar') ?> <?= e($d['date']) ?></span>
          <?php endif; ?>
          <?php if ($d['camera']): ?>
            <span class="meta-row"><?= svg('camera') ?> <?= e($d['camera']) ?></span>
          <?php endif; ?>
        </div>
      </div>
      <span class="img-row-size"><?= human_filesize($d['size']) ?></span>
    </div>
    <?php endforeach; ?>
  </div>

</div>
<?php elseif (empty($subdirs)): ?>
  <p class="empty">This folder is empty.</p>
<?php endif; ?>

<!-- Lightbox -->
<div class="lightbox" id="lightbox" role="dialog" aria-modal="true" aria-label="Image viewer">
  <button class="lb-btn lb-close" aria-label="Close"><?= svg('close') ?></button>
  <button class="lb-btn lb-nav lb-prev" aria-label="Previous"><?= svg('prev') ?></button>
  <img class="lb-img" src="" alt="" tabindex="-1">
  <button class="lb-btn lb-nav lb-next" aria-label="Next"><?= svg('next') ?></button>
  <div class="lb-caption" aria-live="polite"></div>
</div>

<!-- Footer: GD/EXIF status + version -->
<footer class="footer">
  <div class="footer-badges">
    <span class="badge <?= $has_gd   ? 'ok' : 'err'  ?>">
      <?= svg('camera')   ?> GD <?=   $has_gd   ? 'ok' : 'missing' ?>
    </span>
    <span class="badge <?= $has_exif ? 'ok' : 'warn' ?>">
      <?= svg('calendar') ?> EXIF <?= $has_exif ? 'ok' : 'missing' ?>
    </span>
  </div>
  <div class="footer-right">
    <span class="footer-ver">pSimpleGallery <?= e($version) ?></span>
    <a id="ver-link" href="<?= e($version_update_url) ?>" class="footer-update"
       target="_blank" rel="noopener noreferrer" hidden></a>
  </div>
</footer>

<script>
(function () {
  'use strict';

  /* ── Dark mode ──────────────────────────────────────────────────────────── */
  const html    = document.documentElement;
  const darkBtn = document.getElementById('dark-btn');

  darkBtn.addEventListener('click', function () {
    var next = html.dataset.theme === 'dark' ? 'light' : 'dark';
    html.dataset.theme = next;
    localStorage.setItem('psg_theme', next);
  });

  /* ── View toggle ────────────────────────────────────────────────────────── */
  var gallery = document.getElementById('gallery');
  if (gallery) {
    var saved = localStorage.getItem('psg_view') || 'grid';
    if (saved === 'list') {
      gallery.classList.add('list-view');
      document.querySelector('[data-view="list"]').classList.add('active');
      document.querySelector('[data-view="grid"]').classList.remove('active');
    }
    document.querySelectorAll('.view-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        document.querySelectorAll('.view-btn').forEach(function (b) { b.classList.remove('active'); });
        btn.classList.add('active');
        gallery.classList.toggle('list-view', btn.dataset.view === 'list');
        localStorage.setItem('psg_view', btn.dataset.view);
      });
    });
  }

  /* ── Lightbox ───────────────────────────────────────────────────────────── */
  var IMAGES  = <?= $js_imgs_json ?>;
  var current = 0;
  var lb      = document.getElementById('lightbox');
  var lbImg   = lb.querySelector('.lb-img');
  var lbCap   = lb.querySelector('.lb-caption');

  function lbOpen(idx) {
    if (!IMAGES.length) return;
    current    = ((idx % IMAGES.length) + IMAGES.length) % IMAGES.length;
    lbImg.src  = '';
    lbImg.alt  = IMAGES[current].name;
    lbImg.src  = IMAGES[current].src;
    lbCap.textContent = IMAGES[current].name + ' (' + (current + 1) + ' / ' + IMAGES.length + ')';
    lb.classList.add('open');
    document.body.style.overflow = 'hidden';
    lb.querySelector('.lb-close').focus();
  }

  function lbClose() {
    lb.classList.remove('open');
    lbImg.src = '';
    document.body.style.overflow = '';
  }

  document.querySelectorAll('[data-img]').forEach(function (el) {
    el.addEventListener('click', function () { lbOpen(+el.dataset.img); });
    el.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); lbOpen(+el.dataset.img); }
    });
  });

  lb.querySelector('.lb-close').addEventListener('click', lbClose);
  lb.querySelector('.lb-prev').addEventListener('click', function () { lbOpen(current - 1); });
  lb.querySelector('.lb-next').addEventListener('click', function () { lbOpen(current + 1); });
  lb.addEventListener('click', function (e) { if (e.target === lb) lbClose(); });

  document.addEventListener('keydown', function (e) {
    if (!lb.classList.contains('open')) return;
    if (e.key === 'Escape')     lbClose();
    if (e.key === 'ArrowLeft')  lbOpen(current - 1);
    if (e.key === 'ArrowRight') lbOpen(current + 1);
  });

  var touchX = 0;
  lb.addEventListener('touchstart', function (e) { touchX = e.touches[0].clientX; }, { passive: true });
  lb.addEventListener('touchend',   function (e) {
    var dx = e.changedTouches[0].clientX - touchX;
    if (Math.abs(dx) > 50) lbOpen(dx < 0 ? current + 1 : current - 1);
  });

  /* ── Version check (async, cached 24 h in localStorage) ────────────────── */
  (function () {
    var LOCAL   = <?= json_encode($version) ?>;
    var RAW_URL = <?= json_encode($version_raw_url) ?>;
    var UPD_URL = <?= json_encode($version_update_url) ?>;
    var KEY     = 'psg_ver';
    var TTL     = 86400000; // 24 hours

    function showIfNewer(v) {
      v = (v || '').trim();
      if (!v || isNaN(parseFloat(v))) return;
      if (parseFloat(v) > parseFloat(LOCAL)) {
        var el = document.getElementById('ver-link');
        if (el) {
          el.textContent = 'update v' + v + ' available ↗';
          el.removeAttribute('hidden');
        }
      }
    }

    try {
      var cached = JSON.parse(localStorage.getItem(KEY) || 'null');
      if (cached && (Date.now() - cached.ts) < TTL) { showIfNewer(cached.v); return; }
    } catch (_) {}

    fetch(RAW_URL, { cache: 'no-store' })
      .then(function (r) { return r.ok ? r.text() : Promise.reject(); })
      .then(function (v) {
        try { localStorage.setItem(KEY, JSON.stringify({ v: v.trim(), ts: Date.now() })); } catch (_) {}
        showIfNewer(v);
      })
      .catch(function () {}); // network errors are silent
  }());

}());
</script>
</body>
</html>
