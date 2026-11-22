<?php
declare(strict_types=1);

use Framework\Core\App;
use Framework\Core\Config;
use Framework\Util\Cli;

return static function (string $website, array $options = []): void {
  $names = ['site' => 'site', 'storage' => 'storage'];
  $custom = array_key_exists('roots', $options) ? $options['roots'] : [];
  if (!is_array($custom) || array_diff_key($custom, $names)) {
    throw new RuntimeException('The roots option must be an array with only site and storage keys.');
  }
  foreach ($custom as $key => $path) {
    $name = is_string($path) ? basename($path) : '';
    if ($path !== "{$website}/{$name}" || !preg_match('/\A[a-z0-9][a-z0-9_-]*\z/', $name)
      || in_array($name, ['public', 'framework', 'vendor'], true)) {
      throw new RuntimeException("The {$key} root must be a non-reserved directory directly inside the project.");
    }
    $names[$key] = $name;
  }
  if ($names['site'] === $names['storage']) throw new RuntimeException('The site and storage roots must be distinct.');
  $roots = [
    'website' => $website,
    'framework' => __DIR__,
    'public' => "{$website}/public",
    'site' => $site = "{$website}/{$names['site']}",
    'extend' => "{$site}/extend",
    'storage' => "{$website}/{$names['storage']}",
  ];
  $frameworkRoot = strtolower(__DIR__ . '/');
  $publicRoot = strtolower((realpath($roots['public']) ?: $roots['public']) . '/');
  foreach (['public', 'site', 'storage'] as $key) {
    $path = $roots[$key];
    $root = $key === 'public' ? $publicRoot : strtolower((realpath($path) ?: $path) . '/');
    if (str_starts_with($frameworkRoot, $root)) throw new RuntimeException("Install the framework outside {$path}.");
    if ($custom !== [] && $key !== 'public' && (is_link($path) || (file_exists($path) && !is_dir($path))
      || str_starts_with($root, $frameworkRoot) || str_starts_with($root, $publicRoot) || str_starts_with($publicRoot, $root))) {
      throw new RuntimeException("The {$key} root must be a local directory outside public and the framework.");
    }
  }

  $prefixes = ['Framework\\' => "{$roots['framework']}/App/", 'Extend\\' => "{$roots['extend']}/"];
  spl_autoload_register(static function (string $class) use ($prefixes): void {
    foreach ($prefixes as $prefix => $dir) {
      if (!str_starts_with($class, $prefix)) continue;
      $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
      if (is_file($file)) require $file;
      return;
    }
  });
  Config::initialize($roots);

  if (PHP_SAPI !== 'cli') {
    App::boot();
    return;
  }

  set_error_handler(static fn(int $level, string $message) => throw new RuntimeException($message), E_WARNING);
  try {
    echo Cli::run(array_slice($_SERVER['argv'], 1)) . PHP_EOL;
  } catch (Throwable $error) {
    fwrite(STDERR, "folio: {$error->getMessage()}\n");
    exit(1);
  } finally {
    restore_error_handler();
  }
};
