<?php
declare(strict_types=1);

namespace Framework\Content;

use Framework\Assets\Gallery;
use Framework\Assets\Image;
use Framework\Core\Field;
use Framework\Core\Store;
use Framework\Render\Renderer;

final class ContentItem
{
  private ?array $loadedFields = null;

  public function id(): string { return $this->id; }
  public function path(): string { return $this->path; }
  public function type(): string { return $this->type; }
  public function lang(): string { return $this->lang; }
  public function images(): object { return Renderer::assetLookup($this->image(...)); }
  public function galleries(): object { return Renderer::assetLookup($this->gallery(...)); }

  public function &fields(): array
  {
    $this->loadedFields ??= Store::loadFields($this->path, $this->lang, $this->dataFormat, templateFiles: $this->typeFiles);
    return $this->loadedFields;
  }

  public function __construct(
    private readonly string $path,
    private readonly string $id,
    private readonly string $type,
    private readonly string $lang,
    private readonly array $typeFiles,
    private readonly string $dataFormat = 'txt',
    bool $devMode = false,
  ) {
    if ($devMode) $this->fields();
  }

  public function __get(string $name): Field
  {
    return new Field($this->fields()[$name] ?? null);
  }

  public function image(string $filename): ?Image
  {
    return Image::load($filename, $this->path);
  }

  public function gallery(string $folder): Gallery
  {
    return Gallery::load($folder, $this->path);
  }
}
