<?php
declare(strict_types=1);

namespace Framework\Assets;

use Exception;
use Framework\Core\Config;
use Framework\Core\Store;
use InvalidArgumentException;
use Stringable;

final class Image implements Stringable
{
  public const array EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'avif', 'svg'];

  private int $sourceWidth;
  private int $sourceHeight;
  public private(set) int $width;
  public private(set) int $height;
  private int $imageType;
  private string $hash;
  private int|string $quality;
  private ?string $generatedPath = null;
  private array $alts = [];
  private bool $isSvg;
  public string $url { get => $this->url(); }
  public string $alt { get => $this->resolveAlt() ?? ''; }

  private static ?string $format = null;
  private static ?string $lang = null;
  private static bool $metaDirty = false;
  private static array $dirCache = [];
  private static array $folderAlts = [];
  private static ?array $metaCache = null;

  public function __construct(public readonly string $sourcePath)
  {
    $config = Config::get();
    $this->quality = $config->imageQuality;
    $this->isSvg = str_ends_with(strtolower($sourcePath), '.svg');

    $metaFile = "{$config->cachePath}/image-meta.php";
    self::$metaCache ??= $config->isStaticBuild ? [] : Store::loadFile($metaFile);

    $mtime = filemtime($sourcePath);
    $size = filesize($sourcePath);
    $cached = self::$metaCache[$sourcePath] ?? null;

    if ($cached === null || $cached[0] !== $mtime || $cached[1] !== $size) {
      $cached = self::$metaCache[$sourcePath] = $this->readMeta($sourcePath, $mtime, $size);
      if (!self::$metaDirty && !$config->isStaticBuild) {
        self::$metaDirty = true;
        register_shutdown_function(static fn() => Store::writeFile($metaFile, self::$metaCache));
      }
    }

    [, , $this->sourceWidth, $this->sourceHeight, $this->imageType, $this->hash] = $cached;
    $this->width = $this->sourceWidth;
    $this->height = $this->sourceHeight;

    if (!$this->isSvg && self::$format === null) {
      $gdInfo = gd_info();
      self::$format = array_key_first(array_filter([
        'avif' => $gdInfo['AVIF Support'] ?? false,
        'webp' => $gdInfo['WebP Support'] ?? false,
        'jpeg' => $gdInfo['JPEG Support'] ?? false,
      ]));
    }
  }

  public static function syncAltFiles(string $root): void
  {
    $galleries = array_filter(Store::filesMap($root), static fn(string $path) =>
      is_dir($path) && !is_link($path) && !str_starts_with(basename($path), '_backup_'));
    foreach ([$root, ...$galleries] as $dir) {
      $keys = [];
      foreach (self::scanImages($dir) as $file) {
        if (str_ends_with(strtolower($file), '.svg')) continue;
        $name = self::name($file);
        if ($name === '' || str_starts_with($name, '# ') || trim($name) !== $name || strpbrk($name, ":\r\n") !== false) {
          throw new InvalidArgumentException("Image name cannot be used as a field key: {$file}");
        }
        $keys[$name] = '';
      }
      $format = Store::syncConfig("{$dir}/config.php", ['data_format' => 'txt'], true)['data_format'];
      $files = Store::filesMap($dir);
      self::$folderAlts[$dir] = Store::syncLangFiles($dir, $files, $format, $keys, commentMissing: true);
      Store::archiveUnusedFormats($dir, $files, $format);
    }
  }

  private function readMeta(string $sourcePath, int $mtime, int $size): array
  {
    $hash = substr(hash_file('xxh3', $sourcePath), 0, 8);
    if ($this->isSvg) {
      return [$mtime, $size, 0, 0, IMAGETYPE_UNKNOWN, $hash];
    }

    $info = getimagesize($sourcePath) ?: throw new Exception("Invalid image: {$sourcePath}");
    return [$mtime, $size, $info[0], $info[1], $info[2], $hash];
  }

  public static function setRequestLang(string $lang): void
  {
    self::$lang = $lang;
  }

  private static function name(string $file): string
  {
    return Store::stripOrder(pathinfo($file, PATHINFO_FILENAME));
  }

  public static function matches(string $filePath, string $query): bool
  {
    $ext = strtolower(pathinfo($query, PATHINFO_EXTENSION));
    return ($ext === '' || strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === $ext)
      && self::name($filePath) === self::name($query);
  }

  public function alt(string ...$alts): self
  {
    return clone($this, ['alts' => $alts]);
  }

  private function resolveAlt(): ?string
  {
    $lang = self::$lang ?? Config::get()->defaultLang;
    if ($this->alts !== []) return $this->alts[$lang] ?? array_first($this->alts);

    $dir = dirname($this->sourcePath);
    if (!isset(self::$folderAlts[$dir][$lang])) {
      $root = Config::get()->imagesPath;
      if ($dir !== $root && dirname($dir) !== $root) return null;
      $config = Store::loadFile("{$dir}/config.php");
      self::$folderAlts[$dir][$lang] = $config === [] ? [] : Store::loadFile("{$dir}/{$lang}.{$config['data_format']}", textOnly: true);
    }
    return self::$folderAlts[$dir][$lang][self::name($this->sourcePath)] ?? '';
  }

  public function resize(?int $width = null, ?int $height = null, int|string|null $quality = null): self
  {
    [$newWidth, $newHeight] = match (true) {
      $this->isSvg || ($width && $height) => [$width ?: $this->width, $height ?: $this->height],
      (bool) $width => [$width, (int) ($width * $this->sourceHeight / $this->sourceWidth)],
      (bool) $height => [(int) ($height * $this->sourceWidth / $this->sourceHeight), $height],
      default => [$this->width, $this->height],
    };

    return clone($this, [
      'width' => $newWidth,
      'height' => $newHeight,
      'quality' => !$this->isSvg && $quality !== null ? $quality : $this->quality,
      'generatedPath' => null,
    ]);
  }

  public function url(): string
  {
    return $this->generatedPath ??= $this->generate();
  }

  public function __toString(): string
  {
    $url = $this->url();
    $alt = $this->resolveAlt();
    $altAttr = $alt === null ? '' : ' alt="' . htmlspecialchars($alt, ENT_QUOTES) . '"';
    $widthAttr = $this->width ? " width=\"{$this->width}\"" : '';
    $heightAttr = $this->height ? " height=\"{$this->height}\"" : '';

    return "<img src=\"{$url}\"{$altAttr}{$widthAttr}{$heightAttr}>";
  }

  private function generate(): string
  {
    $outputDir = Config::get()->publicPath . '/generated-images';
    $generated = Store::filesMap($outputDir);
    $basename = pathinfo($this->sourcePath, PATHINFO_FILENAME);

    $baseFilename = $this->isSvg
      ? "{$basename}_{$this->hash}"
      : "{$basename}_{$this->width}x{$this->height}_{$this->quality}_{$this->hash}";
    // Reuse a higher-priority cached format even if this server cannot encode it.
    $format = $this->isSvg ? 'svg' : array_find(
      ['avif', 'webp', 'jpeg'],
      static fn(string $extension) => $extension === self::$format || isset($generated["{$baseFilename}.{$extension}"]),
    );

    $filename = "{$baseFilename}.{$format}";
    if ($format !== null && isset($generated[$filename])) return "/generated-images/{$filename}";

    $outputPath = "{$outputDir}/{$filename}";
    Store::checkWritePath($outputPath);
    if (!is_dir($outputDir)) mkdir($outputDir, 0755, true);

    if ($this->isSvg) {
      copy($this->sourcePath, $outputPath);
    } else {
      $this->writeRaster($outputPath, $format);
    }
    Store::filesMap($outputDir, refresh: true);

    return "/generated-images/{$filename}";
  }

  private function writeRaster(string $outputPath, ?string $format): void
  {
    $sourceImage = match ($this->imageType) {
      IMAGETYPE_JPEG => imagecreatefromjpeg($this->sourcePath),
      IMAGETYPE_PNG => imagecreatefrompng($this->sourcePath),
      IMAGETYPE_WEBP => imagecreatefromwebp($this->sourcePath),
      IMAGETYPE_AVIF => imagecreatefromavif($this->sourcePath),
      default => throw new Exception("Unsupported image type: {$this->sourcePath}"),
    } ?: throw new Exception("Failed to load image: {$this->sourcePath}");

    if ($this->width !== $this->sourceWidth || $this->height !== $this->sourceHeight) {
      $finalImage = imagecreatetruecolor($this->width, $this->height);
      imagealphablending($finalImage, false);
      imagesavealpha($finalImage, true);
      imagecopyresampled($finalImage, $sourceImage, 0, 0, 0, 0, $this->width, $this->height, $this->sourceWidth, $this->sourceHeight);
      unset($sourceImage);
    } else {
      $finalImage = $sourceImage;
    }

    $baseQuality = match ($this->quality) {
      'low' => 60,
      'medium' => 80,
      'high' => 90,
      default => (int) $this->quality,
    };

    $formatQuality = match ($format) {
      'avif' => (int) ($baseQuality * 0.75),
      'webp' => (int) ($baseQuality * 0.95),
      default => $baseQuality,
    };

    match ($format) {
      'avif' => imageavif($finalImage, $outputPath, $formatQuality),
      'webp' => imagewebp($finalImage, $outputPath, $formatQuality),
      'jpeg' => imagejpeg($finalImage, $outputPath, $formatQuality),
    };
  }

  public static function resolveFolder(string $basePath, string $folder): ?string
  {
    if (str_contains($folder, '..')) {
      throw new InvalidArgumentException("Invalid image path: {$folder}");
    }

    if ($folder === '' || $folder === '.' || str_contains($folder, '/')) return null;
    foreach ([$folder, str_replace('_', '-', $folder)] as $name) {
      if (is_dir($path = "{$basePath}/{$name}")) return $path;
    }
    return null;
  }

  public static function scanImages(string $dir): array
  {
    return self::$dirCache[$dir] ??= array_values(array_filter(
      Store::filesMap($dir),
      static fn(string $fullPath, string|int $entry) =>
        in_array(strtolower(pathinfo($entry, PATHINFO_EXTENSION)), self::EXTENSIONS, true) && is_file($fullPath),
      ARRAY_FILTER_USE_BOTH,
    ));
  }

  public static function load(string $filename, ?string $basePath = null): ?self
  {
    if (str_contains($filename, '..')) {
      throw new InvalidArgumentException("Invalid image path: {$filename}");
    }

    if (str_contains($filename, '/')) {
      if ($basePath !== null || substr_count($filename, '/') !== 1) return null;
      [$folder, $filename] = explode('/', $filename);
      $basePath = self::resolveFolder(Config::get()->imagesPath, $folder);
      if ($basePath === null) return null;
    }

    $files = self::scanImages($basePath ?? Config::get()->imagesPath);
    foreach (array_unique([$filename, str_replace('_', '-', $filename)]) as $name) {
      $match = array_find($files, static fn(string $file) => self::matches($file, $name));
      if ($match !== null) return new self($match);
    }
    return null;
  }
}
