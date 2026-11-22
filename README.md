# FolioPHP

Folio is a lightweight, file-based PHP framework for multilingual websites with reusable components and no external PHP dependencies. Use `php folio build` to export your site for [static hosting](#static-site-generator) without running PHP in production.

## Table of Contents

- [Introduction](#introduction)
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
- **Auto-scaffolding in dev mode:** Creating an empty folder for a page, content type, or component automatically generates its boilerplate files on the next request.
- **Resetting a project:** Delete the `site/` folder and reload the page to generate a fresh project structure. To clear all caches and generated files as well, delete `/cache`, `public/generated-assets`, and `public/generated-images`.

---

## Pages

Pages are represented by folders inside `site/pages/`. Creating a new page is as simple as creating a new folder.

### Key Features

- **Auto-generated files:** `config.php` and language files (e.g., `en.txt`) are automatically created inside new page folders on the next dev request.
- **Nesting:** Nest folders inside each other to build hierarchical URL and page structures.
- **Ordering:** Prefix folder names with numbers and an underscore (e.g., `01_about`) to control order; the prefix is ignored in IDs and URLs.
- **IDs:** A page ID is its lowercase path relative to `site/pages/`, without order prefixes. For example, `01_blog/02_post-1/` has the ID `blog/post-1`. Reordering does not change IDs; renaming or moving a folder does.
- **Ignored folders:** Prefix a folder with `_` (e.g., `_notes`) to prevent it from being parsed as a page.
- **Page-scoped files:**
  - Add any `.css` or `.js` file inside a page folder to include page-specific styles or scripts.
  - Add `header.php` or `footer.php` to override the site defaults for that page.
  - Add `template.php` to define a custom template for that page.
- **Active states:** `$page->isActive()` is `true` only on the currently rendered page, while `$page->hasActiveChild()` is `true` if any descendant is active:
  ```php
  <a href="<?= $page->url() ?>" <?= $page->isActive() || $page->hasActiveChild() ? 'aria-current="page"' : '' ?>>Home</a>
  ```

### Page Controllers

Add a `controller.php` file to any page folder to run logic during the `Page` object's construction. `$this` is the page; use `fields()` to update raw values.

```php
// site/pages/home/controller.php
<?php
$wordCount = str_word_count($this->fields()['text']);
$this->fields()['read_time'] = ceil($wordCount / 200);
```

### Custom Page Types

Place `Page` subclasses inside `extend/Page/` under the `Extend\Page` namespace to define custom blueprints, routing flags, or methods without modifying `/app`. Activate a type by setting `'type'` in a page's `config.php`. You can also use the CLI command `php folio create page-type Article` to scaffold this.

```php
// extend/Page/Article.php
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

Content item IDs include the type. For example, `site/content/01_team/02_victor/` has the ID `team/victor`. Item folders do not need a `config.php`; format is set at the content-type level.

---

## Fields

Fields store structured, language-synced content. Folio supports three storage formats:

1. **`.txt` (Default):** Human-friendly custom syntax (`en.txt`).
2. **`.php`:** Native PHP arrays (`en.php`) — most performant and flexible.
3. **`.json`:** Standard JSON (`en.json`).

Set the default page field format in `site/pages/config.php`. Change a page's effective format in dev mode so its language files are converted before production.

### The `.txt` Format

Fields are separated by a line of dashes (`-------`). Arrays use standard JSON syntax.

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

Fields return `Field` objects that support chainable modifiers:

```php
$page->title->uppercase()
$page->title->excerpt(100)
$page->publish_date->toDate('d.m.Y')
$page->description->or('No description available')
$page->tags->length()
```

See [Field Methods](#field-methods) in the API Reference for the full list.

### Fields and framework methods

On `Page`, `Site`, `ContentItem`, and `Component`, properties read user fields; no framework names are reserved. Use methods for metadata and containers. For example, `$page->url` is a field, `$page->url()` is its current URL, and `$page->fields()['url']` reads the raw value. Keep custom page-type state non-public to preserve this contract.

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

| Scope | Source Path | Helper Syntax |
|---|---|---|
| Global | `site/assets/images/` | `$image('photo')`, `$images->photo`, `$gallery('projects')`, `$galleries->projects` |
| Page-scoped | `site/pages/<page>/` | `$page->image('cover')`, `$page->images()->cover` |
| Content-scoped | `site/content/<type>/<item>/` | `$content('team')->victor->image('portrait')`, `$content->team->victor->images()->portrait`, `$content('team')->victor->gallery('gallery')` |

Page and content-item `image()` and `images()` read direct files only. A page’s `images/` folder is an ordinary child page, not a media folder. Use global galleries for page image sets. Every immediate subfolder of a content item is a gallery, including folders named `images` or `gallery`.

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
$alt = $images->{'cover-photo'}->alt;
$alt = $galleries->{'summer-trip'}->{'cover-photo'}->alt;

// Override for a single render
<?= $image('photo')->alt('A mountain at sunset') ?>
<?= $image('photo')->alt(en: 'A mountain at sunset', de: 'Ein Berg bei Sonnenuntergang') ?>
<?= $gallery('projects')->image('photo')->alt(en: 'Bridge', de: 'Brücke') ?>
<?= $image('photo')->alt('') ?> // Explicitly decorative
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

// Filtering by field values
$page->children()->status('published')
$page->children()->category('photography')
$page->children()->category(['web', 'design']) // Must match all listed values
$page('portfolio')->children()->featured('true')->shuffle()->limit(5)

// Custom callback filtering
$page->children()->filter(fn($p) => $p->publish_date > '2024-01-01')
$page->children()->filter(fn($p) => $p->featured && !$p->archived)

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

Pages, site fields, content items, and components use per-language content files (`en.txt`, `de.txt`, etc.). The same rules apply to TXT, PHP, and JSON files:

- **Shared fields:** Define `_title: My title` in the `default_lang` file and read it as `$page->title` in every language. The shared value takes precedence over a plain `title` in the corresponding files. Dev sync omits both keys from secondary files and removes any existing copies.
- **Translation fallback:** If a key is missing, is an empty string (e.g. `Title:`), or is `null`, Folio uses that key’s default-language value, even if the translation file is missing. Fallback is per field, not per array entry. `0`, `"0"`, `false`, and `[]` are kept.

Dev sync copies default-language values into new translation entries and leaves existing entries unchanged, even if blank. Clear a copied value to make it follow the default language at render time.

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

Optionally create `roots.php` in the website root (next to `folio`) to rename `site/` or `storage/` for both HTTP and CLI:

```php
<?php
return [
    'site' => 'project',
    'storage' => 'var',
];
```

Omitted keys keep their default names. Use distinct directory names starting with a lowercase letter or digit and containing only lowercase letters, digits, hyphens, or underscores. These directories cannot be symlinks or overlap the framework or `public/`. The document root and page URLs do not change.

Move existing files to the new directories yourself. If you use a deployment allowlist, include `roots.php` and the renamed site directory, keep runtime storage out of the upload, and update your ignore rules to match.

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
| `backup` | Enables/disables automatic backups in `backups/`. |
| `backup_interval` | Days between automatic backups. |
| `image_quality` | Preset (`low`, `medium`, `high`), dynamically mapped per image format. |

The PHP deployment workflow turns off `dev_mode` in the uploaded copy. Scaffold and commit required source files before deploying; missing required configs are not created in production.

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
| `php folio create page-type Article` | Create `extend/Page/Article.php` (use with `'type' => 'article'`). |
| `php folio build` | Export the [static site](#static-site-generator) for all languages into `dist/`. |
| `php folio clear cache` | Delete cached HTML and image metadata while keeping generated CSS, JS, and images. |
| `php folio backup` | Create a ZIP archive in `backups/` immediately (requires PHP's `zip` extension). |

**Notes:**
- `create` commands require `dev_mode` and create empty directories; the next dev request scaffolds their files. Names must use lowercase letters and digits with `-` or `_` separators. Page creation rejects duplicate sibling names even when order prefixes differ.
- Generated CSS and JS versions are kept so cached pages can still load them. After a deployment or content change, run `php folio clear cache` to refresh cached HTML without deleting assets. Older versions accumulate until deliberately cleaned up.
- Backups exclude `.git`, `backups`, `cache`, `generated-assets`, `generated-images`, the static build output (`dist/`), and symlinks.

---

## Static Site Generator

### 1. Build

Visit any newly created pages or components in dev mode first so their files are scaffolded, then run from the project root:

```sh
php folio build
```

This renders all routable pages in every configured language into `dist/`, along with `index.html`, a default-language `404.html`, generated CSS/JS/images, and supported public assets (such as fonts and PDFs). PHP files, server configs, and symlinks are not copied.

Builds always disable scaffolding and request caches. A successful build atomically replaces the previous Folio-generated `dist/`; a failed build leaves it intact. Keep all source edits outside `dist/`.

### 2. Preview Locally

Serve `dist/` as the web root (for example, with Python 3—not a build or deployment dependency):

```sh
python3 -m http.server 8001 --bind 127.0.0.1 --directory "$PWD/dist"
```

Open [http://127.0.0.1:8001/](http://127.0.0.1:8001/). Do not open `index.html` directly or include `/dist` in the URL, as links and assets are root-relative.

### 3. Deploy

Publish the **contents of `dist/`** at your domain or subdomain root (`index.html` at `/`). Use a host that supports directory indexes (e.g., `/work/floral/` → `work/floral/index.html`) and serves `404.html` with HTTP status 404.

- **Cloudflare Pages:** Upload `dist/` via [Direct Upload](https://developers.cloudflare.com/pages/get-started/direct-upload/); root `404.html` is picked up automatically. Rebuild and redeploy after making changes.
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