<?php
declare(strict_types=1);

namespace Framework\Core;

use ArrayAccess;
use ArrayIterator;
use Countable;
use DateTimeImmutable;
use IteratorAggregate;
use Stringable;
use Traversable;

final class Field implements IteratorAggregate, ArrayAccess, Countable, Stringable
{
  public function __construct(private mixed $value) {}

  public function offsetExists(mixed $offset): bool { return is_array($this->value) && isset($this->value[$offset]); }
  public function offsetGet(mixed $offset): mixed { return $this->value[$offset]; }
  public function offsetSet(mixed $offset, mixed $value): void { $this->value[$offset] = $value; }
  public function offsetUnset(mixed $offset): void { unset($this->value[$offset]); }

  public function __toString(): string { return (string) $this->value; }
  public function getIterator(): Traversable { return new ArrayIterator(is_array($this->value) ? $this->value : (array) $this->value); }

  public function uppercase(): self { return clone($this, ['value' => mb_strtoupper((string) $this->value)]); }
  public function lowercase(): self { return clone($this, ['value' => mb_strtolower((string) $this->value)]); }
  public function isNotEmpty(): bool { return !empty($this->value); }
  public function isEmpty(): bool { return empty($this->value); }
  public function or(string $fallback): string { return $this->isNotEmpty() ? (string) $this->value : $fallback; }
  public function length(): int { return is_array($this->value) ? count($this->value) : mb_strlen((string) $this->value); }
  public function count(): int { return $this->length(); }

  public function excerpt(int $chars): string
  {
    $str = (string) $this->value;
    return mb_strlen($str) <= $chars ? $str : mb_substr($str, 0, $chars) . '…';
  }

  public function words(int $count): string
  {
    $words = preg_split('/\s+/u', trim((string) $this->value), -1, PREG_SPLIT_NO_EMPTY);
    if (!$words) return '';

    return count($words) <= $count
      ? implode(' ', $words)
      : implode(' ', array_slice($words, 0, $count)) . '…';
  }

  public function toDate(string $format = 'Y-m-d'): string
  {
    return empty($this->value) ? '' : (new DateTimeImmutable((string) $this->value))->format($format);
  }

  public function year(): string { return $this->toDate('Y'); }
  public function month(): string { return $this->toDate('F'); }
  public function day(): string { return $this->toDate('d'); }
}
