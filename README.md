
Folio is a lightweight, dependency-free, file-based PHP framework and static site generator for multilingual websites with an intuitive API and flexible content management.

## Table of Contents

- [Introduction](#introduction)
- [Quickstart](#quickstart)
- [Pages](#pages)
- [Content](#content)
- [Fields](#fields)
- [Templates](#templates)
- [Components](#components)
- [Assets](#assets)
- [Querying Pages & Content](#querying-pages--content)
- [Multilingual Support](#multilingual-support)
- [Configuration](#configuration)
- [Command Line](#command-line)
- [Static Site Generator](#static-site-generator)
- [API Reference](#api-reference)

---

## Introduction

FolioPHP is designed to simplify and speed up building portfolios, brochure sites, and blogs while keeping maintenance costs low by avoiding external dependencies.

### Core Concepts

- **Everything lives in `site/`:** All project content, templates, components, assets, and configuration are stored in the `site/` directory. For a standard project, you rarely need to touch anything outside it.
- **Everything is a folder**: To create a page, create a folder inside `pages/`. Creating a new component is as easy as creating a folder inside `components/`. The same applies to all kinds of content.
- **Auto-scaffolding in dev mode:** Creating an empty folder for a page, content type, or component automatically generates its boilerplate files on the next request.
- **Clearing the cache:** Run `php folio clear cache` to remove cached HTML and image metadata from `storage/cache/` without deleting source files or generated assets.

---

## Quickstart

**Requirements:** PHP 8.5 with `gd` and `mbstring`. Backups also require `zip`. Composer is not needed to run Folio.

From a new project directory:

```sh
git clone https://github.com/FolioPHP/framework.git framework
mkdir -p public site
```

Create `site/project.php` to leave minification off while the minifiers are unfinished. The first dev request fills in the other defaults:

```php
<?php
return ['minify_code' => false];
```

Create `public/index.php`:

```php
<?php
(require dirname(__DIR__) . '/framework/bootstrap.php')(dirname(__DIR__));
```

Create `folio` next to `public/`:

```php
<?php
require __DIR__ . '/public/index.php';
```

Start the local server from the project root:

```sh
php -S localhost:8000 -t public
```

Open [http://localhost:8000/](http://localhost:8000/) once to scaffold `site/`, then add `site/pages/home/template.php` and reload:

```php
<h1><?= htmlspecialchars((string) $page->title, ENT_QUOTES, 'UTF-8') ?></h1>
```

---

## Pages

Pages are represented by folders inside `site/pages/`. Creating a new page is as simple as creating a new folder.

`site/pages/home/` serves `/` and the localized home URLs (e.g., `/de`). `site/pages/error/` renders HTTP 404 responses and is not directly routable.

### Key Features

- **Auto-generated files:** `config.php` and language files (e.g., `en.txt`) are automatically created inside new page folders on the next dev request.
- **Nesting:** Nest folders inside each other to build hierarchical URL and page structures.
- **Ordering:** Prefix folder names with numbers and an underscore (e.g., `01_about`) to control order; the prefix is ignored in IDs and URLs.
- **IDs:** A page ID is its lowercase path relative to `site/pages/`, without order prefixes. For example, `01_blog/02_post-1/` has the ID `blog/post-1`. Reordering does not change IDs; renaming or moving a folder does.
- **Ignored folders:** Prefix a folder with `_` (e.g., `_notes`) to prevent it from being parsed as a page.
- **Page-scoped files:**
  - Add any `.css` or `.js` file inside a page folder to include page-specific styles or scripts.
  - Add `header.php` or `footer.php` to override the default `header` or `footer` component for that page. Use `show_header` or `show_footer` to disable either region.
  - Add `template.php` to define a custom template for that page.
- **Document metadata:** Set `meta_title` and `meta_description` in the page's language files to populate the document title and meta description.
- **Active states:** `$page->isActive()` is `true` only on the currently rendered page, while `$page->hasActiveChild()` is `true` if any descendant is active:
  ```php
  <a href="<?= $page->url() ?>" <?= $page->isActive() || $page->hasActiveChild() ? 'aria-current="page"' : '' ?>>Home</a>
  ```

### Page Controllers

Add a `controller.php` file to run logic when the page's fields are first loaded: during construction in dev mode, on demand in production. An HTML cache hit can skip the controller. `$this` is the page; use `fields()` to update raw values. Add the example below to a page after defining a `text` field.

```php
<?php
declare(strict_types=1);

$wordCount = str_word_count($this->fields()['text']);
$this->fields()['read_time'] = ceil($wordCount / 200);
```

### Custom Page Types

Place `Page` subclasses inside `site/extend/Page/` under the `Extend\Page` namespace to define custom blueprints, routing flags, or methods without modifying `framework/App/`. Activate a type by setting `'type'` in a page's `config.php`. You can also use `php folio create page-type Article` to create the class file.

```php
<?php
declare(strict_types=1);

namespace Extend\Page;

use Framework\Page\Page;

final class Article extends Page
{
    public function buildFieldsBlueprint(): array
    {
        return [...parent::buildFieldsBlueprint(), 'author' => ''];
    }
}
```

```php
// site/pages/my-post/config.php
return [
    'type' => 'article',
    // ...
];
```

---

## Content

Content types are collections of structured items (such as team members, portfolio projects, or testimonials) that share a field blueprint and can be queried in templates without having URLs of their own.

Compared to pages, content items:
1. Have no URLs of their own.
2. Do not support `template.php` files.
3. Automatically sync their fields with a `fields.txt` blueprint inside their content-type folder.

### Directory Structure

```text
site/content/
  team/                    — Content type (auto-creates fields.txt)
    fields.txt             — Blueprint for all team items
    victor/                — Content item
      en.txt               — English content (synced with fields.txt)
      de.txt               — German content (synced with fields.txt)
      portrait.jpg         — Item-specific image
      gallery/             — Item-specific gallery
    jane/
      en.txt
      de.txt
  portfolio/               — Another content type
    fields.txt
    project-one/
      en.txt
      de.txt
      cover.jpg
```

### Workflow

1. Create a folder inside `site/content/` (e.g., `team`) and reload to auto-create its `fields.txt` blueprint.
2. Add subfolders inside the content-type folder for each item and reload to auto-create language files synced from `fields.txt`.
3. Access items in templates using the `$content` helper.

### Accessing Content

```php
// All items of a type (returns a ContentCollection)
<?php foreach ($content('team')->limit(3) as $member): ?>
  <article>
    <?= $member->image('portrait')->resize(width: 300) ?>
    <h2><?= $member->title ?></h2>
    <p><?= $member->bio->excerpt(150) ?></p>
  </article>
<?php endforeach; ?>

// Single item via path shorthand
<?= $content('team/victor')->title ?>
<?= $content('team/victor')->description->excerpt(100) ?>

// Single item via property access
<?= $content('team')->victor->title ?>
<?= $content->team->victor->title ?>
<?= $content('team')->victor->gallery('gallery')->image('detail-1') ?>
```

Content items support [direct images and galleries](#images). Pages support direct images only. Both support [field modifiers](#field-modifiers) and [collection queries](#collection-methods).

Content item IDs include the type. For example, `site/content/01_team/02_victor/` has the ID `team/victor`.

---

## Fields

Fields store language-synced content. Folio supports three storage formats:

1. **`.txt` (Default):** Human-friendly custom syntax (`en.txt`).
2. **`.php`:** Native PHP arrays (`en.php`) — most performant and flexible.
3. **`.json`:** Standard JSON (`en.json`).

Set the default page field format in `site/pages/config.php`. Change a page's effective format in dev mode so its language files are converted before production.

### The `.txt` Format

Fields are separated by a line of dashes (`-------`). Arrays use standard JSON syntax. Scalar values are strings in `.txt` (`true` means the string `'true'`); use PHP or JSON to store typed scalars such as booleans.

```text
title: Welcome to the Homepage
-------
desc: This is a longer value.
It can span across multiple lines.
-------
cta_button: {"label": "Learn More", "url": "/about"}
-------
```

### Accessing Fields

- **Page template:** `$page->fieldName`
- **Component template:** `$component->field_name`
- **Content item:** `$content('content-type')->{'content-item'}->fieldName`

If there is no exact match, underscores fall back to hyphens: `$pages->about_us` resolves to the `about-us` page ID, and `$component->field_name` reads the `field-name` field.

### Field Modifiers

Page, site, and content-item field properties return `Field` objects that support modifiers. Component field properties return raw values.

```php
$page->title->uppercase()
$page->title->excerpt(100)
$page->publish_date->toDate('d.m.Y')
$page->description->or('No description available')
$page->tags->length()
```

See [Field Methods](#field-methods) in the API Reference for the full list.

### Fields and framework methods

On `Page`, `Site`, `ContentItem`, and `Component`, properties read user fields. Use methods for metadata and containers. For example, `$page->url` is a field, `$page->url()` is its current URL, and `$page->fields()['url']` reads the raw value.

---

## Templates

Templates share a common layout (`template.php`) and field blueprint (`fields.txt` or another format) across multiple pages. They can be defined locally (folder-based) or globally (config-based).

### Local Templates (Folder-Based)

Create a `template/` folder inside any page directory:
- All sibling pages in that directory inherit the template.
- Its `template.php` overrides siblings' `template.php`.
- Its `fields.txt` is synced to siblings' language files.

### Global Templates (Config-Based)

Create a named folder inside `site/templates/` (e.g., `site/templates/blog-layout/`) containing `template.php` and `fields.txt`, then reference it in a page's `config.php`:

```php
'template' => 'blog-layout',
```

### Template Config Options

| Value | Behavior |
|---|---|
| `'inherit'` | **(Default)** Uses the local `template/` folder if present in the parent directory. |
| `'unset'` | Disables templating; the page uses its own `template.php` and fields. |
| `'name'` | Uses the global template in `site/templates/name/`. |

---

## Components

Components are reusable UI blocks rendered via `$components`. You can create a component folder manually under `site/components/` or scaffold it automatically in dev mode by calling `$components->new_component` in a template. Components can be nested inside one another.

The component's `config.php` uses `content_format` (`txt`, `php`, or `json`) and `render_assets` (`external` or `inline`). Keep component CSS and JS alongside `template.php`; Folio includes those assets when it renders the component.

Inside a component's template, access fields and overrides through the `$component` object:

```php
<?= $component->field_name ?>
```

### Content Hierarchy

Component data is merged in the following order (later sources overwrite earlier ones):

#### 1. Global Default Content
By default, a component loads content from its own folder (e.g., `site/components/card/en.txt`, `de.txt`):

```php
<?= $components->card ?>
```

#### 2. Page-Specific Content
Pass an ID string to create a page-specific instance. Folio loads (or auto-creates in dev mode) a folder inside the current page's directory (e.g., `site/pages/home/_service-1/en.txt`):

```php
<?= $components->card('service-1') ?>
```

#### 3. Global Runtime Overrides
Inject data for all languages directly from the template, even for keys not defined in the component's field files (via array shorthand or `->all()`):

```php
<?= $components->button(['text' => 'Click Me!']) ?>
<?= $components->button->all(['text' => 'Click Me!']) ?>
```

#### 4. Language-Specific Overrides
Override values for specific languages:

```php
<?= $components->card('service-1')
    ->en(['title' => 'English Title'])
    ->de(['title' => 'Deutscher Titel'])
?>
```

### Slots

Slots pass rendered content separately from `$component` fields. Use named arguments or an associative array with `->slots()`, or set one value with `->slot($name, $value)`. These methods return a new component and can be chained with field overrides. Values can be strings, `Stringable` objects (including images and components), or `null`.

```php
<?= $components->card('service-1')->slots(
    header: '<h2>Services</h2>',
    media: $image('hero')?->resize(width: 600),
)->slot('footer', $components->button(['text' => 'Read more'])) ?>

<?= $components->card->slots(['card-header' => '<h2>Services</h2>']) ?>
```

Use `->open()` and `$components->close()` to capture template output and render the component in place. Unmarked output goes to `default`. Use `$slots->name` or `$slots('name')` to switch slots, and `$slots->default` or `$slots()` to return to `default`:

```php
<?php $components->card('service-1')->open() ?>
  <?php $slots->header ?>
    <h2>Services</h2>
  <?php $slots() ?>
    <p>Explore our services.</p>
  <?php $slots('footer') ?>
    <?= $components->button(['text' => 'Read more']) ?>
<?php $components->close() ?>
```

Alternatively, open and close a named slot explicitly. The matching `->close()` returns capture to `default`. Close the explicit slot before switching slots or closing its component. Component blocks can be nested inside any slot.

```php
<?php $components->card->open() ?>
  <?php $slots->header->open() ?>
    <h2>Services</h2>
  <?php $slots->header->close() ?>
  <p>Default body content.</p>
<?php $components->close() ?>
```

Captured slots override matching inline slots. Revisiting a slot appends to its captured content. A selected but empty slot clears its inline value; implicit whitespace in `default` does not replace an inline default.

In the component template, read slots with `$slot->name` or `$slot('name')`. Both return `null` for missing or whitespace-only content. Nonempty values are returned as rendered, without escaping. Property access prefers the exact name and otherwise falls back from underscores to hyphens, so `$slots->card_header` and `$slot->card_header` can target `card-header`. Use the callable form to specify an exact name.

```php
<article class="card">
  <?php if ($slot->header): ?>
    <header><?= $slot->header ?></header>
  <?php endif; ?>
  <div class="card-body"><?= $slot->default ?? $component->text ?></div>
  <?php if ($slot('footer')): ?>
    <footer><?= $slot('footer') ?></footer>
  <?php endif; ?>
</article>
```

---

## Assets

Global assets live inside `site/assets/` under `css/`, `js/`, and `images/`.

### CSS Files

Load files from `site/assets/css/` using `$css()`. The optional second argument overrides the configured render mode (`'external'` or `'inline'`).

```php
<?= $css('my-styles') ?>
<?= $css('my-styles', 'external') ?>
```

### JS Files

Load files from `site/assets/js/` using `$js()`, with an optional render mode override (`'external'` or `'inline'`).

```php
<?= $js('my-scripts') ?>
<?= $js('my-scripts', 'inline') ?>
```

### Images

Images are processed and cached in `public/generated-images/` and served in the best server-supported format (**AVIF → WebP → JPEG**). If an equal or better format is already pre-generated, it is served without regenerating. Quality is controlled by `image_quality` in `site/project.php` or overridden via `resize()`.

File extensions and order prefixes (`01_`) are optional when referencing images—`$image('photo')` matches `01_photo.jpg`.

| Scope          | Source Path                   | Helper Syntax                                                                                                                               |
| -------------- | ----------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| Global         | `site/assets/images/`         | `$image('photo')`, `$images->photo`, `$gallery('projects')`, `$galleries->projects`                                                         |
| Page-scoped    | `site/pages/<page>/`          | `$page->image('cover')`, `$page->images()->cover`                                                                                           |
| Content-scoped | `site/content/<type>/<item>/` | `$content('team')->victor->image('portrait')`, `$content->team->victor->images()->portrait`, `$content('team')->victor->gallery('gallery')` |

Page and content-item `image()` and `images()` read direct files only. A page’s `images/` folder is an ordinary child page, not a media folder. Use global galleries for page image sets. Every immediate subfolder of a content item or inside `site/assets/images/` is a gallery, including folders named `images` or `gallery`.

#### Rendering Images

```php
// Global image (<img src="/generated-images/..." alt="" width="..." height="...">)
<?= $image('photo') ?>
<?= $image('photo.jpg') ?>
<?= $images->photo ?>

// Retrieve only the URL
$url = $image('photo')->url;

// Page-scoped and cross-page images
<?= $page->image('cover') ?>
<?= $page->images()->{'cover-photo'} ?>
<?= $page('home')->image('hero') ?>
<?= $pages->about->images()->victor ?>

// Content-scoped image
<?= $content('team')->victor->image('portrait')->resize(width: 400) ?>
<?= $content->team->victor->images()->portrait->resize(width: 400) ?>
```

#### Galleries

Galleries can be rendered directly, iterated over, or queried for a single image:

```php
// Render or iterate
<?= $gallery('projects') ?>
<?php foreach ($gallery('projects') as $img): ?>
  <?= $img ?>
<?php endforeach; ?>

// Chainable, non-destructive modifiers
<?= $gallery('projects')->ordered()->reverse()->limit(6) ?>

// Pick a single image (both forms are equivalent)
<?= $gallery('projects')->image('photo') ?>
<?= $image('projects/photo')->resize(width: 400) ?>

// Global and content-item galleries
<?= $galleries->projects->photo ?>
<?= $content->team->victor->galleries()->images->portrait->resize(width: 400) ?>
<?= $content->team->victor->galleries()->gallery->detail_1 ?> // gallery/detail-1.*
```

See [Gallery Methods](#gallery-methods) for all modifiers (`shuffle()`, `reverse()`, `ordered()`, `limit()`, `count()`).

#### Resizing

`resize()` is available on both images and galleries. All parameters are optional, and processed outputs are cached per dimension/quality combination.

```php
<?= $image('photo')->resize(width: 800) ?>        // Auto-calculates height
<?= $image('photo')->resize(height: 600) ?>       // Auto-calculates width
<?= $image('photo')->resize(800, 600) ?>          // Exact dimensions (ignores aspect ratio)
<?= $image('photo')->resize(quality: 60) ?>       // Re-encode only
<?= $gallery('projects')->resize(width: 400) ?>   // Applies to all images in gallery
```

#### Alt Text

In dev mode, `site/assets/images/` and each immediate gallery subfolder automatically get a `config.php` and a language file per supported language. Edit alt text in those files; production only reads them.

- **`data_format`:** Set `txt` (default), `php`, or `json` in the folder's `config.php` while in dev mode to convert translations and back up the previous format.
- **Shared keys & inactive entries:** Format variants (`cover.jpg`, `cover.webp`, `01_cover.avif`) share one `cover` entry per folder and language. SVGs render normally but do not create or keep entries active. Removing the last non-SVG variant renames its key to `# cover` (reserved for inactive keys across all three formats); re-adding a non-SVG variant restores the text.

```php
// Read plain text for the current language
$alt = $image('cover-photo')->alt;
$alt = $images->cover_photo->alt;
$alt = $galleries->summer_trip->cover_photo->alt;

// Override for a single render
<?= $image('photo')->alt('A mountain at sunset') ?>
<?= $image('photo')->alt(en: 'A mountain at sunset', de: 'Ein Berg bei Sonnenuntergang') ?>
<?= $gallery('projects')->image('photo')->alt(en: 'Bridge', de: 'Brücke') ?>
```

Rendered HTML escapes alt text automatically. An empty global/gallery entry renders `alt=""`. Named language overrides fall back to the first supplied value if the current language is missing. Page- and content-scoped images do not generate alt files; without an inline override, their `alt` attribute is omitted.

---

## Querying Pages & Content

### Template Variables

Every page template provides:

- `$page` — The currently rendered page.
- `$link($id, $lang = null)` — Generate a [localized URL](#generating-localized-urls) by page ID.
- `$site` — The site-wide root object.
- `$pages` — All pages (including nested pages and the error page). Use `$pages->contact` to look up a root folder and `$pages->count()` to count them.
- `$content` — Helper for accessing [Content](#content) collections.

### Lookup by folder ID

```php
$pages->contact === $page('contact') && $page('contact') === $pages->page('contact');
$pages->blog->children()->post_1 === $pages->page('blog/post-1');
$content->team->victor === $content('team/victor');
```

`$pages` properties look only at top-level pages. Each `->children()` narrows lookup to one level, so `$pages->post_1` does not find `blog/post-1`. Collection properties try the exact segment before replacing underscores with hyphens. Properties on a page or item still read user fields, and `first()->title` reads the first item's title.

Siblings cannot have the same normalized folder name, even if their order prefixes differ. When copying a page, also update its localized slugs if they would collide with a sibling's URL.

### Getting Pages

```php
// Direct children of the current page
$page->children()

// Top-level pages
$site->children()
$site->children()->ordered()

// Lookup by ID ($page() tries the full ID first, then relative to the current page)
$site->page('blog')->children()
$page('blog')          // Absolute ID
$page('post1')         // Resolves to 'blog/post1' when on the blog page
$page('blog/post1')    // Absolute path
```

### Filtering, Sorting & Reading Results

All collection methods are chainable and work on both page and content collections:

```php
// Limiting and pagination
$page->children()->limit(6)
$page->children(6)                      // Shorthand for limit(6)
$page->children()->offset(1)->limit(5)

// Ordering
$page->children()->reverse()            // Reverses by publish_date or order
$site->children()->ordered()->reverse() // Ordered pages only, reversed
$page->children()->shuffle()

// Filtering by field values (scalar matches are strict)
$page->children()->status('published')
$page->children()->category('photography')
$page->children()->category(['web', 'design']) // Must match all listed values
$page('portfolio')->children()->featured('true')->shuffle()->limit(5) // TXT flag

// Compare raw values in callbacks, not Field objects
$page->children()->filter(fn($p) => ($p->fields()['publish_date'] ?? '') >= '2024-01-01')
$page->children()->filter(fn($p) =>
    ($p->fields()['featured'] ?? false) === true && ($p->fields()['archived'] ?? false) === false
) // PHP/JSON boolean flags

// Combining queries
$page('blog')->children()->category('tech')->reverse()->limit(3)

// Iterating and inspecting results
foreach ($page->children() as $child) {
    echo $child->title;
}
$latest = $page->children()->first();
$oldest = $page->children()->last();
$total  = $page->children()->count();
```

---

## Multilingual Support

### Language Files & Slugs

Pages, site fields, content items, and components use per-language files (`en.txt`, `de.txt`, etc.). A missing, null, or empty-string field falls back to the default-language value. Prefix a key in the default-language file with `_` (e.g., `_price`) to expose the same `price` value in every language.

Pages define localized URL slugs in their `config.php`:

```php
// site/pages/about/config.php
return [
    'en_slug' => 'about-us',
    'de_slug' => 'ueber-uns',
];
```

URLs are prefixed with the language code for every language except `default_lang`:
- English (default): `/about-us`
- German: `/de/ueber-uns`

Noncanonical paths for known pages redirect to their canonical URL without forwarding the query string. Unknown or malformed paths return 404 without redirecting.

### Generating Localized URLs

Use `$link($id)` to get the URL for a page ID in the current language. Pass a language code to override it. An unknown ID returns `'#'`. Changing `<lang>_slug` changes the URL, not the ID.

```php
// Specific page in the current language
<a href="<?= $link('contact') ?>">Contact</a>

// Current page in a specific language
<a href="<?= $page->url('de') ?>">Deutsch</a>

// Specific page in a specific language
<a href="<?= $link('contact', 'de') ?>">Deutsch</a>
```

### Language Switcher

Use `$page->lang()` (or `$site->lang()`) for the active language and `$site->langs()` for all configured languages:

```php
<?php foreach ($site->langs() as $lang): ?>
  <a href="<?= $page->url($lang) ?>" <?= $page->lang() === $lang ? 'class="active"' : '' ?>>
    <?= strtoupper($lang) ?>
  </a>
<?php endforeach; ?>
```

For multilingual overrides on reusable UI and media, see [Components](#4-language-specific-overrides) (`->en()`, `->de()`) and [Image Alt Text](#alt-text) (`->alt(en: ..., de: ...)`).

---

## Configuration

Folio uses three primary configuration levels:

- **[Project config](#project-config)** (`site/project.php`) — Runtime and system settings.
- **[Site config](#site-config)** (`site/pages/config.php`) — Site-wide page defaults.
- **[Page config](#page-config)** (`site/pages/<page>/config.php`) — Per-page overrides.

### Folder Roots

Folio defaults to `site/` and `storage/` next to `public/`. To rename either, pass an optional `roots` array in `public/index.php`:

```php
<?php
$base = dirname(__DIR__);
(require $base . '/framework/bootstrap.php')($base, [
  'roots' => [
    'site' => $base . '/project',
    'storage' => $base . '/var',
  ],
]);
```

Omit the array or either key to use its default. Paths must be absolute and point to distinct directories directly inside the project, with names starting with a lowercase letter or digit and containing only lowercase letters, digits, hyphens, or underscores. They cannot be symlinks or overlap the framework or `public/`. The document root and page URLs do not change.

For CLI commands to share these roots, the `folio` shim must call `require __DIR__ . '/public/index.php';`. If migrating from `roots.php`, move its overrides here and remove the old file; Folio no longer reads it. If you rename `site/`, update any deployment allowlist and ignore rules, and keep runtime storage out of the upload.

### Project Config

`site/project.php` controls runtime and system-level behavior.

| Setting | Description |
|---|---|
| `supported_langs` | Supported language codes (e.g., `['en', 'de']`). |
| `default_lang` | Primary language; add new content here first. |
| `dev_mode` | Enables scaffolding and developer features; disabling improves performance. |
| `performance_monitor` | Enables request timings in the server log and `Server-Timing` HTTP header. |
| `caching` | Global HTML caching switch for production. Caches only pages with caching enabled. |
| `minify_code` | Enables/disables HTML, CSS, and JS minification. |
| `backup` | Enables/disables automatic backups in `storage/backups/`. |
| `backup_interval` | Days between automatic backups. |
| `image_quality` | Preset (`low`, `medium`, `high`), dynamically mapped per image format. |

With minification off, Folio preserves template and component output as written. It adds line breaks only to separate the document tags it generates around that output.

On a PHP host, set the document root to `public/`, serve existing public files directly, and route other requests to `public/index.php`. Scaffold and deploy the required source files before turning `dev_mode` off; production does not create missing required configs.

Even with dev mode off, the PHP process needs write access to `public/generated-assets/`, `public/generated-images/`, and the relevant `storage/` paths when generating assets, writing caches, or creating backups.

#### Performance Monitor

With `'performance_monitor' => true` in `site/project.php`, open a document request in **DevTools → Network → Timing**. The public `Server-Timing` header reports milliseconds for `app` and each phase that ran (a framework HTML cache hit omits `render`). Detailed timings are written to the server log.

### Site Config

`site/pages/config.php` defines site-wide defaults for `data_format` and `render_assets`. It also controls `show_header` and `show_footer` globally; pages can opt out but cannot enable either when it is off globally.

### Page Config

Each page has its own `config.php`; available keys depend on its [page type](#custom-page-types). Purge cached HTML after changing rendering settings.

Legacy `id` keys are ignored. Update queries that used custom IDs to use normalized folder paths instead.

| Setting | Description |
|---|---|
| `type` | Page subclass (default: `'page'`). |
| `template` | `'inherit'` (default), `'unset'`, or global template name. |
| `data_format` | `'inherit'` (default: template format, then site default), `'txt'`, `'php'`, or `'json'`. |
| `render_assets` | `'inherit'` (default), `'external'`, or `'inline'`. |
| `caching` | `true` (default) allows HTML caching only if project caching is enabled; `false` opts out. Dev mode always bypasses caching. Cached error responses share one HTML body per language and return HTTP 404; set `false` if errors vary by URL. |
| `show_header` | `true` (default) shows the header only if enabled in the site config; `false` hides it. |
| `show_footer` | `true` (default) shows the footer only if enabled in the site config; `false` hides it. |
| `<lang>_slug` | Per-language URL slug for each supported language (e.g., `en_slug`, `de_slug`). |

---

## Command Line

Run `php folio` or `php folio --help` from the project root to list available commands.

| Command | Result |
|---|---|
| `php folio dev` | Show the current dev mode status. |
| `php folio dev on` / `off` | Set `dev_mode` in `site/project.php` (no-op if unchanged). |
| `php folio performance-monitor` | Show status; append `on` or `off` to set `performance_monitor`. |
| `php folio create page services/design` | Create `site/pages/services/design/` (and missing parents). Use actual folder names for existing parents (e.g., `02_work/new-project`). |
| `php folio create component hero-card` | Create `site/components/hero-card/`. |
| `php folio create gallery summer-trip` | Create `site/assets/images/summer-trip/`. |
| `php folio create content-type projects` | Create `site/content/projects/`. |
| `php folio create template blog-layout` | Create `site/templates/blog-layout/`. |
| `php folio create page-type Article` | Write `site/extend/Page/Article.php` immediately (use with `'type' => 'article'`). |
| `php folio build` | Export the [static site](#static-site-generator) for all languages into `storage/dist/`. |
| `php folio clear cache` | Delete cached HTML and image metadata while keeping generated CSS, JS, and images. |
| `php folio backup` | Create a ZIP archive in `storage/backups/` immediately (requires PHP's `zip` extension). |

**Notes:**
- Folder-creation commands require `dev_mode` and create empty directories; the next dev request scaffolds their files. Names must use lowercase letters and digits with `-` or `_` separators. Page creation rejects duplicate sibling names even when order prefixes differ. `create page-type` does not require dev mode.
- Generated CSS and JS versions are kept so cached pages can still load them. After a deployment or content change, run `php folio clear cache` to refresh cached HTML without deleting assets. Older versions accumulate until deliberately cleaned up.
- Backups include the site root, `public/`, and the required `folio` and `folio.ref` files. They omit the framework, runtime storage, `.git`, public generated assets and images, and symlinks.
- Cache, backups, and static builds follow the [configured storage root](#folder-roots); the paths shown here use the default.

---

## Static Site Generator

### 1. Build

A build does not scaffold source files. Visit new pages and render new components in dev mode first, then run from the project root:

```sh
php folio build
```

This renders all routable pages in every configured language into `storage/dist/`, including the home `index.html` and a default-language `404.html`. The output also contains generated CSS/JS/images and supported public assets (such as fonts and PDFs). PHP files, server configs, and symlinks are not copied.

Folio stages the build before replacing the previous output and refuses to overwrite an existing directory that it did not generate. Keep source edits outside `storage/dist/`.

### 2. Preview Locally

Serve `storage/dist/` as the web root:

```sh
python3 -m http.server 8001 --bind 127.0.0.1 --directory "$PWD/storage/dist"
```

Open [http://127.0.0.1:8001/](http://127.0.0.1:8001/). Do not open `index.html` directly or include `/dist` in the URL, as links and assets are root-relative.

### 3. Deploy

Publish the **contents of `storage/dist/`** at your domain or subdomain root (`index.html` at `/`). Use a host that supports directory indexes (e.g., `/work/floral/` → `work/floral/index.html`) and serves `404.html` with HTTP status 404.

- **Cloudflare Pages:** Upload `storage/dist/` via [Direct Upload](https://developers.cloudflare.com/pages/get-started/direct-upload/); root `404.html` is picked up automatically. Rebuild and redeploy after making changes.
- **Limitation:** PHP runs only at build time; pages requiring request-time PHP or dynamic endpoints will not work in static exports.

---

## API Reference

### Collection Methods

| Method | Description |
|---|---|
| `page($id)` | Get a page from the collection by folder ID. |
| `children()` | Get child pages in the current collection context. |
| `filter($callback)` | Filter items using a custom callback. |
| `exclude($ids)` | Return a new collection without the given IDs (case-insensitive), e.g., `$pages->exclude(['error'])`. |
| `limit($count)` | Limit results to `$count` items. |
| `offset($count)` | Skip the first `$count` items. |
| `ordered()` | Return only items with an order prefix defined. |
| `reverse()` | Reverse order (by `publish_date` or `order`). |
| `shuffle()` | Randomize order. |
| `count()` | Return total number of items. |
| `first()` | Return the first item. |
| `last()` | Return the last item. |
| `$fieldName($value)` | Filter by field value (e.g., `status('published')`, `category('tech')`). |

### Page Methods

| Method / Property | Description |
|---|---|
| `children()` | Get all direct child pages. |
| `$page($id)` | Get another page by ID (supports relative paths). |
| `url()` / `url($lang)` | Return the page URL as a string in the current or specified language. |
| `id()`, `order()`, `lang()` | Normalized folder ID, folder order, and current language. Content items also expose `id()`. |
| `isActive()`, `hasActiveChild()` | Whether this page or a descendant is active, respectively. |
| `image($name)`, `images()` | Look up images directly in the page folder. |
| `fields()` | Raw field array; `->$name` reads a user field. |
| `$site->langs()` | All configured languages. |

### Field Methods

| Method | Description |
|---|---|
| `uppercase()` | Convert text to uppercase. |
| `lowercase()` | Convert text to lowercase. |
| `excerpt($chars)` | Truncate text to `$chars` characters and append `…`. |
| `words($count)` | Truncate text to `$count` words and append `…`. |
| `or($fallback)` | Return `$fallback` if the field is empty. |
| `isEmpty()` | Return `true` if the field is empty. |
| `isNotEmpty()` | Return `true` if the field has a value. |
| `length()` | Return string length or array item count. |
| `toDate($format)` | Format a date field using a PHP date format string. |
| `year()` | Extract the year from a date field (e.g., `2025`). |
| `month()` | Extract the month name from a date field (e.g., `October`). |
| `day()` | Extract the day from a date field (e.g., `17`). |

### Gallery Methods

| Method | Description |
|---|---|
| `image($name)` | Pick a single image from the gallery. |
| `resize(...)` | Resize all images in the gallery (`width`, `height`, `quality`). |
| `ordered()` | Keep only images with an order prefix (`01_name.jpg`), sorted by it. |
| `reverse()` | Reverse image order. |
| `shuffle()` | Randomize image order. |
| `limit($count)` | Limit the number of images. |
| `count()` | Return the number of images. |
