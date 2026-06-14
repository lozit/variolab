<!-- generated-by: groundrules v1.5.0 (adopted) -->
# Learnings — Variolab – A/B Testing

Rules learned from corrections and non-trivial discoveries during the project. Reverse-chronological order (newest at the top). **Re-read at session start.**

One entry = one **actionable rule**, not a journal note. Each entry has a rule-stating title, a **Why** (the story + what it cost), and a **When to apply** (the concrete trigger). Minimal code snippet / command included when it is the fix.

---

## Diagnose front-end "it doesn't work" in a real browser FIRST; batch releases — don't tag a wp.org version per change

**Why**: A user reported a conversion goal "doesn't work / had to click twice" on an imported-HTML landing. I reasoned from the code and shipped a sequence of patch releases (v0.15.9 tracker-on-blank-canvas, v0.15.10 cookie-independence) before opening a real browser — where the actual cause was WP Rocket "Delay JavaScript Execution" rewriting the tracker's `<script type>` to `rocketlazyloadscript`, deferring it until the first interaction. ~10 tag+deploy cycles burned in an hour, and v0.15.10 fixed the wrong root cause.

**When to apply**: for any front-end "it doesn't work on my site" report — reproduce in a real browser before coding a fix (Playwright MCP: navigate the live URL, inspect `window.AbtestTracker`, the script `type`/attributes, the network call, click and watch). On blank-canvas pages the tracker is injected by `Tracker::blank_canvas_script_tags()`, but cache/optimisation plugins still process the HTML output buffer, so delay-JS can defer it — exclude via `rocket_delay_js_exclusions` / `perfmatters_delay_js_exclusions`. And always: commit locally and **batch**; never `git tag` + push a wp.org release per change. Tag/deploy only when the user asks, or once a coherent batch is verified. (2026-06-12)

## Behind any CDN/edge cache, always send `Cache-Control: no-store` headers — never rely on a cache plugin's filters alone

**Why**: Figuring out how A/B testing survives Kinsta (nginx server-cache + Cloudflare Enterprise edge). On a cache HIT, WordPress never runs → no impression logged, the 50/50 split frozen. Plugin filters (`rocket_*`, `litespeed_*`) only touch their own cache; an external CDN never sees them. The universal API is the HTTP header.

**When to apply**: any A/B-tested page on any host. Send `nocache_headers()` + `Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private` from the Router as early as `parse_request`, before any output. Belt-and-suspenders: the user adds test URLs to the host's cache-bypass list (MyKinsta → Tools → Cache, Cloudways Varnish exclusions, etc.) and purges on a new test. Kinsta detection: `KINSTA_CACHE_ZONE` / `KINSTAMU_VERSION` constants or `mu-plugins/kinsta-mu-plugins`; state in the `X-Kinsta-Cache` header (`HIT`/`MISS`/`BYPASS`). (2026-04-29)

## Always `wp_slash()` content from a non-`$_POST` source before `wp_insert_post` / `wp_update_post` / `update_post_meta` / `update_option`

**Why**: An imported `.html` containing a JS bundler crashed with `JSON.parse: unexpected non-whitespace character`. The stored content showed `<u002Fscript>` instead of `</script>`, `n<script>` instead of `\n` — every backslash had vanished. Cause: those functions call `wp_unslash()` on their inputs (they expect auto-slashed `$_POST` data). Content from `file_get_contents()` has no added slashes, so `wp_unslash()` strips the **real** backslashes.

**When to apply**: whenever the value does NOT come from `$_POST`/`$_GET`. Pre-slash it:
```php
wp_insert_post([
    'post_title'   => wp_slash( $title ),
    'post_content' => wp_slash( $html ),
]);
```
Invisible with plain text, devastating on JSON, regex, Babel/JSX, or any source code containing `\`. (2026-04-28)

## To serve non-public (`private`) content on a hijacked URL, rewriting `query_vars` isn't enough — combine `pre_get_posts` + `posts_results`

**Why**: A Router refactor rewrote `query_vars['page_id']` to a `private` variant, but WP still returned 404 — `WP_Query` filters non-public statuses on the front end when the visitor lacks `read_private_pages`.

**When to apply**: any time you hijack a URL to serve non-public content. In `Router::maybe_route()`: (1) `pre_get_posts` priority 1 on the main query → `$query->set('post_status', ['publish','private'])` + `$query->is_404 = false`; (2) `posts_results` as a safety net → if empty and we expected our variant, inject `$variant_post` and reset `is_singular`/`is_page`/`queried_object`. NEVER disable capability checks globally. (2026-04-28)

## On wp-env, a 404 on every slug URL is usually `mod_rewrite` not loaded — reload Apache before chasing a router bug

**Why**: `wp option update permalink_structure '/%postname%/'` + `wp rewrite flush --hard` weren't enough; every slug URL 404'd. `apache_mod_loaded('mod_rewrite')` returned `false` even after `a2enmod rewrite` said "already enabled". I hunted a router bug for a while — it was the environment.

**When to apply**: before debugging code when every URL 404s on wp-env, check `apache_mod_loaded('mod_rewrite')`. If false: `npx wp-env run wordpress service apache2 reload` (the module is enabled at the Apache level but not loaded in the PHP-Apache runtime until reload). (2026-04-28)

## When a menu/hook/class mysteriously vanishes, check `get_option('active_plugins')` — WP silently auto-disables a plugin that fatals on load

**Why**: After fixing a fatal, the admin menu didn't appear. I searched the code for 20 minutes. Real cause: WP's fatal-error protection (since 5.2) had auto-disabled the plugin during a previous fatal — and `wp plugin list` kept reporting it "active", lying. The user saw the plugin alternate between "active (fataling)" and "auto-disabled".

**When to apply**: when behavior changes weirdly (missing menu, hook not wired, classes not loaded), check `get_option('active_plugins')` FIRST — not `wp plugin list` (it can disagree). Fix the root cause, then `wp plugin activate <slug>`. (2026-04-28)

## Call `register_post_type` (and anything touching rewrite/query) on `init`, never earlier

**Why**: `Plugin::boot()` hooked on `plugins_loaded` called `Experiment::register()` → `register_post_type()` immediately → fatal on **every** admin page: `Call to a member function add_rewrite_tag() on null`. The `$wp_rewrite` global is built by WP on `init` priority 0; before that it's `null`. The bug didn't show via wp-cli or front-end curl — only real wp-admin navigation triggered it.

**When to apply**: any WP function touching routing/rewrite/query (`register_post_type`, `register_taxonomy`, `add_rewrite_rule`…) must run on `init` at the earliest:
```php
add_action( 'init', [ Experiment::class, 'register' ] );
```
`plugins_loaded` is for loading textdomain, instantiating classes, wiring hooks — no side effects on WP state. And: always test via wp-admin in a browser, not just front + CLI. (2026-04-28)

## Block themes don't fire `the_post` — for content swaps, mutate the globals directly

**Why**: The content swap used `add_action('the_post', ...)`. On Twenty Twenty-Four (default block theme since WP 6.4) the cookie was set to "B" but the render still showed A. Block themes don't run `while(have_posts()){ the_post(); }` — `WP_Block_Template` renders `core/post-content`/`core/post-title` reading directly from `global $post` and `$wp_query->queried_object`, so `the_post` never fires.

**When to apply**: for any filter/action on a singular page's content, test on a block theme too. Loop hooks (`the_post`, `loop_start`, `loop_end`) are obsolete for block renders — mutate the globals in place on `template_redirect` priority 1 (`$wp_query->post`, `$wp_query->posts[0]`, `$wp_query->queried_object`, `$post`), or use `the_content`/`render_block` filters which stay universal. See `Router::swap_to_variant()`. (2026-04-28)
