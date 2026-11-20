<?php
declare(strict_types=1);

namespace Framework\Render;

use Framework\Core\Config;
use Framework\Core\Store;
use Framework\Page\Page;
use Stringable;

final class Component implements Stringable
{
  private static array $definitions = [];
  private static array $syncedConfigs = [];
  private static array $contentCache = [];

  private array $definition;
  private array $allOverrides = [];
  private array $langOverrides = [];
  private ?string $contentId = null;
  private ?array $fields = null;

  public function __construct(
    private readonly Page $page,
    private readonly string $name,
    string|array|null $arg = null,
  ) {
    $this->definition = self::resolveDefinition($name);
    if (is_string($arg)) $this->contentId = $arg;
    elseif (is_array($arg)) $this->allOverrides = $arg;
  }

  public static function syncAll(): void
  {
    foreach (glob(Config::get()->componentsPath . '/*', GLOB_ONLYDIR) ?: [] as $folder) {
      self::ensureStructure($folder);
    }
  }

  private static function ensureStructure(string $path): array
  {
    if (isset(self::$syncedConfigs[$path])) return self::$syncedConfigs[$path];

    if (!is_file($tpl = "{$path}/template.php")) {
      Store::putContents($tpl, '<p>This is ' . basename($path) . '</p>');
    }

    $cfg = Store::syncConfig("{$path}/config.php", ['content_format' => 'txt', 'render_assets' => 'external'], true);
    $files = Store::filesMap($path, refresh: true);
    $prefix = Config::get()->defaultLang . '.';

    if (array_any(array_keys($files), static fn(string $f) => str_starts_with($f, $prefix))) {
      Store::syncLangFiles($path, $files, $cfg['content_format']);
      Store::archiveUnusedFormats($path, $files, $cfg['content_format']);
    }

    return self::$syncedConfigs[$path] = $cfg;
  }

  private static function resolveDefinition(string $name): array
  {
    if (isset(self::$definitions[$name])) return self::$definitions[$name];

    $config = Config::get();
    $path = "{$config->componentsPath}/{$name}";
    $cfg = $config->isDevMode ? self::ensureStructure($path) : require "{$path}/config.php";
    $mode = $cfg['render_assets'] ?? 'inline';
    $files = Store::filesMap($path);

    return self::$definitions[$name] = [
      'path' => $path,
      'template' => "{$path}/template.php",
      'format' => $cfg['content_format'],
      'css' => Renderer::bundleDirAssets($files, 'css', $mode),
      'js' => Renderer::bundleDirAssets($files, 'js', $mode),
    ];
  }

  public function __call(string $lang, array $arguments): self
  {
    $overrides = $this->langOverrides;
    $overrides[$lang] = array_merge($overrides[$lang] ?? [], $arguments[0] ?? []);
    return clone($this, ['langOverrides' => $overrides, 'fields' => null]);
  }

  public function all(array $data): self
  {
    return clone($this, ['allOverrides' => array_merge($this->allOverrides, $data), 'fields' => null]);
  }

  public function fields(): array
  {
    $lang = $this->page->lang();
    return $this->fields ??= array_merge($this->loadLangContent($lang), $this->allOverrides, $this->langOverrides[$lang] ?? []);
  }

  public function __toString(): string
  {
    $this->fields();
    Renderer::collectComponentAsset('css', $this->definition['css']);
    $html = Renderer::renderTemplate($this->definition['template'], [
      ...Renderer::context($this->page),
      'component' => $this,
    ]);
    Renderer::collectComponentAsset('js', $this->definition['js']);

    return $html;
  }

  public function __get(string $name): mixed
  {
    if (array_key_exists($name, $this->fields())) return $this->fields[$name];
    return $this->fields[str_replace('_', '-', $name)] ?? null;
  }

  private function loadLangContent(string $lang): array
  {
    $fmt = $this->definition['format'];
    $defPath = $this->definition['path'];
    $defaults = self::$contentCache["{$defPath}:{$lang}"] ??= Store::loadFile("{$defPath}/{$lang}.{$fmt}");

    if ($this->contentId === null) return $defaults;

    $scopedPath = "{$this->page->path()}/_{$this->contentId}";
    $scoped = self::$contentCache["{$scopedPath}:{$lang}"] ??= Store::loadFields(
      $scopedPath,
      $lang,
      $fmt,
      blueprint: ['_comment' => "Content for {$this->name} component"],
    );

    return array_merge($defaults, $scoped);
  }
}
