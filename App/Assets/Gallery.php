<?php
declare(strict_types=1);

namespace Framework\Assets;

use Exception;
use Framework\Core\Collection;
use Framework\Core\Config;
use Stringable;

final class Gallery extends Collection implements Stringable
{
  public static function load(string $folder, ?string $basePath = null): self
  {
    $basePath ??= Config::get()->imagesPath;
    $folderPath = Image::resolveFolder($basePath, $folder)
      ?? throw new Exception("Gallery not found: {$folder}");

    return new self(array_map(static fn(string $file) => new Image($file), Image::scanImages($folderPath)));
  }

  public function image(string $name): Image
  {
    foreach (array_unique([$name, str_replace('_', '-', $name)]) as $key) {
      $match = array_find($this->items, static fn(Image $img) => Image::matches($img->sourcePath, $key));
      if ($match !== null) return $match;
    }
    throw new Exception("Image '{$name}' not found in gallery.");
  }

  public function __get(string $name): Image { return $this->image($name); }

  /** Proxy Image modifiers (resize, alt) immutably across every image in the gallery. */
  public function __call(string $method, array $args): static
  {
    return clone($this, [
      'items' => array_map(static fn(Image $image) => $image->$method(...$args), $this->items),
    ]);
  }

  public function __toString(): string
  {
    return implode('', array_map(static fn(Image $image) => '<br>' . $image, $this->items));
  }
}
