<?php
declare(strict_types=1);

namespace Framework\Page;

use Framework\Assets\Image;
use Framework\Core\Collection;
use Framework\Core\Config;
use Framework\Core\Field;
use Framework\Core\Store;
use Framework\Render\Component;
use Framework\Render\Renderer;
use Framework\Util\PerformanceMonitor;

class Page
{
  protected bool $routable = true;

  private readonly string $id;
  private readonly ?int $order;
  private array $pageConfig;
  private array $templateConfig;
  private bool $active = false;

  private ?array $loadedFields = null;
  private string $dataFormat;
  private ?object $components = null;

  public function &fields(): array
  {
    if ($this->loadedFields === null) $this->hydrateFields();
    return $this->loadedFields;
  }

  public function id(): string { return $this->id; }
  public function order(): ?int { return $this->order; }
  public function path(): string { return $this->path; }
  public function lang(): string { return $this->lang; }
  public function pageConfig(): array { return $this->pageConfig; }
  public function templateConfig(): array { return $this->templateConfig; }
  public function pageFiles(): array { return $this->pageFiles; }
  public function templateFiles(): array { return $this->templateFiles; }
  public function repository(): PageRepository { return $this->repository; }
  public function isRoutable(): bool { return $this->routable; }
  public function isActive(): bool { return $this->active; }
  public function activate(): void { $this->active = true; }

  public function isCacheable(): bool
  {
    return $this->projectConfig->caching && ($this->pageConfig['caching'] ?? true) === true;
  }

  public function hasActiveChild(): bool
  {
    foreach ($this->children() as $child) {
      if ($child->isActive() || $child->hasActiveChild()) return true;
    }
    return false;
  }

  public function __construct(
    private readonly string $path,
    private readonly string $lang,
    private readonly array $pageFiles,
    private readonly array $templateFiles,
    private readonly PageRepository $repository,
    protected readonly Config $projectConfig,
    ?array $preloadedConfig = null,
    array $templateConfig = [],
    private readonly string $role = 'page',
  ) {
    PerformanceMonitor::start('Page Constructor');

    if ($this->role === 'error') $this->routable = false;

    $this->order = Store::extractOrder($path);
    $this->id = $this instanceof Site ? 'site' : Store::folderId($path, $projectConfig->pagesPath);

    $dev = $projectConfig->isDevMode;
    $this->pageConfig = Store::syncConfig("{$path}/config.php", $dev ? $this->buildConfigBlueprint() : [], $dev, $preloadedConfig);
    unset($this->pageConfig['id']);

    $this->templateConfig = $templateConfig;
    if (!($this instanceof Site)) {
      $defaults = $repository->site->pageConfig();
      $defaults['data_format'] = $templateConfig['data_format'] ?? $defaults['data_format'];
      foreach (['data_format', 'render_assets'] as $key) {
        if (($this->pageConfig[$key] ?? 'inherit') === 'inherit') $this->pageConfig[$key] = $defaults[$key];
      }
      foreach (['show_header', 'show_footer'] as $key) {
        $this->pageConfig[$key] = $defaults[$key] === true && ($this->pageConfig[$key] ?? true) === true;
      }
    }
    $this->dataFormat = $this->pageConfig['data_format'];

    if ($dev) $this->hydrateFields();

    PerformanceMonitor::end('Page Constructor');
  }

  private function hydrateFields(): void
  {
    $dev = $this->projectConfig->isDevMode;
    $this->loadedFields = Store::loadFields(
      $this->path,
      $this->lang,
      $this->dataFormat,
      $this->pageFiles,
      $dev ? $this->buildFieldsBlueprint() : [],
      $this->templateFiles,
    );

    if (isset($this->pageFiles['controller.php'])) {
      // Controllers keep $this, but see the same public field namespace as templates.
      (function (string $file) { include $file; })->bindTo($this, null)($this->pageFiles['controller.php']);
    }
  }

  public function buildConfigBlueprint(): array
  {
    $blueprint = [
      'data_format' => 'inherit',
      'render_assets' => 'inherit',
      'caching' => true,
      'template' => 'inherit',
      'show_header' => true,
      'show_footer' => true,
    ];

    if ($this->role !== 'page') return $blueprint;

    $blueprint['type'] = strtolower(basename(str_replace('\\', '/', static::class)));
    foreach ($this->projectConfig->supportedLangs as $lang) {
      $blueprint["{$lang}_slug"] = basename($this->id);
    }

    return $blueprint;
  }

  public function buildFieldsBlueprint(): array
  {
    $name = basename($this->id);
    if ($this->role === 'error') return ['title' => $name];

    return [
      'meta_title' => $name,
      'meta_description' => '',
      'title' => $name,
      'publish_date' => date('Y-m-d H:i:s'),
    ];
  }

  public function __get(string $name): Field
  {
    return new Field($this->fields()[$name] ?? null);
  }

  public function components(): object
  {
    return $this->components ??= new readonly class($this) {
      public function __construct(private Page $page) {}
      public function __call(string $name, array $args): Component { return new Component($this->page, $name, $args[0] ?? null); }
      public function __get(string $name): Component { return new Component($this->page, $name); }
    };
  }

  public function children(?int $limit = null): Collection
  {
    $children = $this->repository->findChildrenByPath($this->path);
    return $limit !== null ? $children->limit($limit) : $children;
  }

  public function page(string $id = ''): ?Page
  {
    return $this($id);
  }

  public function __invoke(string $id = ''): ?Page
  {
    if ($id === '') return $this;
    return $this->repository->findById($id) ?? $this->repository->findById("{$this->id}/{$id}");
  }

  public function url(?string $lang = null): string
  {
    $lang ??= $this->lang;
    $prefix = $lang === $this->projectConfig->defaultLang ? '' : "/{$lang}";
    if ($this instanceof Site || $this->role !== 'page') return $prefix ?: '/';

    $parentUrl = $this->repository->findByPath(dirname($this->path))?->url($lang) ?? $prefix;
    return rtrim($parentUrl, '/') . '/' . $this->pageConfig["{$lang}_slug"];
  }

  public function image(string $filename): ?Image
  {
    return Image::load($filename, $this->path);
  }

  public function images(): object
  {
    return Renderer::assetLookup($this->image(...));
  }
}
