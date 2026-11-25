<?php
declare(strict_types=1);

namespace Framework\Render;

use InvalidArgumentException;
use LogicException;

final class SlotCapture
{
  private array $blocks = [];

  public function open(Component $component, array $inline): void
  {
    ob_start();
    $this->blocks[] = [
      'component' => $component,
      'inline' => $inline,
      'level' => ob_get_level(),
      'name' => 'default',
      'explicit' => false,
      'defaultSelected' => false,
      'values' => [],
    ];
  }

  public function close(): void
  {
    $block = $this->current();
    if ($block['explicit']) {
      throw new LogicException("Close slot {$block['name']} before closing its component block.");
    }

    self::save($block, ob_get_clean());
    array_pop($this->blocks);
    if (!$block['defaultSelected'] && mb_trim($block['values']['default'] ?? '') === '') {
      unset($block['values']['default']);
    }
    echo $block['component']->slots($block['values']);
  }

  public function __get(string $name): object
  {
    $block = &$this->current();
    if (!array_key_exists($name, $block['inline']) && !array_key_exists($name, $block['values'])) {
      $name = str_replace('_', '-', $name);
    }
    // Fetching a handle to close an explicit slot must not switch the active slot.
    if (!$block['explicit']) $this($name);

    return new readonly class($this, $name) {
      public function __construct(private SlotCapture $capture, private string $name) {}
      public function open(): void { $this->capture->openSlot($this->name); }
      public function close(): void { $this->capture->closeSlot($this->name); }
    };
  }

  public function __invoke(string $name = 'default'): void
  {
    $block = &$this->current();
    if ($name === '') throw new InvalidArgumentException('Component slot names cannot be empty.');
    if ($block['explicit']) {
      throw new LogicException("Close slot {$block['name']} before switching slots.");
    }
    if ($name === 'default') $block['defaultSelected'] = true;
    self::select($block, $name);
  }

  public function openSlot(string $name): void
  {
    $this($name);
    $block = &$this->current();
    $block['explicit'] = true;
  }

  public function closeSlot(string $name): void
  {
    $block = &$this->current();
    if (!$block['explicit'] || $block['name'] !== $name) {
      throw new LogicException("Cannot close slot {$name}: it is not the open explicit slot.");
    }
    $block['explicit'] = false;
    self::select($block, 'default');
  }

  public function discardFrom(int $level): void
  {
    while ($this->blocks !== [] && array_last($this->blocks)['level'] >= $level) {
      array_pop($this->blocks);
    }
  }

  private function &current(): array
  {
    $key = array_key_last($this->blocks);
    if ($key === null || $this->blocks[$key]['level'] !== ob_get_level()) {
      throw new LogicException('No component block is open in the current output buffer.');
    }
    return $this->blocks[$key];
  }

  private static function select(array &$block, string $name): void
  {
    if ($name !== $block['name']) {
      self::save($block, ob_get_contents());
      ob_clean();
      $block['name'] = $name;
    }
  }

  private static function save(array &$block, string $output): void
  {
    $name = $block['name'];
    $block['values'][$name] = ($block['values'][$name] ?? '') . $output;
  }
}
