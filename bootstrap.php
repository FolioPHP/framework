<?php
declare(strict_types=1);

use Framework\Core\App;
use Framework\Core\Config;
use Framework\Util\Cli;

return static function (string $website): void {
  $roots = [
    'website' => $website,
    'framework' => __DIR__,
    'public' => "{$website}/public",
    'site' => $site = "{$website}/site",
    'extend' => "{$site}/extend",
    'storage' => "{$website}/storage",
  ];
  foreach (['public', 'site', 'storage'] as $key) {
    $root = realpath($roots[$key]);
    if ($root !== false && str_starts_with(__DIR__ . '/', $root . '/')) {
      throw new RuntimeException("Install the framework outside {$roots[$key]}.");
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
