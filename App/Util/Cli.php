<?php
declare(strict_types=1);

namespace Framework\Util;

use Framework\Core\Config;
use Framework\Core\Store;
use Framework\Render\Renderer;
use RuntimeException;

final class Cli
{
  private const string HELP = <<<'TEXT'
    Usage: php folio <command>
      dev [on|off]                  Show or set development mode
      performance-monitor [on|off]  Show or set the performance monitor
      create page <path>            Create a page folder and missing parents (dev only)
      create component <name>       Create a component folder (dev only)
      create gallery <name>         Create a global gallery folder (dev only)
      create content-type <name>    Create a content type folder (dev only)
      create template <name>        Create a global template folder (dev only)
      create page-type <Name>       Create a Page subclass
      build                         Regenerate the static site
      clear cache                   Clear cached HTML and image metadata
      backup                        Create a ZIP backup
      --help                        Show this help
    TEXT;

  private const array SETTINGS = [
    'dev' => ['dev_mode', 'Dev mode'],
    'performance-monitor' => ['performance_monitor', 'Performance monitor'],
  ];

  private const array FOLDERS = [
    'page' => 'pages',
    'component' => 'components',
    'gallery' => 'assets/images',
    'content-type' => 'content',
    'template' => 'templates',
  ];

  public static function run(array $args): string
  {
    $roots = Config::roots();
    $project = "{$roots['site']}/project.php";
    if ($args === [] || $args === ['--help']) return self::HELP;

    if (isset(self::SETTINGS[$args[0]]) && (count($args) === 1
      || (count($args) === 2 && in_array($args[1], ['on', 'off'], true)))) {
      [$key, $label] = self::SETTINGS[$args[0]];
      $settings = require $project;
      $enabled = $settings[$key];
      if (!is_bool($enabled)) throw new RuntimeException("Project setting {$key} must be a boolean.");
      if (isset($args[1]) && $enabled !== ($args[1] === 'on')) {
        $settings[$key] = $enabled = $args[1] === 'on';
        Store::writeFile($project, $settings);
      }
      return "{$label}: " . ($enabled ? 'on' : 'off');
    }

    if ($args === ['build']) return StaticBuild::run($roots);

    if ($args === ['clear', 'cache']) {
      $cache = "{$roots['storage']}/cache";
      self::checkDirectory($roots['website'], "{$cache}/pages");
      self::checkDirectory($roots['website'], "{$roots['public']}/generated-assets");
      Renderer::purgeCache($cache, $roots['public']);
      return 'Cache cleared.';
    }

    if ($args === ['backup']) {
      $archive = Backup::archive($roots);
      if (!$archive) throw new RuntimeException('Backup failed. Check the backups directory for a same-name ZIP or write errors.');
      return 'Created ' . basename($roots['storage']) . '/backups/' . basename($archive);
    }

    if (count($args) === 3 && $args[0] === 'create') {
      if ($args[1] === 'page-type') return self::createType($roots, $args[2]);
      if (isset(self::FOLDERS[$args[1]])) {
        if ((require $project)['dev_mode'] !== true) throw new RuntimeException('Enable dev mode first: php folio dev on');
        return self::createFolder($roots, $args[1], $args[2]);
      }
    }
    throw new RuntimeException('Invalid command or arguments. Run php folio --help.');
  }

  private static function createFolder(array $roots, string $type, string $path): string
  {
    $page = $type === 'page';
    $segments = $page ? explode('/', $path) : [$path];
    $base = self::FOLDERS[$type];
    $parent = "{$roots['site']}/{$base}";
    self::checkDirectory($roots['website'], $parent);

    foreach ($segments as $index => $folder) {
      if (!preg_match('/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/D', $folder)) {
        throw new RuntimeException('Use lowercase folder names with letters, digits, hyphens or underscores.');
      }
      $target = "{$parent}/{$folder}";
      $slug = $page ? Store::stripOrder($folder) : $folder;
      $leaf = $index === array_key_last($segments);
      if (($page && $slug === 'template') || ($type === 'template' && in_array($slug, ['inherit', 'unset'], true))) {
        throw new RuntimeException("Reserved {$type} name: {$slug}");
      }
      if (is_link($target) || (file_exists($target) && ($leaf || !is_dir($target)))) {
        throw new RuntimeException("Path already exists: {$folder}");
      }
      foreach (Store::filesMap($parent) as $entry => $existing) {
        $entry = (string) $entry;
        if ($entry === $folder || ($page && !is_dir($existing) && !is_link($existing))) continue;
        if (strtolower($page ? Store::stripOrder($entry) : $entry) === $slug) {
          throw new RuntimeException("Folder name already in use: {$entry}");
        }
      }
      if ($page && !is_dir($target) && in_array($slug, ['home', 'error'], true)) {
        throw new RuntimeException("Reserved page name: {$slug}");
      }
      $parent = $target;
    }

    Store::checkWritePath($parent);
    mkdir($parent, 0755, true);
    return 'Created ' . basename($roots['site']) . "/{$base}/{$path}/. Reload in dev mode to scaffold.";
  }

  private static function checkDirectory(string $root, string $path): void
  {
    $device = stat($root)['dev'];
    foreach (explode('/', substr($path, strlen($root) + 1)) as $folder) {
      $root .= "/{$folder}";
      if (is_link($root) || (file_exists($root) && (!is_dir($root) || stat($root)['dev'] !== $device))) {
        throw new RuntimeException("Expected a local directory, not a link or mount: {$root}");
      }
    }
  }

  private static function createType(array $roots, string $name): string
  {
    $reserved = ['self', 'parent', 'int', 'float', 'bool', 'string', 'true', 'false', 'null', 'void', 'iterable', 'object', 'mixed', 'never'];
    if (!preg_match('/^[A-Z][A-Za-z0-9]*$/D', $name) || token_get_all("<?php {$name}")[1][0] !== T_STRING
      || in_array(strtolower($name), $reserved, true)) {
      throw new RuntimeException('Use a non-reserved PHP class name, e.g. Article.');
    }
    $dir = "{$roots['extend']}/Page";
    self::checkDirectory($roots['website'], $dir);
    Store::checkWritePath($dir);
    $files = Store::filesMap($dir) + Store::filesMap("{$roots['framework']}/App/Page");
    if (isset(array_change_key_case($files)[strtolower("{$name}.php")])) throw new RuntimeException("Page type name already in use: {$name}");

    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $source = <<<PHP
      <?php
      declare(strict_types=1);

      namespace Extend\Page;

      use Framework\Page\Page;

      final class {$name} extends Page
      {
      }

      PHP;
    $file = fopen("{$dir}/{$name}.php", 'xb');
    if (fwrite($file, $source) !== strlen($source)) throw new RuntimeException("Could not write {$name}.php");
    fclose($file);
    return 'Created ' . basename($roots['site']) . "/extend/Page/{$name}.php. Set 'type' => '" . lcfirst($name) . "' in the page config.";
  }
}
