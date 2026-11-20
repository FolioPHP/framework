<?php
declare(strict_types=1);

namespace Framework\Core;

use LogicException;
use RuntimeException;

final class Config
{
  private static ?self $instance = null;
  private static array $roots;

  public readonly string $sitePath;
  public readonly string $pagesPath;
  public readonly string $contentPath;
  public readonly string $templatesPath;
  public readonly string $componentsPath;
  public readonly string $imagesPath;
  public readonly string $publicPath;
  public readonly string $cachePath;
  public readonly array $supportedLangs;
  public readonly string $defaultLang;
  public readonly bool $isDevMode;
  public readonly bool $isStaticBuild;
  public readonly bool $minify;
  public readonly bool $backup;
  public readonly bool $performanceMonitor;
  public readonly bool $caching;
  public readonly int $backupInterval;
  public readonly string $imageQuality;

  private function __construct(?string $buildPath = null)
  {
    $this->sitePath = self::$roots['site'];
    $this->pagesPath = "{$this->sitePath}/pages";
    $this->contentPath = "{$this->sitePath}/content";
    $this->templatesPath = "{$this->sitePath}/templates";
    $this->componentsPath = "{$this->sitePath}/components";
    $this->imagesPath = "{$this->sitePath}/assets/images";
    $this->publicPath = $buildPath ?? self::$roots['public'];
    $this->cachePath = self::$roots['storage'] . '/cache';
    $this->isStaticBuild = $buildPath !== null;

    $configPath = "{$this->sitePath}/project.php";
    $existing = is_file($configPath) ? Store::loadFile($configPath) : null;
    if ($this->isStaticBuild && $existing === null) throw new RuntimeException("Missing required config: {$configPath}");
    $this->isDevMode = !$this->isStaticBuild && (bool) ($existing['dev_mode'] ?? true);

    $siteConfig = Store::syncConfig($configPath, $this->isDevMode || $existing === null ? [
      'supported_langs' => ['en', 'de'],
      'default_lang' => 'en',
      'dev_mode' => true,
      'image_quality' => 'medium',
      'performance_monitor' => true,
      'caching' => true,
      'minify_code' => true,
      'backup' => false,
      'backup_interval' => 7,
    ] : [], $this->isDevMode, $existing);

    $this->supportedLangs = $siteConfig['supported_langs'];
    $this->defaultLang = $siteConfig['default_lang'];
    $this->minify = $siteConfig['minify_code'];
    $this->backup = $this->isStaticBuild ? false : $siteConfig['backup'];
    $this->backupInterval = $siteConfig['backup_interval'];
    $this->performanceMonitor = $this->isStaticBuild ? false : $siteConfig['performance_monitor'];
    $this->caching = $this->isStaticBuild ? false : $siteConfig['caching'];
    $this->imageQuality = $siteConfig['image_quality'];
  }

  public static function initialize(array $roots): void { self::$roots = $roots; }
  public static function roots(): array { return self::$roots; }

  public static function get(): self
  {
    return self::$instance ??= new self();
  }

  public static function forBuild(string $publicPath): self
  {
    if (self::$instance !== null) throw new LogicException('Initialize the build before loading project configuration.');
    return self::$instance = new self($publicPath);
  }

  public static function isBuilding(): bool
  {
    return self::$instance?->isStaticBuild ?? false;
  }
}
