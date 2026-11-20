<?php
declare(strict_types=1);

namespace Framework\Util;

use Framework\Assets\Image;
use Framework\Core\Config;
use Framework\Core\Store;
use Framework\Page\Page;
use Framework\Page\PageRepository;
use Framework\Render\Renderer;
use RuntimeException;
use Throwable;

final class StaticBuild
{
  private const string MARKER = '.folio-build';
  private const string SIGNATURE = "Folio static build\n";
  private const array GENERATED = ['generated-assets', 'generated-images'];

  public static function run(array $roots): string
  {
    $storage = $roots['storage'];
    Store::checkWritePath($storage);
    $output = "{$storage}/dist";
    self::checkOutput($output);
    $work = "{$storage}/.folio-build-" . bin2hex(random_bytes(8));
    $stage = "{$work}/site";
    $previous = "{$work}/previous";
    mkdir($work, 0755, true);

    try {
      $config = Config::forBuild($stage);
      $routes = self::routes($config);
      Store::copyTree($roots['public'], $stage, static fn(string $path, bool $dir) =>
        !in_array($path, self::GENERATED, true) && ($dir || preg_match(
          '/\.(?:html?|css|m?js|json|webmanifest|xml|txt|map|wasm|pdf|ico|svg|avif|webp|jpe?g|png|gif|woff2?|ttf|otf|mp[34]|webm|ogg|wav)$/iD',
          $path,
        )));

      foreach ($routes as $file => [$lang, $path]) {
        $target = "{$stage}/{$file}";
        if (file_exists($target)) throw new RuntimeException("Build output collides with a public asset: {$file}");
        Image::setRequestLang($lang);
        $page = (new PageRepository($config, $lang))->findByPath($path)
          ?? throw new RuntimeException("Page disappeared during the build: {$path}");
        Store::putContents($target, self::render($page, $file));
      }
      Store::putContents("{$stage}/" . self::MARKER, self::SIGNATURE);

      if (self::checkOutput($output)) rename($output, $previous);
      try {
        rename($stage, $output);
      } catch (Throwable $error) {
        if (is_dir($previous)) rename($previous, $output);
        throw $error;
      }
      if (is_dir($previous)) Store::removeTree($previous);
      return 'Built ' . (count($routes) - 1) . ' pages and 404.html in ' . basename($storage) . '/dist/.';
    } finally {
      // Leave the previous output recoverable if rollback itself failed.
      if (!is_dir($previous)) Store::removeTree($work);
    }
  }

  private static function routes(Config $config): array
  {
    if (!in_array($config->defaultLang, $config->supportedLangs, true)) {
      throw new RuntimeException('The default language must be a supported language.');
    }

    $routes = [];
    foreach ($config->supportedLangs as $lang) {
      if (!is_string($lang) || !preg_match('/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/D', $lang)) {
        throw new RuntimeException('Invalid language code in supported_langs.');
      }
      Image::setRequestLang($lang);
      $repository = new PageRepository($config, $lang);
      foreach ($repository->all() as $page) {
        if (!$page->isRoutable()) continue;
        $url = $page->url($lang);
        $file = self::destination($url, $lang, $config);
        if (isset($routes[$file])) throw new RuntimeException("Duplicate page URL: {$url}");
        $routes[$file] = [$lang, $page->path()];
      }
      if ($lang === $config->defaultLang) {
        $error = $repository->findById('error') ?? throw new RuntimeException('Missing required error page.');
        $routes['404.html'] = [$lang, $error->path()];
      }
    }
    if (!isset($routes['index.html'])) throw new RuntimeException('Missing required home page.');
    return $routes;
  }

  private static function destination(string $url, string $lang, Config $config): string
  {
    if ($url === '/' && $lang === $config->defaultLang) return 'index.html';
    $segments = explode('/', substr($url, 1));
    $first = $segments[0];
    $routeLang = in_array($first, $config->supportedLangs, true) ? $first : $config->defaultLang;

    if (!str_starts_with($url, '/') || $url !== mb_strtolower($url) || $routeLang !== $lang
      || in_array($first, [...self::GENERATED, '404.html'], true) || in_array('index.html', $segments, true)
      || !array_all($segments, static fn(string $segment) => preg_match('/^[\pL\pM\pN_-]+(?:\.[\pL\pM\pN_-]+)*$/uD', $segment))) {
      throw new RuntimeException("Unsafe or noncanonical page URL for {$lang}: {$url}");
    }
    return substr($url, 1) . '/index.html';
  }

  private static function checkOutput(string $output): bool
  {
    if (!is_link($output) && !file_exists($output)) return false;
    if (!self::ownsOutput($output) || stat($output)['dev'] !== stat(dirname($output))['dev']) {
      throw new RuntimeException("Refusing to replace {$output}/: not a local Folio build directory.");
    }
    return true;
  }

  private static function ownsOutput(string $output): bool
  {
    $marker = "{$output}/" . self::MARKER;
    return !is_link($output) && is_dir($output) && !is_link($marker) && is_file($marker)
      && file_get_contents($marker) === self::SIGNATURE;
  }

  private static function render(Page $page, string $file): string
  {
    $level = ob_get_level();
    ob_start();
    try {
      $html = Renderer::render($page);
      if (ob_get_length() !== 0) throw new RuntimeException('Unexpected output outside the page template.');
      return $html;
    } catch (Throwable $error) {
      throw new RuntimeException("Rendering {$file}: {$error->getMessage()}", previous: $error);
    } finally {
      while (ob_get_level() > $level) ob_end_clean();
    }
  }
}
