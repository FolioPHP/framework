<?php
declare(strict_types=1);

namespace Framework\Core;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class Store
{
  private const array FORMATS = ['txt', 'php', 'json'];

  private static array $dirMaps = [];
  private static array $templateFields = [];

  public static function filesMap(string $dir, bool $refresh = false): array
  {
    if (!$refresh && isset(self::$dirMaps[$dir])) return self::$dirMaps[$dir];
    if (!is_dir($dir)) return self::$dirMaps[$dir] = [];

    $map = [];
    foreach (scandir($dir) ?: [] as $entry) {
      if ($entry[0] !== '.') $map[$entry] = "{$dir}/{$entry}";
    }

    return self::$dirMaps[$dir] = $map;
  }

  public static function stripOrder(string $name): string
  {
    return preg_replace('/^\d+(?::\d+)?_/', '', $name);
  }

  public static function folderId(string $path, string $root): string
  {
    $segments = explode('/', substr($path, strlen($root) + 1));
    return mb_strtolower(implode('/', array_map(self::stripOrder(...), $segments)));
  }

  public static function extractOrder(string $path): ?int
  {
    return preg_match('/^(\d+)_/', basename($path), $m) ? (int) $m[1] : null;
  }

  public static function sync(mixed $target, array $source, bool $prune = false): array
  {
    $target = is_array($target) ? $target : [];
    return $prune ? array_intersect_key($target, $source) + $source : $target + $source;
  }

  public static function syncConfig(string $filePath, array $blueprint, bool $devMode, ?array $existing = null): array
  {
    $exists = $existing !== null || is_file($filePath);
    if (!$exists && !$devMode) throw new RuntimeException("Missing required config: {$filePath}");
    $existing ??= $exists ? self::loadFile($filePath) : [];
    if (!$devMode) return $existing;

    $synced = self::sync($existing, $blueprint, prune: true);
    if (!$exists || $synced !== $existing) {
      self::writeFile($filePath, $synced);
    }

    return $synced;
  }

  public static function loadFile(string $path, bool $textOnly = false): array
  {
    if (!is_file($path)) return [];

    return match (pathinfo($path, PATHINFO_EXTENSION)) {
      'txt' => self::txtToArray(file_get_contents($path), $textOnly),
      'php' => is_array($data = include $path) ? $data : [],
      'json' => ($raw = trim(file_get_contents($path))) !== ''
        ? (json_decode($raw, true, 512, JSON_THROW_ON_ERROR) ?? [])
        : [],
      default => [],
    };
  }

  public static function loadFileByName(string $name, array $files, string $format = '', bool $textOnly = false): array
  {
    $path = $files["{$name}.{$format}"]
      ?? array_find($files, static fn(string $_, string $file) => pathinfo($file, PATHINFO_FILENAME) === $name
        && in_array(pathinfo($file, PATHINFO_EXTENSION), self::FORMATS, true));

    return $path ? self::loadFile($path, $textOnly) : [];
  }

  public static function checkWritePath(string $path): void
  {
    $framework = Config::roots()['framework'];
    $ancestor = $path;
    while (($resolved = realpath($ancestor)) === false) $ancestor = dirname($ancestor);
    if (str_starts_with("{$path}/", "{$framework}/") || str_starts_with("{$resolved}/", "{$framework}/")) {
      throw new RuntimeException("Cannot write inside the framework: {$path}");
    }
  }

  public static function putContents(string $path, string $content): bool
  {
    $exists = is_file($path);
    if ($exists && file_get_contents($path) === $content) return false;

    self::checkWritePath($dir = dirname($path));
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
      throw new RuntimeException("Could not create directory: {$dir}");
    }
    $mode = $exists ? fileperms($path) : 0666 & ~umask();
    $temp = tempnam($dir, '.folio-write-');
    if ($temp === false) throw new RuntimeException("Could not stage write: {$path}");

    try {
      if ($mode === false || realpath(dirname($temp)) !== realpath($dir)
        || file_put_contents($temp, $content) !== strlen($content)
        || !chmod($temp, $mode & 0777) || !rename($temp, $path)) {
        throw new RuntimeException("Could not write file: {$path}");
      }
    } finally {
      if (is_file($temp)) unlink($temp);
    }
    unset(self::$dirMaps[$dir]);
    return true;
  }

  public static function copyTree(string $source, string $target, callable $include): void
  {
    if (!is_dir($source) || is_link($source)) throw new RuntimeException("Expected a source directory: {$source}");
    $relative = static fn(SplFileInfo $file) => substr($file->getPathname(), strlen($source) + 1);
    $dir = new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS);
    $files = new RecursiveCallbackFilterIterator($dir, static fn(SplFileInfo $file) =>
      !$file->isLink() && $file->getFilename()[0] !== '.' && ($file->isFile() || $file->isDir())
      && $include($relative($file), $file->isDir()));

    foreach (new RecursiveIteratorIterator($files) as $file) {
      $path = "{$target}/" . $relative($file);
      if (!is_dir($parent = dirname($path))) mkdir($parent, 0755, true);
      copy($file->getPathname(), $path);
    }
  }

  public static function removeTree(string $path, ?int $device = null): void
  {
    if (is_link($path) || !is_dir($path)) {
      unlink($path);
      return;
    }

    $here = stat($path)['dev'];
    if ($device !== null && $device !== $here) throw new RuntimeException("Refusing to remove a mounted directory: {$path}");
    foreach (new FilesystemIterator($path) as $item) self::removeTree($item->getPathname(), $here);
    rmdir($path);
  }

  public static function writeFile(string $path, array $data): void
  {
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    $content = match ($ext) {
      'txt' => self::arrayToTxt($data),
      'php' => "<?php\nreturn " . self::formatPhp($data) . ";\n",
      'json' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n",
      default => throw new RuntimeException("Unsupported format: {$ext}"),
    };

    if (self::putContents($path, $content) && $ext === 'php' && function_exists('opcache_invalidate')) {
      opcache_invalidate($path, true);
    }
  }

  public static function loadFields(
    string $path,
    string $lang,
    string $format,
    array $files = [],
    array $blueprint = [],
    array $templateFiles = [],
    bool $isTemplate = false,
  ): array {
    $config = Config::get();
    $defName = $isTemplate ? 'fields' : $config->defaultLang;
    $targetPath = "{$path}/" . ($isTemplate ? 'fields' : $lang) . ".{$format}";

    if (!$config->isDevMode) return self::loadFile($targetPath);

    $files = $files ?: self::filesMap($path);
    $tplFields = $templateFiles
      ? (self::$templateFields[array_first($templateFiles) . ':' . $format] ??= self::loadFileByName('fields', $templateFiles, $format))
      : [];

    $defFile = "{$defName}.{$format}";
    $defPath = "{$path}/{$defFile}";
    $existing = self::loadFileByName($defName, $files, $format);
    $fields = self::sync($existing, self::sync($blueprint, $tplFields));
    if (!isset($files[$defFile]) || $fields !== $existing) {
      self::writeFile($defPath, $fields);
    }

    if (!$isTemplate) {
      $byLang = self::syncLangFiles($path, [$defFile => $defPath, ...$files], $format, $fields);
      $fields = $byLang[$lang] ?? self::loadFile($targetPath);
    }

    self::archiveUnusedFormats($path, $files, $format);
    return $fields;
  }

  public static function syncLangFiles(string $dir, array $files, string $format, ?array $defData = null, bool $commentMissing = false): array
  {
    $config = Config::get();
    $defData ??= self::loadFileByName($config->defaultLang, $files, $format);
    $byLang = [$config->defaultLang => $defData];

    foreach ($config->supportedLangs as $lang) {
      $targetFile = "{$lang}.{$format}";
      $targetPath = "{$dir}/{$targetFile}";

      if (!$commentMissing && $lang === $config->defaultLang) {
        if (!isset($files[$targetFile])) self::writeFile($targetPath, $defData);
        continue;
      }

      $existing = self::loadFileByName($lang, $files, $format, textOnly: $commentMissing);
      $fields = $existing;
      if ($commentMissing) {
        $fields = [];
        foreach ($existing as $key => $value) {
          $name = str_starts_with((string) $key, '# ') ? substr($key, 2) : $key;
          $fields[array_key_exists($name, $defData) ? $name : "# {$name}"] = $value;
        }
      }
      $byLang[$lang] = $synced = self::sync($fields, $defData, prune: !$commentMissing);
      if (!isset($files[$targetFile]) || $synced !== $existing) {
        self::writeFile($targetPath, $synced);
      }
    }

    return $byLang;
  }

  public static function archiveUnusedFormats(string $dir, array $files, string $format): void
  {
    $config = Config::get();
    $stale = [];

    foreach (array_diff(self::FORMATS, [$format]) as $unused) {
      foreach ([...$config->supportedLangs, 'fields'] as $name) {
        if (isset($files["{$name}.{$unused}"])) $stale[] = $files["{$name}.{$unused}"];
      }
    }

    if ($stale === []) return;

    self::checkWritePath($dir);
    $backupDir = "{$dir}/_backup_" . date('Y-m-d_H-i-s');
    self::checkWritePath($backupDir);
    if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);
    foreach ($stale as $filePath) {
      rename($filePath, "{$backupDir}/" . basename($filePath));
    }
    unset(self::$dirMaps[$dir]);
  }

  public static function loadTemplate(string $path, string $name, string $lang, array $siteConfig, bool $devMode): array
  {
    $files = self::filesMap($path);
    if (!$devMode) {
      $config = isset($files['config.php']) ? self::loadFile($files['config.php']) : [];
      unset($config['id']);
      return ['files' => $files, 'config' => $config];
    }

    $config = self::syncConfig("{$path}/config.php", [
      'data_format' => $siteConfig['data_format'],
      'render_assets' => $siteConfig['render_assets'],
      'show_header' => true,
      'show_footer' => true,
    ], true);

    self::loadFields($path, $lang, $config['data_format'], $files, [
      'meta_title' => $name,
      'meta_description' => '',
      'title' => $name,
    ], isTemplate: true);

    return ['files' => self::filesMap($path, refresh: true), 'config' => $config];
  }

  public static function formatPhp(array $data, int $level = 0): string
  {
    if (array_is_list($data)) {
      $items = array_map(static fn(mixed $v) => self::formatPhpValue($v, $level), $data);
      return '[' . implode(', ', $items) . ']';
    }

    $indent = str_repeat('  ', $level);
    $out = "[\n";
    foreach ($data as $key => $value) {
      $k = is_string($key) ? var_export($key, true) : $key;
      $out .= "{$indent}  {$k} => " . self::formatPhpValue($value, $level + 1) . ",\n";
    }
    return "{$out}{$indent}]";
  }

  private static function formatPhpValue(mixed $value, int $level): string
  {
    return match (true) {
      is_array($value) => self::formatPhp($value, $level),
      $value === null => 'null',
      default => var_export($value, true),
    };
  }

  private static function txtToArray(string $txt, bool $textOnly): array
  {
    $array = [];
    foreach (preg_split('/^\h*-{3,}\h*$/m', trim($txt)) ?: [] as $block) {
      if (!str_contains($block, ':')) continue;

      [$key, $value] = array_map('trim', explode(':', $block, 2));
      if ($key === '') continue;

      $array[$key] = (!$textOnly && $value !== '' && ($value[0] === '{' || $value[0] === '['))
        ? (json_decode($value, true) ?? $value)
        : $value;
    }

    return $array;
  }

  private static function arrayToTxt(array $array): string
  {
    if ($array === []) return '';

    $blocks = [];
    foreach ($array as $key => $value) {
      if (is_array($value)) $value = json_encode($value, JSON_UNESCAPED_UNICODE);
      $blocks[] = "{$key}: {$value}";
    }

    return implode(PHP_EOL . '-------' . PHP_EOL, $blocks) . PHP_EOL . '-------';
  }
}
