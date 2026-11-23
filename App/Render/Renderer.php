<?php
declare(strict_types=1);

namespace Framework\Render;

use Closure;
use Framework\Assets\Gallery;
use Framework\Assets\Image;
use Framework\Core\Config;
use Framework\Core\Store;
use Framework\Page\Page;
use Framework\Util\Minifier;
use Framework\Util\PerformanceMonitor;

final class Renderer
{
  private static array $contextCache = [];
  private static array $emittedAssets = [];
  private static array $componentAssets = ['css' => [], 'js' => []];
  private static array $deferredAssets = ['css' => [], 'js' => []];

  public static function render(Page $page): string
  {
    $config = Config::get();
    if ($config->isStaticBuild) self::$contextCache = [];
    $page->activate();
    $page->fields(); // Ensure the active page controller runs before output buffering.

    PerformanceMonitor::start('Page rendering');

    self::$emittedAssets = [];
    self::$componentAssets = ['css' => [], 'js' => []];
    self::$deferredAssets = ['css' => [], 'js' => []];

    $site = $page->repository()->site;
    $pageConfig = $page->pageConfig();
    $pageFiles = $page->pageFiles();
    $templateFiles = $page->templateFiles();
    $renderMode = $pageConfig['render_assets'];
    $components = $page->components();
    $ctx = self::context($page);

    // Phase 1: render body first so rendered components register their CSS/JS
    ob_start();
    if ($pageConfig['show_header']) {
      echo self::wrap('header', isset($pageFiles['header.php'])
        ? self::renderTemplate($pageFiles['header.php'], $ctx)
        : (string) $components->header);
    }
    if ($templateFile = $templateFiles['template.php'] ?? $pageFiles['template.php'] ?? null) {
      echo self::wrap('main', self::renderTemplate($templateFile, $ctx));
    }
    if ($pageConfig['show_footer']) {
      echo self::wrap('footer', isset($pageFiles['footer.php'])
        ? self::renderTemplate($pageFiles['footer.php'], $ctx)
        : (string) $components->footer);
    }
    $body = ob_get_clean();

    // Phase 2: assemble <head> with collected assets in document order
    $esc = static fn(mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES);
    $head = <<<HTML
    <!DOCTYPE html>
    <html lang="{$esc($page->lang())}">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <meta name="description" content="{$esc($page->fields()['meta_description'] ?? '')}">
      <meta name="author" content="{$esc($site->fields()['author'] ?? '')}">
      <link rel="icon" type="image/x-icon" href="data:,">
      <title>{$esc($page->fields()['meta_title'] ?? '')}</title>

    HTML;

    $assets = self::renderGlobalAsset('css', 'framework', 'external', $config)
      . self::bundleDirAssets($pageFiles, 'css', $renderMode, $templateFiles, dedupe: true)
      . implode('', self::$componentAssets['css'])
      . self::renderDeferred('css', $config)
      . self::bundleDirAssets($pageFiles, 'js', $renderMode, $templateFiles, dedupe: true)
      . implode('', self::$componentAssets['js'])
      . self::renderDeferred('js', $config)
      . self::renderGlobalAsset('js', 'globalJS', 'external', $config);

    $html = "{$head}{$assets}</head>\n<body>\n{$body}</body>\n</html>\n";

    if ($config->minify) {
      $html = Minifier::minifyHTML($html);
    }

    if ($page->isCacheable() && !$config->isDevMode && !$config->isStaticBuild) {
      Store::putContents(self::cacheFile($config, $page), $html);
    }

    PerformanceMonitor::end('Page rendering');
    return $html;
  }

  public static function context(Page $page): array
  {
    $key = "{$page->path()}:{$page->lang()}";
    if (isset(self::$contextCache[$key])) return self::$contextCache[$key];

    $config = Config::get();
    $mode = $page->pageConfig()['render_assets'];

    return self::$contextCache[$key] = [
      'page' => $page,
      'link' => static fn(string $id, ?string $lang = null): string => $page->repository()->findById($id)?->url($lang) ?? '#',
      'pages' => $page->repository()->all(),
      'site' => $page->repository()->site,
      'content' => $page->repository()->content,
      'components' => $page->components(),
      'image' => Image::load(...),
      'gallery' => Gallery::load(...),
      'images' => self::assetLookup(static fn(string $name) => Image::load($name, $config->imagesPath)),
      'galleries' => self::assetLookup(Gallery::load(...)),
      'css' => self::assetHelper('css', $mode, $config),
      'js' => self::assetHelper('js', $mode, $config),
    ];
  }

  public static function assetLookup(Closure $lookup): object
  {
    return new readonly class($lookup) {
      public function __construct(private Closure $lookup) {}
      public function __get(string $name): Image|Gallery|null { return ($this->lookup)($name); }
    };
  }

  public static function renderTemplate(string $templateFile, array $context): string
  {
    ob_start();
    self::includeTemplate($templateFile, $context);
    return ob_get_clean();
  }

  private static function wrap(string $tag, string $content, string $indent = ''): string
  {
    if ($content === '') return "{$indent}<{$tag}></{$tag}>\n";

    $start = str_starts_with($content, "\n") || str_starts_with($content, "\r") ? '' : "\n";
    $end = str_ends_with($content, "\n") || str_ends_with($content, "\r") ? '' : "\n";
    return "{$indent}<{$tag}>{$start}{$content}{$end}{$indent}</{$tag}>\n";
  }

  private static function includeTemplate(string $__file, array $__context): void
  {
    extract($__context);
    include $__file;
  }

  public static function collectComponentAsset(string $type, string $tag): void
  {
    if ($tag !== '') self::$componentAssets[$type][$tag] = $tag;
  }

  private static function markEmitted(string $id, string $format): bool
  {
    $key = "{$id}:{$format}";
    if (isset(self::$emittedAssets[$key])) return false;
    return self::$emittedAssets[$key] = true;
  }

  public static function bundleDirAssets(
    array $filesMap,
    string $format,
    string $mode = 'external',
    array $overrideMap = [],
    bool $dedupe = false,
  ): string {
    $filter = static fn(string $file) => str_ends_with($file, ".{$format}");
    $files = array_filter($overrideMap, $filter, ARRAY_FILTER_USE_KEY)
      ?: array_filter($filesMap, $filter, ARRAY_FILTER_USE_KEY);

    if ($files === []) return '';

    $id = self::assetId(dirname(array_first($files)));
    if ($dedupe && !self::markEmitted($id, $format)) return '';

    ksort($files, SORT_NATURAL);
    return self::buildAssetTag(implode("\n", array_map('file_get_contents', $files)), $id, $format, $mode);
  }

  private static function renderGlobalAsset(string $type, string $filename, string $mode, Config $config, string $indent = '  '): string
  {
    $path = "{$config->sitePath}/assets/{$type}/{$filename}.{$type}";
    if ($config->isDevMode && !is_file($path)) {
      Store::putContents($path, '');
    }
    if (!is_file($path)) return '';

    $id = self::assetId($path);
    if (!self::markEmitted($id, $type)) return '';

    return self::buildAssetTag(file_get_contents($path), $id, $type, $mode, $indent);
  }

  private static function renderDeferred(string $type, Config $config): string
  {
    $html = '';
    foreach (array_keys(self::$deferredAssets[$type]) as $name) {
      $html .= self::renderGlobalAsset($type, $name, 'external', $config);
    }
    return $html;
  }

  private static function assetHelper(string $type, string $defaultMode, Config $config): Closure
  {
    return static function (string $filename, ?string $modeOverwrite = null) use ($type, $defaultMode, $config): void {
      if (($modeOverwrite ?? $defaultMode) === 'inline') {
        $tag = self::renderGlobalAsset($type, $filename, 'inline', $config, indent: '');
        if ($tag !== '') echo "\n{$tag}";
      } else {
        self::$deferredAssets[$type][$filename] = true;
      }
    };
  }

  private static function buildAssetTag(string $content, string $id, string $format, string $mode, string $indent = '  '): string
  {
    if (trim($content) === '') return '';

    $config = Config::get();
    $minify = static fn(string $raw) => $format === 'css' ? Minifier::minifyCSS($raw) : Minifier::minifyJS($raw);

    if ($mode === 'inline') {
      $out = $config->minify ? $minify($content) : $content;
      return self::wrap($format === 'css' ? 'style' : 'script', $out, $indent);
    }

    $publicFolder = "{$config->publicPath}/generated-assets";
    $hash = substr(hash('xxh3', $content . ($config->minify ? ':min' : '')), 0, 8);
    $filename = "{$id}_{$hash}.{$format}";
    $filePath = "{$publicFolder}/{$filename}";

    if (!is_file($filePath)) {
      Store::putContents($filePath, $config->minify ? $minify($content) : $content);
    }

    $url = "/generated-assets/{$filename}";
    return $format === 'css'
      ? "{$indent}<link rel=\"stylesheet\" href=\"{$url}\">\n"
      : "{$indent}<script src=\"{$url}\" defer></script>\n";
  }

  private static function assetId(string $path): string
  {
    $relative = substr($path, strlen(Config::get()->sitePath) + 1);
    $cleaned = preg_replace(['#(?:^|/)\d+_#', '/\.(css|js)$/'], ['/', ''], $relative);
    return preg_replace('/-+/', '-', trim(str_replace('/', '-', $cleaned), '-'));
  }

  public static function getCached(Config $config, Page $page): ?string
  {
    if (!$page->isCacheable() || $config->isDevMode || $config->isStaticBuild) return null;

    $file = self::cacheFile($config, $page);
    return is_file($file) ? file_get_contents($file) : null;
  }

  public static function purgeCache(string $cache, string $public): void
  {
    foreach ([
      ...(glob("{$cache}/pages/*.html") ?: []),
      ...(glob("{$public}/generated-assets/*.html") ?: []),
    ] as $file) {
      if (is_file($file)) unlink($file);
    }

    if (is_file($meta = "{$cache}/image-meta.php")) {
      unlink($meta);
      if (function_exists('opcache_invalidate')) opcache_invalidate($meta, true);
    }
  }

  private static function cacheFile(Config $config, Page $page): string
  {
    $key = $page->isRoutable()
      ? hash('xxh3', $page->lang() . ':' . mb_strtolower(ltrim($page->url($page->lang()), '/')))
      : 'error-' . hash('xxh3', $page->lang());
    return "{$config->cachePath}/pages/{$key}.html";
  }
}
