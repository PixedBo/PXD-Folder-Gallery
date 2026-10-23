<?php

/**
 * @package     mod_folder_gallery
 * @copyright   (C) Pixed - pixed.it
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Pixed\Module\FolderGallery\Site\Helper;

\defined('_JEXEC') or die;

use Joomla\CMS\Access\Access;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;

/**
 * Helper for mod_folder_gallery.
 *
 * Performance strategy:
 *  - the filesystem is browsed ONE level at a time (no recursion);
 *  - directory listings are cached (Joomla cache) keyed by path + mtime;
 *  - thumbnails are generated on demand for the visible page only and cached on disk;
 *  - the full image is loaded by the lightbox only when the user clicks it.
 *
 * Security strategy:
 *  - every user-supplied path is normalised, checked segment by segment ('.', '..', control
 *    characters) and then confined to the base folder via realpath() + prefix comparison;
 *  - the AJAX endpoints enforce the module instance's access level and publishing window;
 *  - admin-supplied folders (base + cache) are confined to the site root and may not point at
 *    Joomla system directories;
 *  - thumbnail generation is bounded (pixel budget + memory estimate) so a huge image or a
 *    decompression bomb degrades to "serve the original" instead of a fatal error;
 *  - AJAX methods never throw: errors are returned as exceptions so com_ajax wraps them in its
 *    JSON envelope instead of rendering the site error page.
 */
class FolderGalleryHelper
{
    /**
     * Allowed image extensions.
     */
    private const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'];

    /**
     * Default thumbnail cache folder (relative to the site root).
     */
    private const DEFAULT_CACHE_DIR = 'images/cache_folder_gallery';

    /**
     * Joomla system directories that may never be used as base or cache folder.
     */
    private const FORBIDDEN_TOP_DIRS = [
        'administrator', 'api', 'cache', 'cli', 'components', 'includes', 'installation',
        'language', 'layouts', 'libraries', 'logs', 'modules', 'plugins', 'tmp',
    ];

    /**
     * Largest source image (in pixels) that will be decoded for a thumbnail.
     */
    private const MAX_SOURCE_PIXELS = 40000000;

    /**
     * Hard runtime ceilings mirroring the manifest limits (params may be written outside the form).
     */
    private const MIN_THUMB_SIZE = 32;
    private const MAX_THUMB_SIZE = 2000;
    private const MAX_PER_PAGE   = 500;

    /**
     * Builds the data for the initial (top) level rendered server side.
     *
     * @param   Registry                 $params  Module parameters.
     * @param   CMSApplicationInterface  $app     The application.
     *
     * @return  array
     */
    public function getInitialLevel(Registry $params, CMSApplicationInterface $app): array
    {
        $base = $this->getBasePath($params);

        if ($base === null) {
            return [
                'rel'     => '',
                'error'   => Text::_('MOD_FOLDER_GALLERY_ERR_BASE'),
                'folders' => [],
                'images'  => [],
                'page'    => 1,
                'pages'   => 1,
                'total'   => 0,
            ];
        }

        return $this->scanLevel($base, '', $params, 1);
    }

    /**
     * com_ajax entry point.
     *
     * Called via: index.php?option=com_ajax&module=folder_gallery&format=json&method=browse&modid=ID&path=REL&page=N
     *
     * Never throws: com_ajax calls namespaced helpers without a try/catch, so a thrown exception
     * would bypass its JSON envelope. A *returned* exception is rendered as {"success":false,...}.
     *
     * @return  array|\RuntimeException
     */
    public function browseAjax()
    {
        try {
            $this->loadLanguage();

            $input = Factory::getApplication()->getInput();

            $modId = (int) $input->getInt('modid', 0);
            $rel   = $this->normalizeRel((string) $input->getString('path', ''));
            $page  = max(1, (int) $input->getInt('page', 1));

            $params = $this->loadModuleParams($modId);

            if ($params === null) {
                return new \RuntimeException(Text::_('MOD_FOLDER_GALLERY_ERR_MODULE'), 404);
            }

            $base = $this->getBasePath($params);

            if ($base === null) {
                return new \RuntimeException(Text::_('MOD_FOLDER_GALLERY_ERR_BASE'), 500);
            }

            $target = $this->resolveSafePath($base, $rel);

            if ($target === null) {
                return new \RuntimeException(Text::_('MOD_FOLDER_GALLERY_ERR_PATH'), 403);
            }

            return $this->scanLevel($target, $rel, $params, $page);
        } catch (\Throwable $e) {
            return $this->unexpected($e);
        }
    }

    /**
     * com_ajax entry point returning the FULL ordered image list of a folder.
     *
     * Used by the lightbox so it can slide through every image in the folder, not just the
     * page currently rendered in the grid. Returns names + full URLs only: no pagination and
     * no thumbnail generation, so it stays cheap even for folders with hundreds of images
     * (it reuses the cached directory listing).
     *
     * Called via: index.php?option=com_ajax&module=folder_gallery&format=json&method=list&modid=ID&path=REL
     *
     * @return  array|\RuntimeException
     */
    public function listAjax()
    {
        try {
            $this->loadLanguage();

            $input = Factory::getApplication()->getInput();

            $modId = (int) $input->getInt('modid', 0);
            $rel   = $this->normalizeRel((string) $input->getString('path', ''));

            $params = $this->loadModuleParams($modId);

            if ($params === null) {
                return new \RuntimeException(Text::_('MOD_FOLDER_GALLERY_ERR_MODULE'), 404);
            }

            $base = $this->getBasePath($params);

            if ($base === null) {
                return new \RuntimeException(Text::_('MOD_FOLDER_GALLERY_ERR_BASE'), 500);
            }

            $target = $this->resolveSafePath($base, $rel);

            if ($target === null) {
                return new \RuntimeException(Text::_('MOD_FOLDER_GALLERY_ERR_PATH'), 403);
            }

            $images = [];

            foreach ($this->readDirCached($target)['files'] as $fname) {
                $imgRel   = ($rel === '' ? '' : $rel . '/') . $fname;
                $images[] = [
                    'name' => $fname,
                    'full' => $this->webUrlWithinBase($imgRel, $params),
                ];
            }

            return [
                'rel'    => $rel,
                'images' => $images,
            ];
        } catch (\Throwable $e) {
            return $this->unexpected($e);
        }
    }

    /**
     * Logs an unexpected error and returns a generic exception for the JSON envelope
     * (never the original message, which could contain server details).
     *
     * @param   \Throwable  $e  The caught error.
     *
     * @return  \RuntimeException
     */
    private function unexpected(\Throwable $e): \RuntimeException
    {
        try {
            Log::add('mod_folder_gallery: ' . \get_class($e) . ': ' . $e->getMessage(), Log::ERROR);
        } catch (\Throwable $ignored) {
            // Logging must never break the response.
        }

        return new \RuntimeException(Text::_('MOD_FOLDER_GALLERY_ERROR'), 500);
    }

    /**
     * Loads the module language file. com_ajax only does this for legacy helper.php modules,
     * so without it the AJAX error messages would come back as raw language keys.
     *
     * @return  void
     */
    private function loadLanguage(): void
    {
        try {
            Factory::getApplication()->getLanguage()->load('mod_folder_gallery', JPATH_SITE);
        } catch (\Throwable $ignored) {
            // A missing language file only degrades the error texts.
        }
    }

    /**
     * Scans a single folder: returns subfolders + a paginated page of images (with thumbs).
     *
     * @param   string    $absPath  Absolute, validated folder path.
     * @param   string    $rel      Normalised path relative to the base folder ('' = base).
     * @param   Registry  $params   Module parameters.
     * @param   int       $page     Image page (1-based).
     *
     * @return  array
     */
    private function scanLevel(string $absPath, string $rel, Registry $params, int $page): array
    {
        $perPage    = min(self::MAX_PER_PAGE, max(1, (int) $params->get('per_page', 24)));
        $showCounts = (int) $params->get('show_counts', 0) === 1;

        $entries = $this->readDirCached($absPath);

        // The thumbnail cache folder is not an album: hide it if it happens to live inside the base folder.
        $cache     = $this->getCacheBase($params);
        $cacheReal = $cache !== null ? realpath($cache['abs']) : false;

        $folders = [];

        foreach ($entries['dirs'] as $name) {
            if ($cacheReal !== false && realpath($absPath . '/' . $name) === $cacheReal) {
                continue;
            }

            $childRel  = ($rel === '' ? '' : $rel . '/') . $name;
            $folders[] = [
                'name'  => $name,
                'path'  => $childRel,
                'count' => $showCounts ? $this->countImages($absPath . '/' . $name) : null,
            ];
        }

        $all   = $entries['files'];
        $total = \count($all);
        $pages = max(1, (int) ceil($total / $perPage));
        $page  = min($page, $pages);
        $slice = \array_slice($all, ($page - 1) * $perPage, $perPage);

        $images = [];

        foreach ($slice as $fname) {
            $imgRel   = ($rel === '' ? '' : $rel . '/') . $fname;
            $images[] = [
                'name'  => $fname,
                'full'  => $this->webUrlWithinBase($imgRel, $params),
                'thumb' => $this->getThumb($absPath . '/' . $fname, $imgRel, $params),
            ];
        }

        return [
            'rel'     => $rel,
            'folders' => $folders,
            'images'  => $images,
            'page'    => $page,
            'pages'   => $pages,
            'total'   => $total,
        ];
    }

    /**
     * Resolves and validates the configured base folder. Returns its real absolute path or null.
     *
     * @param   Registry  $params  Module parameters.
     *
     * @return  string|null
     */
    private function getBasePath(Registry $params): ?string
    {
        $rel = $this->normalizeRel((string) $params->get('base_folder', 'images'));

        if ($rel === '' || $this->isUnsafeRel($rel) || $this->isForbiddenTopDir($rel)) {
            return null;
        }

        $root = realpath(JPATH_ROOT);

        if ($root === false) {
            return null;
        }

        $real = realpath($root . \DIRECTORY_SEPARATOR . str_replace('/', \DIRECTORY_SEPARATOR, $rel));

        if (
            $real === false
            || !is_dir($real)
            || $real === $root
            || strpos($real, $root . \DIRECTORY_SEPARATOR) !== 0
        ) {
            return null;
        }

        return $real;
    }

    /**
     * Resolves a relative sub-path against the base folder, blocking any traversal outside it.
     *
     * @param   string  $base  Real base path.
     * @param   string  $rel   Normalised relative sub-path.
     *
     * @return  string|null
     */
    private function resolveSafePath(string $base, string $rel): ?string
    {
        $rel = $this->normalizeRel($rel);

        if ($rel === '') {
            return $base;
        }

        if ($this->isUnsafeRel($rel)) {
            return null;
        }

        $real = realpath($base . \DIRECTORY_SEPARATOR . str_replace('/', \DIRECTORY_SEPARATOR, $rel));

        if (
            $real === false
            || !is_dir($real)
            || ($real !== $base && strpos($real, $base . \DIRECTORY_SEPARATOR) !== 0)
        ) {
            return null;
        }

        return $real;
    }

    /**
     * Normalises a relative path: backslashes to slashes, collapsed slashes, no leading/trailing slash.
     *
     * @param   string  $rel  Raw relative path.
     *
     * @return  string
     */
    private function normalizeRel(string $rel): string
    {
        $rel = str_replace('\\', '/', $rel);
        $rel = (string) preg_replace('#/+#', '/', $rel);

        return trim($rel, '/');
    }

    /**
     * Tells whether a normalised relative path is unsafe to use on the filesystem.
     *
     * Rejected: whole '.' / '..' segments and any control character (NUL would make realpath()
     * throw a ValueError on PHP 8). Only whole segments are rejected, NOT the substring '..', so
     * legitimate folder names with consecutive dots (e.g. "Foto..Finali", "2024..2025") are allowed.
     * Containment is still enforced by the realpath check in the callers.
     *
     * @param   string  $rel  Relative path, '/'-separated.
     *
     * @return  bool
     */
    private function isUnsafeRel(string $rel): bool
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $rel)) {
            return true;
        }

        foreach (explode('/', $rel) as $segment) {
            if ($segment === '..' || $segment === '.') {
                return true;
            }
        }

        return false;
    }

    /**
     * Tells whether a normalised relative path starts inside a Joomla system directory.
     *
     * @param   string  $rel  Relative path, '/'-separated.
     *
     * @return  bool
     */
    private function isForbiddenTopDir(string $rel): bool
    {
        $top = strtolower((string) strtok($rel, '/'));

        return \in_array($top, self::FORBIDDEN_TOP_DIRS, true);
    }

    /**
     * Reads a directory through the Joomla cache, keyed by path + mtime.
     *
     * @param   string  $absPath  Absolute folder path.
     *
     * @return  array{dirs: string[], files: string[]}
     */
    private function readDirCached(string $absPath): array
    {
        $id = 'dir_' . md5($absPath . '|' . (int) @filemtime($absPath));

        try {
            $cache = Factory::getContainer()
                ->get(CacheControllerFactoryInterface::class)
                ->createCacheController('callback', ['defaultgroup' => 'mod_folder_gallery']);

            return $cache->get([self::class, 'readDir'], [$absPath], $id);
        } catch (\Throwable $e) {
            // If caching is unavailable for any reason, just read directly.
            return self::readDir($absPath);
        }
    }

    /**
     * Reads a directory and splits it into image files and sub-folders.
     * Static so it can be used as a cache callback without serialising $this.
     *
     * Entries whose name is not valid UTF-8 or contains control characters are skipped: they
     * could not be addressed safely and a single such name would break json_encode() for the
     * whole folder.
     *
     * @param   string  $absPath  Absolute folder path.
     *
     * @return  array{dirs: string[], files: string[]}
     */
    public static function readDir(string $absPath): array
    {
        $dirs  = [];
        $files = [];

        $dh = @opendir($absPath);

        if ($dh === false) {
            return ['dirs' => [], 'files' => []];
        }

        while (($entry = readdir($dh)) !== false) {
            if ($entry === '.' || $entry === '..' || $entry[0] === '.') {
                continue;
            }

            if (!mb_check_encoding($entry, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $entry)) {
                continue;
            }

            $full = $absPath . '/' . $entry;

            if (is_dir($full)) {
                $dirs[] = $entry;
            } else {
                $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));

                if (\in_array($ext, self::IMAGE_EXT, true)) {
                    $files[] = $entry;
                }
            }
        }

        closedir($dh);

        natcasesort($dirs);
        natcasesort($files);

        return ['dirs' => array_values($dirs), 'files' => array_values($files)];
    }

    /**
     * Counts images inside a folder (uses the cached listing).
     *
     * @param   string  $absPath  Absolute folder path.
     *
     * @return  int
     */
    private function countImages(string $absPath): int
    {
        return \count($this->readDirCached($absPath)['files']);
    }

    /**
     * Resolves and validates the thumbnail cache folder (admin param `cache_dir`).
     *
     * The folder may not exist yet (it is created on first use), so containment is checked
     * textually here and again with realpath() in ensureCacheDir() once it exists.
     *
     * @param   Registry  $params  Module parameters.
     *
     * @return  array{rel: string, abs: string}|null  Site-relative path + absolute path, or null if invalid.
     */
    private function getCacheBase(Registry $params): ?array
    {
        $rel = $this->normalizeRel((string) $params->get('cache_dir', self::DEFAULT_CACHE_DIR));

        if ($rel === '') {
            $rel = self::DEFAULT_CACHE_DIR;
        }

        if ($this->isUnsafeRel($rel) || $this->isForbiddenTopDir($rel)) {
            return null;
        }

        $root = realpath(JPATH_ROOT);

        if ($root === false) {
            return null;
        }

        return [
            'rel' => $rel,
            'abs' => $root . \DIRECTORY_SEPARATOR . str_replace('/', \DIRECTORY_SEPARATOR, $rel),
        ];
    }

    /**
     * Creates the cache folder (+ shard) if needed and verifies it really lives inside the site root.
     *
     * @param   string  $cacheAbs  Absolute cache folder path (may not exist yet).
     * @param   string  $shard     Shard sub-folder name (2 hex chars).
     *
     * @return  string|null  The real, writable shard directory or null.
     */
    private function ensureCacheDir(string $cacheAbs, string $shard): ?string
    {
        if (!is_dir($cacheAbs)) {
            @mkdir($cacheAbs, 0755, true);
        }

        $real = realpath($cacheAbs);
        $root = realpath(JPATH_ROOT);

        if (
            $real === false
            || $root === false
            || !is_dir($real)
            || $real === $root
            || strpos($real, $root . \DIRECTORY_SEPARATOR) !== 0
        ) {
            return null;
        }

        // Joomla convention: keep the cache folder from being listed if directory indexes are on.
        $index = $real . \DIRECTORY_SEPARATOR . 'index.html';

        if (!is_file($index)) {
            @file_put_contents($index, "<!DOCTYPE html><title></title>\n");
        }

        $dir = $real . \DIRECTORY_SEPARATOR . $shard;

        if (!is_dir($dir)) {
            @mkdir($dir, 0755);
        }

        return (is_dir($dir) && is_writable($dir)) ? $dir : null;
    }

    /**
     * Returns a cached thumbnail URL, generating it on first access.
     * Falls back to the original image URL if the cache folder is invalid, GD is missing,
     * the image is too large, or generation fails.
     *
     * @param   string    $imgAbs  Absolute path of the source image.
     * @param   string    $imgRel  Path relative to the base folder.
     * @param   Registry  $params  Module parameters.
     *
     * @return  string
     */
    private function getThumb(string $imgAbs, string $imgRel, Registry $params): string
    {
        $fallback = $this->webUrlWithinBase($imgRel, $params);
        $cache    = $this->getCacheBase($params);

        if ($cache === null || !\function_exists('imagecreatetruecolor')) {
            return $fallback;
        }

        $w    = min(self::MAX_THUMB_SIZE, max(self::MIN_THUMB_SIZE, (int) $params->get('thumb_width', 500)));
        $h    = min(self::MAX_THUMB_SIZE, max(self::MIN_THUMB_SIZE, (int) $params->get('thumb_height', 500)));
        $crop = (int) $params->get('crop', 1) === 1;
        $ext  = \function_exists('imagewebp') ? 'webp' : 'jpg';

        // Keyed on the ABSOLUTE source path so two module instances with different base folders
        // (but identical relative paths) can never share a thumbnail.
        $mtime = (int) @filemtime($imgAbs);
        $hash  = md5($imgAbs . '|' . $w . 'x' . $h . '|' . ($crop ? 'c' : 'f') . '|' . $mtime);
        $shard = substr($hash, 0, 2);
        $file  = $hash . '.' . $ext;

        $thumbRel = $cache['rel'] . '/' . $shard . '/' . $file;
        $thumbAbs = $cache['abs'] . \DIRECTORY_SEPARATOR . $shard . \DIRECTORY_SEPARATOR . $file;

        if (is_file($thumbAbs)) {
            return Uri::root() . $this->encodePath($thumbRel);
        }

        $dir = $this->ensureCacheDir($cache['abs'], $shard);

        if ($dir !== null && $this->makeThumb($imgAbs, $dir . \DIRECTORY_SEPARATOR . $file, $w, $h, $crop, $ext)) {
            return Uri::root() . $this->encodePath($thumbRel);
        }

        // Fallback: serve the original (still lazy-loaded in the grid).
        return $fallback;
    }

    /**
     * Generates a thumbnail with GD (cover-crop or contain-fit).
     *
     * Refuses (returns false) images above MAX_SOURCE_PIXELS or that would not fit in the
     * remaining memory_limit, so an oversized image or a decompression bomb can never turn
     * into a fatal error.
     *
     * @param   string  $src   Source absolute path.
     * @param   string  $dest  Destination absolute path.
     * @param   int     $tw    Target width.
     * @param   int     $th    Target height.
     * @param   bool    $crop  True for cover-crop, false for contain-fit.
     * @param   string  $ext   Output extension ('webp' or 'jpg').
     *
     * @return  bool
     */
    private function makeThumb(string $src, string $dest, int $tw, int $th, bool $crop, string $ext): bool
    {
        $info = @getimagesize($src);

        if ($info === false) {
            return false;
        }

        [$sw, $sh] = $info;

        if ($sw < 1 || $sh < 1 || ($sw * $sh) > self::MAX_SOURCE_PIXELS || !$this->hasMemoryFor($sw, $sh, $tw, $th)) {
            return false;
        }

        $type = $info[2];
        $img  = false;

        if ($type === IMAGETYPE_JPEG) {
            $img = @imagecreatefromjpeg($src);
        } elseif ($type === IMAGETYPE_PNG) {
            $img = @imagecreatefrompng($src);
        } elseif ($type === IMAGETYPE_GIF) {
            $img = @imagecreatefromgif($src);
        } elseif ($type === IMAGETYPE_WEBP) {
            $img = \function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false;
        } elseif (\defined('IMAGETYPE_AVIF') && $type === IMAGETYPE_AVIF) {
            $img = \function_exists('imagecreatefromavif') ? @imagecreatefromavif($src) : false;
        }

        if (!$img) {
            return false;
        }

        if ($crop) {
            // Cover: scale to fill the box, then centre-crop.
            $scale   = max($tw / $sw, $th / $sh);
            $nw      = (int) ceil($sw * $scale);
            $nh      = (int) ceil($sh * $scale);
            $dx      = (int) (($tw - $nw) / 2);
            $dy      = (int) (($th - $nh) / 2);
            $canvasW = $tw;
            $canvasH = $th;
        } else {
            // Contain: fit inside the box, never upscale.
            $scale   = min($tw / $sw, $th / $sh, 1);
            $nw      = max(1, (int) round($sw * $scale));
            $nh      = max(1, (int) round($sh * $scale));
            $dx      = 0;
            $dy      = 0;
            $canvasW = $nw;
            $canvasH = $nh;
        }

        $dst = imagecreatetruecolor($canvasW, $canvasH);

        if (!$dst) {
            imagedestroy($img);

            return false;
        }

        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $canvasW, $canvasH, $white);

        imagecopyresampled($dst, $img, $dx, $dy, 0, 0, $nw, $nh, $sw, $sh);

        if ($ext === 'webp' && \function_exists('imagewebp')) {
            $ok = @imagewebp($dst, $dest, 82);
        } else {
            $ok = @imagejpeg($dst, $dest, 82);
        }

        imagedestroy($img);
        imagedestroy($dst);

        return $ok && is_file($dest);
    }

    /**
     * Estimates whether decoding a source image + the thumbnail canvas fits in memory_limit.
     *
     * @param   int  $sw  Source width.
     * @param   int  $sh  Source height.
     * @param   int  $tw  Thumbnail width.
     * @param   int  $th  Thumbnail height.
     *
     * @return  bool
     */
    private function hasMemoryFor(int $sw, int $sh, int $tw, int $th): bool
    {
        $limit = $this->memoryLimitBytes();

        if ($limit <= 0) {
            // Unlimited (-1) or unparsable: rely on MAX_SOURCE_PIXELS only.
            return true;
        }

        // GD truecolor ~ 4 bytes/pixel + per-row overhead; 5 bytes/pixel + 4 MB is a safe estimate.
        $needed = (($sw * $sh) + ($tw * $th)) * 5 + 4 * 1024 * 1024;

        return (memory_get_usage(true) + $needed) < $limit;
    }

    /**
     * Parses the memory_limit ini value into bytes (0 = unlimited/unknown).
     *
     * @return  int
     */
    private function memoryLimitBytes(): int
    {
        $value = trim((string) ini_get('memory_limit'));

        if ($value === '' || $value === '-1') {
            return 0;
        }

        $bytes = (int) $value;

        switch (strtolower(substr($value, -1))) {
            case 'g':
                $bytes *= 1024;
                // no break
            case 'm':
                $bytes *= 1024;
                // no break
            case 'k':
                $bytes *= 1024;
        }

        return max(0, $bytes);
    }

    /**
     * Builds the public URL of a path relative to the base folder.
     *
     * @param   string    $relWithinBase  Path relative to the base folder.
     * @param   Registry  $params         Module parameters.
     *
     * @return  string
     */
    private function webUrlWithinBase(string $relWithinBase, Registry $params): string
    {
        $baseRel = $this->normalizeRel((string) $params->get('base_folder', 'images'));
        $path    = $baseRel . '/' . $this->normalizeRel($relWithinBase);

        return Uri::root() . $this->encodePath($path);
    }

    /**
     * URL-encodes each path segment while preserving the slashes.
     *
     * @param   string  $path  Relative path from the site root.
     *
     * @return  string
     */
    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /**
     * Loads the params of a specific module instance by id, enforcing the same visibility rules
     * Joomla applies when rendering it: published, site client, within the publishing window and
     * with an access level the current user holds. Menu assignment is not checked (the AJAX
     * request has no menu context); the access level is the meaningful control.
     *
     * @param   int  $id  Module id.
     *
     * @return  Registry|null
     */
    private function loadModuleParams(int $id): ?Registry
    {
        if ($id < 1) {
            return null;
        }

        $app    = Factory::getApplication();
        $user   = $app->getIdentity();
        $levels = $user !== null ? $user->getAuthorisedViewLevels() : Access::getAuthorisedViewLevels(0);
        $levels = array_values(array_unique(array_map('intval', (array) $levels)));

        if ($levels === []) {
            return null;
        }

        $now = Factory::getDate()->toSql();

        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->createQuery()
            ->select($db->quoteName('params'))
            ->from($db->quoteName('#__modules'))
            ->where($db->quoteName('id') . ' = :id')
            ->where($db->quoteName('module') . ' = ' . $db->quote('mod_folder_gallery'))
            ->where($db->quoteName('client_id') . ' = 0')
            ->where($db->quoteName('published') . ' = 1')
            ->whereIn($db->quoteName('access'), $levels)
            ->where('(' . $db->quoteName('publish_up') . ' IS NULL OR ' . $db->quoteName('publish_up') . ' <= :publishUp)')
            ->where('(' . $db->quoteName('publish_down') . ' IS NULL OR ' . $db->quoteName('publish_down') . ' >= :publishDown)')
            ->bind(':id', $id, ParameterType::INTEGER)
            ->bind(':publishUp', $now)
            ->bind(':publishDown', $now);

        $db->setQuery($query);

        $raw = $db->loadResult();

        return $raw === null ? null : new Registry($raw);
    }
}
