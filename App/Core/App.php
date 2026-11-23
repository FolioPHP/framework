<?php
declare(strict_types=1);

namespace {
  function pd(mixed ...$vars): never
  {
    echo '<pre>';
    foreach ($vars as $var) print_r($var);
    echo '</pre>';
    exit;
  }

  function dd(mixed ...$vars): never
  {
    echo '<pre>';
    var_dump(...$vars);
    echo '</pre>';
    exit;
  }
}

namespace Framework\Core {
  use Framework\Assets\Image;
  use Framework\Page\Page;
  use Framework\Page\PageRepository;
  use Framework\Render\Component;
  use Framework\Render\Renderer;
  use Framework\Util\Backup;
  use Framework\Util\PerformanceMonitor;

  final class App
  {
    public static function boot(): void
    {
      PerformanceMonitor::start('Total Request');

      $config = Config::get();
      self::initDevWorkspace($config);
      $html = self::dispatch($config);

      PerformanceMonitor::end('Total Request');
      PerformanceMonitor::report();
      echo $html;
    }

    private static function initDevWorkspace(Config $config): void
    {
      if (!$config->isDevMode) return;

      PerformanceMonitor::start('Bootstrap');
      self::ensureFolders($config->pagesPath, ['home', 'error']);
      self::ensureFolders("{$config->sitePath}/assets", ['css', 'js', 'images']);
      self::ensureFolders($config->sitePath, ['templates', 'components']);
      if (!is_dir($extendDir = Config::roots()['extend'] . '/Page')) {
        Store::checkWritePath($extendDir);
        mkdir($extendDir, 0755, true);
      }

      Component::syncAll();
      Image::syncAltFiles($config->imagesPath);
      PerformanceMonitor::end('Bootstrap');
    }

    private static function ensureFolders(string $path, array $folders): void
    {
      $existing = array_map(
        static fn(string $dir) => strtolower(Store::stripOrder(basename($dir))),
        glob("{$path}/*", GLOB_ONLYDIR) ?: [],
      );

      foreach (array_diff($folders, $existing) as $folder) {
        Store::checkWritePath($target = "{$path}/{$folder}");
        mkdir($target, 0755, true);
      }
    }

    private static function dispatch(Config $config): string
    {
      $rawPath = explode('?', (string) ($_SERVER['REQUEST_URI'] ?? '/'), 2)[0];
      $path = rawurldecode($rawPath);
      $validPath = str_starts_with($rawPath, '/') && mb_check_encoding($path, 'UTF-8')
        && !preg_match('/%(?![a-f0-9]{2})/i', $rawPath)
        && !preg_match('#[\x00-\x1F\x7F]|(?:^|/)\.{1,2}(?:/|$)#', $path)
        && !str_contains($path, '\\');
      $canonicalPath = $validPath ? '/' . mb_strtolower(preg_replace('#/+#', '/', trim($path, '/'))) : null;
      $segment = explode('/', substr($canonicalPath ?? '/', 1), 2)[0];
      $lang = in_array($segment, $config->supportedLangs, true) ? $segment : $config->defaultLang;

      Image::setRequestLang($lang);
      $repository = new PageRepository($config, $lang);
      $page = self::resolvePage($canonicalPath, $path, $repository);
      $html = Renderer::getCached($config, $page) ?? Renderer::render($page);

      if ($config->backup) (new Backup($config))->check();
      return $html;
    }

    private static function resolvePage(?string $canonicalPath, string $path, PageRepository $repository): Page
    {
      foreach ($repository->all() as $page) {
        if ($canonicalPath === null || !$page->isRoutable()) continue;
        $url = mb_strtolower($page->url());
        if ($url !== $canonicalPath) continue;
        if ($path !== $url) {
          header('Location: ' . implode('/', array_map('rawurlencode', explode('/', $url))), true, 301);
          exit;
        }
        return $page;
      }

      http_response_code(404);
      return $repository->findError();
    }
  }
}
