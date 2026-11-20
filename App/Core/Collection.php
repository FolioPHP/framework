<?php
declare(strict_types=1);

namespace Framework\Core;

use ArrayIterator;
use Countable;
use Framework\Assets\Image;
use Framework\Page\Page;
use IteratorAggregate;

class Collection implements IteratorAggregate, Countable
{
  public function __construct(
    protected array $items = [],
    private bool $isOrdered = false,
    private readonly string $idPrefix = '',
  ) {}

  public function getIterator(): ArrayIterator
  {
    return new ArrayIterator($this->items);
  }

  public function limit(int $count): static
  {
    return clone($this, ['items' => array_slice($this->items, 0, $count, true)]);
  }

  public function offset(int $offset): static
  {
    return clone($this, ['items' => array_slice($this->items, $offset, null, true)]);
  }

  public function shuffle(): static
  {
    $keys = array_keys($this->items);
    shuffle($keys);
    return clone($this, ['items' => array_replace(array_flip($keys), $this->items)]);
  }

  public function reverse(): static
  {
    $sorted = $this->items;
    if (!$this->isOrdered && $sorted !== [] && array_first($sorted) instanceof Page) {
      uasort($sorted, static fn(Page $a, Page $b) =>
        ($b->fields()['publish_date'] ?? '') <=> ($a->fields()['publish_date'] ?? '')
      );
    }

    return clone($this, ['items' => array_reverse($sorted, true)]);
  }

  public function ordered(): static
  {
    $ordered = array_filter($this->items, fn(mixed $item) => $this->extractOrder($item) !== null);
    uasort($ordered, fn(mixed $a, mixed $b) => $this->extractOrder($a) <=> $this->extractOrder($b));
    return clone($this, ['items' => $ordered, 'isOrdered' => true]);
  }

  public function filter(callable $callback): static
  {
    return clone($this, ['items' => array_filter($this->items, $callback, ARRAY_FILTER_USE_BOTH)]);
  }

  public function exclude(array $ids): static
  {
    $excluded = [];
    foreach ($ids as $id) $excluded[mb_strtolower($id)] = true;
    return $this->filter(static fn(mixed $item) => !method_exists($item, 'id') || !isset($excluded[$item->id()]));
  }

  public function first(): mixed
  {
    return array_first($this->items);
  }

  public function last(): mixed
  {
    return array_last($this->items);
  }

  public function count(): int
  {
    return count($this->items);
  }

  public function findById(string $id): mixed
  {
    $id = mb_strtolower($id);
    return $this->items[$id] ?? (!str_contains($id, '/') ? ($this->items[$this->idPrefix . $id] ?? null) : null);
  }

  public function page(string $id = ''): ?Page
  {
    if ($id === '') return null;
    $found = $this->findById($id);
    return $found instanceof Page ? $found : null;
  }

  public function children(): static
  {
    return $this->filter(fn(mixed $item) => $item instanceof Page
      ? dirname($item->path()) === $item->repository()->site->path()
      : method_exists($item, 'id') && !str_contains(substr($item->id(), strlen($this->idPrefix)), '/'));
  }

  public function __get(string $name): mixed
  {
    if (str_contains($name, '/')) return null;
    $key = mb_strtolower($name);
    return $this->items[$this->idPrefix . $key] ?? $this->items[$this->idPrefix . str_replace('_', '-', $key)] ?? null;
  }

  public function __call(string $name, array $arguments): mixed
  {
    if ($arguments === []) return $this->first()?->fields()[$name] ?? null;

    $value = $arguments[0];
    return $this->filter(static function (mixed $item) use ($name, $value): bool {
      $fieldValue = $item->fields()[$name] ?? null;
      return match (true) {
        is_array($value) => is_array($fieldValue) && array_diff($value, $fieldValue) === [],
        is_array($fieldValue) => in_array($value, $fieldValue, true),
        default => $fieldValue === $value,
      };
    });
  }

  protected function extractOrder(mixed $item): ?int
  {
    return $item instanceof Page ? $item->order() : Store::extractOrder($item instanceof Image ? $item->sourcePath : $item->path());
  }
}
