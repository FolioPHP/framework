<?php
declare(strict_types=1);

namespace Framework\Page;

use Framework\Content\Content;
use Framework\Core\Collection;
use Framework\Core\Config;
use Framework\Core\Store;
use RuntimeException;

final class PageRepository
{
  public readonly Site $site;
  public readonly Content $content;
  private array $pagesById = [];
  private array $pagesByPath = [];
  private array $childrenByPath = [];

  public function __construct(private readonly Config $projectConfig, string $lang)
  {
    $pagesPath = $projectConfig->pagesPath;
    $this->site = new Site($pagesPath, $lang, Store::filesMap($pagesPath), [], $this, $projectConfig);
    $this->content = new Content($projectConfig, $lang);

    $siteConfig = $this->site->pageConfig();
    $dev = $projectConfig->isDevMode;
    $emptyTemplate = ['files' => [], 'config' => []];

    $globalTemplates = [];
    foreach (glob("{$projectConfig->templatesPath}/*", GLOB_ONLYDIR) ?: [] as $path) {
      $name = basename($path);
      $globalTemplates[$name] = Store::loadTemplate($path, $name, $lang, $siteConfig, $dev);
    }

    $localTemplates = [];
    foreach ($this->findFoldersRecursively($pagesPath) as $folder) {
      $parent = dirname($folder);
      $folderName = Store::folderId($folder, $parent);

      if ($folderName === 'template') {
        $localTemplates[$folder] ??= Store::loadTemplate($folder, 'template', $lang, $siteConfig, $dev);
        continue;
      }

      $files = Store::filesMap($folder);
      $preloaded = isset($files['config.php']) ? Store::loadFile($files['config.php']) : null;
      $role = ($folderName === 'home' || $folderName === 'error') ? $folderName : 'page';
      $className = $role === 'page' ? $this->resolvePageClass($preloaded['type'] ?? 'page') : Page::class;

      $tplMode = $preloaded['template'] ?? 'inherit';
      $tpl = match (true) {
        $tplMode === 'unset' => $emptyTemplate,
        isset($globalTemplates[$tplMode]) => $globalTemplates[$tplMode],
        default => $localTemplates[$local = "{$parent}/template"]
          ??= (isset(Store::filesMap($parent)['template']) && is_dir($local)
            ? Store::loadTemplate($local, 'template', $lang, $siteConfig, $dev)
            : $emptyTemplate),
      };

      $page = new $className($folder, $lang, $files, $tpl['files'], $this, $projectConfig, $preloaded, $tpl['config'], $role);
      $id = $page->id();
      if (isset($this->pagesById[$id])) throw new RuntimeException("Duplicate page ID '{$id}': {$folder}");
      $this->pagesByPath[$folder] = $page;
      $this->pagesById[$id] = $page;

      if ($page->isRoutable()) $this->childrenByPath[$parent][$id] = $page;
    }
  }

  private function resolvePageClass(string $type): string
  {
    if ($type === 'page') return Page::class;

    $name = ucfirst($type);
    return array_find(
      ["Extend\\Page\\{$name}", "Framework\\Page\\{$name}"],
      static fn(string $class) => is_subclass_of($class, Page::class),
    ) ?? throw new RuntimeException("Unknown page type '{$type}' (expected Extend\\Page\\{$name} extending Page)");
  }

  private function findFoldersRecursively(string $dir, bool $sort = true): array
  {
    $folders = [];
    foreach (Store::filesMap($dir) as $entry => $full) {
      if (((string) $entry)[0] !== '_' && is_dir($full) && !is_link($full)) {
        array_push($folders, $full, ...$this->findFoldersRecursively($full, false));
      }
    }
    if ($sort) sort($folders, SORT_NATURAL);

    return $folders;
  }

  public function findByPath(?string $path): ?Page { return $path !== null ? ($this->pagesByPath[$path] ?? null) : null; }
  public function findById(string $id): ?Page { return $this->pagesById[mb_strtolower($id)] ?? null; }
  public function findError(): Page { return $this->pagesById['error']; }
  public function all(): Collection { return new Collection($this->pagesById); }

  public function findChildrenByPath(string $parentPath): Collection
  {
    $parent = $this->pagesByPath[$parentPath] ?? null;
    return new Collection($this->childrenByPath[$parentPath] ?? [], idPrefix: $parent ? $parent->id() . '/' : '');
  }
}
