<?php

declare(strict_types=1);

namespace Framework\Util;

use Framework\Util\HTMLMinifier;
use Framework\Util\JSMinifier;
use Framework\Util\CSSMinifier;
use Framework\Util\PerformanceMonitor;

final class Minifier
{
  public static function minifyHTML(string $input): string
  {
    return PerformanceMonitor::measure('HTML Minifier', fn() => HTMLMinifier::minify($input));
  }

  public static function minifyCSS(string $input): string
  {
    return PerformanceMonitor::measure('CSS Minifier', fn() => CSSMinifier::minify($input));
  }

  public static function minifyJS(string $input): string
  {
    return PerformanceMonitor::measure('JS Minifier', fn() => JSMinifier::minify($input));
  }

}