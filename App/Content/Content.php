<?php
declare(strict_types=1);

namespace Framework\Content;

use Framework\Core\Collection;
use Framework\Core\Config;
use Framework\Core\Store;
use RuntimeException;

final class Content
{
  private array $types = [];

  public function __construct(
    private readonly Config $config,
    private readonly string $lang,
  ) {
    foreach (Store::filesMap($config->contentPath) as $entry => $path) {
      if (((string) $entry)[0] === '_' || !is_dir($path)) continue;
      $type = Store::folderId($path, $config->contentPath);
      if (isset($this->types[$type])) throw new RuntimeException("Duplicate content type ID '{$type}': {$path}");
      $this->types[$type] = $config->isDevMode ? $this->loadType($type, $path) : $path;
    }
  }

  public function __get(string $name): ?Collection
  {
    if (str_contains($name, '/')) return null;
    return $this->find($name) ?? $this->find(str_replace('_', '-', $name));
  }

  public function __invoke(string $query): Collection|ContentItem|null { return $this->find($query); }

  public function find(string $query): Collection|ContentItem|null
  {
    $query = mb_strtolower($query);
    [$type] = explode('/', $query, 2);
    $items = $this->types[$type] ?? null;
    if (is_string($items)) $items = $this->types[$type] = $this->loadType($type, $items);
    return $query === $type ? $items : $items?->findById($query);
  }

  private function loadType(string $type, string $typePath): Collection
  {
    $dev = $this->config->isDevMode;
    $format = Store::syncConfig("{$typePath}/config.php", ['data_format' => 'txt'], $dev)['data_format'];

    if ($dev) {
      Store::loadFields($typePath, $this->lang, $format, blueprint: ['title' => $type], isTemplate: true);
    }
    $typeFiles = Store::filesMap($typePath, refresh: $dev);

    $items = [];
    foreach ($typeFiles as $entry => $fullPath) {
      if (((string) $entry)[0] === '_' || !is_dir($fullPath)) continue;
      $id = Store::folderId($fullPath, $this->config->contentPath);
      if (isset($items[$id])) throw new RuntimeException("Duplicate content item ID '{$id}': {$fullPath}");
      $items[$id] = new ContentItem($fullPath, $id, $type, $this->lang, $typeFiles, $format, $dev);
    }

    return new Collection($items, idPrefix: "{$type}/");
  }
}
