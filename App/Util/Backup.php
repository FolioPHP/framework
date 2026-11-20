<?php
declare(strict_types=1);

namespace Framework\Util;

use FilesystemIterator;
use Framework\Core\Config;
use Framework\Core\Store;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ZipArchive;

final class Backup
{
  public function __construct(private readonly Config $config) {}

  public function check(): bool
  {
    if (!$this->config->backup) return false;

    $roots = Config::roots();
    $files = glob("{$roots['storage']}/backups/backup_*.zip") ?: [];
    $lastBackup = $files ? max(array_map('filemtime', $files)) : 0;

    return (time() - $lastBackup >= $this->config->backupInterval * 86400) && self::archive($roots) !== false;
  }

  public static function archive(array $roots): string|false
  {
    $backupDir = "{$roots['storage']}/backups";
    Store::checkWritePath($backupDir);
    if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);

    $zip = new ZipArchive();
    $path = "{$backupDir}/backup_" . date('Y-m-d_H-i-s') . '.zip';
    if (file_exists($path) || !($reservation = fopen($path, 'xb'))) return false;
    fclose($reservation);

    $saved = false;
    try {
      if ($zip->open($path, ZipArchive::OVERWRITE) !== true) return false;
      $excluded = ["{$roots['public']}/generated-assets", "{$roots['public']}/generated-images"];
      foreach (['public', 'site'] as $name) {
        $source = $roots[$name];
        if (is_link($source) || !$zip->addEmptyDir($name)) return false;
        $dir = new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS);
        $filter = new RecursiveCallbackFilterIterator($dir, static fn(SplFileInfo $item) =>
          !$item->isLink() && $item->getFilename() !== '.git' && !in_array($item->getPathname(), $excluded, true)
        );

        foreach (new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST) as $file) {
          $pathInSource = $file->getPathname();
          $entry = $name . substr($pathInSource, strlen($source));
          if (!($file->isDir() ? $zip->addEmptyDir($entry) : $zip->addFile($pathInSource, $entry))) return false;
        }
      }
      foreach (['folio', 'folio.ref'] as $name) {
        $source = "{$roots['website']}/{$name}";
        if (is_link($source) || !is_file($source) || !$zip->addFile($source, $name)) return false;
      }
      $saved = $zip->close();
      return $saved ? $path : false;
    } finally {
      unset($zip);
      if (!$saved) unlink($path);
    }
  }
}
