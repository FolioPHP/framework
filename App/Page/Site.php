<?php
declare(strict_types=1);

namespace Framework\Page;

final class Site extends Page
{
  public function langs(): array { return $this->projectConfig->supportedLangs; }

  public function buildConfigBlueprint(): array
  {
    return [
      'render_assets' => 'external',
      'data_format' => 'txt',
      'show_header' => true,
      'show_footer' => true,
    ];
  }

  public function buildFieldsBlueprint(): array
  {
    return [
      'title' => 'My Website',
      'author' => 'Site author name',
      'description' => 'A description of my website',
    ];
  }
}
