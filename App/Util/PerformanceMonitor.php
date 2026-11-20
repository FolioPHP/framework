<?php
declare(strict_types=1);

namespace Framework\Util;

use Framework\Core\Config;

final class PerformanceMonitor
{
  private const array PUBLIC_METRICS = [
    'Total Request' => 'app',
    'Bootstrap' => 'bootstrap',
    'Page Constructor' => 'pages',
    'Page rendering' => 'render',
    'HTML Minifier' => 'minify-html',
    'CSS Minifier' => 'minify-css',
    'JS Minifier' => 'minify-js',
  ];

  private static array $timers = [];

  public static function start(string $key): void
  {
    if (!Config::get()->performanceMonitor) return;

    self::$timers[$key] ??= ['total' => 0, 'count' => 0, 'max' => 0];
    self::$timers[$key]['start'] = hrtime(true);
  }

  public static function end(string $key): void
  {
    if (!isset(self::$timers[$key]['start'])) return;

    $duration = hrtime(true) - self::$timers[$key]['start'];
    unset(self::$timers[$key]['start']);

    self::$timers[$key]['total'] += $duration;
    self::$timers[$key]['count']++;
    self::$timers[$key]['max'] = max(self::$timers[$key]['max'], $duration);
  }

  public static function measure(string $key, callable $callback): mixed
  {
    self::start($key);
    try {
      return $callback();
    } finally {
      self::end($key);
    }
  }

  public static function report(int $top = 0): void
  {
    if (!Config::get()->performanceMonitor || self::$timers === []) return;

    $timers = self::$timers;
    uasort($timers, static fn(array $a, array $b) => $b['total'] <=> $a['total']);
    if ($top > 0) $timers = array_slice($timers, 0, $top, true);

    $rows = [];
    foreach ($timers as $key => $timer) {
      if ($timer['count'] === 0) continue;
      $totalMs = $timer['total'] / 1_000_000;
      $rows[] = [
        'key' => $key,
        'total_ms' => round($totalMs, 2),
        'count' => $timer['count'],
        'avg_ms' => round($totalMs / $timer['count'], 2),
        'max_ms' => round($timer['max'] / 1_000_000, 2),
      ];
    }

    self::outputServerTiming($rows);
    self::outputToTerminal($rows);
  }

  private static function outputToTerminal(array $data): void
  {
    $lines = [
      '',
      "\033[1;36m╔═══════════════════════════════════════════════════════════════╗\033[0m",
      "\033[1;36m║               ⚡ PERFORMANCE MONITOR REPORT                   ║\033[0m",
      "\033[1;36m╚═══════════════════════════════════════════════════════════════╝\033[0m",
    ];

    foreach ($data as $row) {
      $details = $row['count'] > 1
        ? sprintf('  (n=%d, avg=%.2f, max=%.2f)', $row['count'], $row['avg_ms'], $row['max_ms'])
        : '';
      $lines[] = sprintf("  %-38s \033[33m%8.2f ms\033[0m%s", $row['key'], $row['total_ms'], $details);
    }

    $lines[] = "\033[1;36m═══════════════════════════════════════════════════════════════\033[0m";
    $lines[] = '';

    file_put_contents('php://stderr', implode("\n", $lines));
  }

  private static function outputServerTiming(array $data): void
  {
    if (PHP_SAPI === 'cli' || headers_sent()) return;

    $metrics = [];
    foreach ($data as $row) {
      if (isset(self::PUBLIC_METRICS[$row['key']])) {
        $metrics[] = sprintf('%s;dur=%.2F', self::PUBLIC_METRICS[$row['key']], $row['total_ms']);
      }
    }
    if ($metrics !== []) header('Server-Timing: ' . implode(', ', $metrics), false);
  }
}
