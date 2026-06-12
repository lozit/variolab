# Lessons learned

Patterns learned from user corrections or bugs hit. Re-read at the start of each session.

## Format
```
## YYYY-MM-DD — [Category] Short title
**Context**: what I was doing
**Mistake**: what I did wrong
**Fix**: what works
**Rule for me**: pattern to apply going forward
```

---

## 2026-04-29 — [Hosting / Cache] Kinsta = double cache (nginx + Cloudflare edge), bypass via `Cache-Control` + Cache Bypass UI

**Context**: Figure out how A/B testing can survive a Kinsta environment (which combines an nginx cache at server level and a Cloudflare Enterprise cache at edge level).

**Kinsta behavior**:
- The Kinsta nginx cache respects HTTP `Cache-Control: no-store, no-cache, private, max-age=0` headers.
- The Cloudflare edge does too (Cache-Control is propagated from the origin).
- The `X-Kinsta-Cache` response header reports the state: `HIT` (served from cache), `MISS` (generated, cached), `BYPASS` (never cached).
- Plugin-side detection: `KINSTA_CACHE_ZONE` or `KINSTAMU_VERSION` constant, or presence of `wp-content/mu-plugins/kinsta-mu-plugins`.
- The Cache Bypass UI in MyKinsta → Tools → Cache lets you exclude URL patterns via regex.

**A/B test strategy on Kinsta**:
1. Code-side: send `nocache_headers()` + `Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private` from the Router on every A/B test page (before any output, so as early as `parse_request`).
2. Belt-and-suspenders: the user adds the test URLs to the MyKinsta Cache Bypass list.
3. When launching a new test, purge the Kinsta cache (manually via the MyKinsta UI, or via their REST API if we automate).

**Rule for me**: For any site behind a CDN/edge cache, **never rely on the filters of a local cache plugin only** — always also send `Cache-Control: no-store` headers in the response, that's the universal API. Plugin filters (rocket, litespeed) only work for their own cache; external CDNs don't see them.

---

## 2026-04-28 — [WordPress / Slashing] `wp_insert_post` / `wp_update_post` eat one level of backslashes via `wp_unslash()`

**Context**: HTML import feature into a WP page. The user uploaded a `.html` file containing a JS bundler that does `JSON.parse()` on an inline payload with escaped sequences (`/`, `\n`, `\t`, `\"`).

**Mistake**: The bundler crashed with "JSON.parse: unexpected non-whitespace character at line 2 column 30". Inspection of the stored content: `<u002Fscript>` instead of `</script>`, `n<script>` instead of `\n<script>`. Every backslash from the original file had vanished from the DB.

**Cause**: `wp_insert_post()` and `wp_update_post()` call **`wp_unslash()`** on their inputs (because they expect data coming from `$_POST`, which is auto-slashed by WP via magic_quotes-like behavior). Our content comes from `file_get_contents()` (no slashes added), so `wp_unslash()` strips the **real** backslashes out of the content.

**Fix**: Pre-slash with `wp_slash()` every scalar value passed to `wp_insert_post` / `wp_update_post` when the source isn't `$_POST`:
```php
wp_insert_post([
    'post_title'   => wp_slash( $title ),
    'post_content' => wp_slash( $html ),
])
```

**Rule for me**: **ALWAYS `wp_slash()`** before `wp_insert_post`, `wp_update_post`, `update_post_meta`, `update_option` when the value does NOT come from `$_POST` / `$_GET` (which are already slashed by WP). Source: [Codex](https://developer.wordpress.org/reference/functions/wp_insert_post/) — "All inputs will be passed through `wp_unslash()`". Invisible bug with normal text, devastating on JSON, regex, Babel/JSX, or any source code containing `\`.

---

## 2026-04-28 — [WordPress / Private posts] Serving a `private` page to a logged-out visitor

**Context**: Router refactor to intercept a custom URL and resolve it to a `private` page (hidden from the public).

**Mistake**: `parse_request` rewrote `query_vars['page_id']` to the private variant, but WP still returned 404 — `WP_Query` filters out non-public statuses when `current_user_can` doesn't cover `read_private_pages`.

**Fix**: Combine two hooks in `Router::maybe_route()`:
1. `pre_get_posts` priority 1 on the main query → `$query->set('post_status', ['publish', 'private'])` + `$query->is_404 = false`
2. `posts_results` as a safety net → if returned empty AND we expected our variant, inject `$variant_post` into the results array and reset `is_singular` / `is_page` / `queried_object`.

**Rule for me**: When hijacking a URL to serve non-public content, rewriting `query_vars` isn't enough. WP filters statuses on the front-end. Standard fix: `pre_get_posts` to allow the status + `posts_results` as a safety net if the query comes back empty. NEVER disable capability checks globally (security risk).

---

## 2026-04-28 — [WordPress / wp-env] Pretty permalinks need an Apache reload after activation

**Context**: `wp option update permalink_structure '/%postname%/'` + `wp rewrite flush --hard` were not enough. Every slug-based URL returned 404.

**Mistake**: I looked for a router bug while it was the environment. `apache_mod_loaded('mod_rewrite')` returned `false` even after `a2enmod rewrite` (which answered "already enabled").

**Fix**: `npx wp-env run wordpress service apache2 reload` — the module is listed enabled at the Apache level, but isn't loaded in the PHP-Apache runtime until you reload.

**Rule for me**: Before hunting a code bug when every URL returns 404 on wp-env, check `apache_mod_loaded('mod_rewrite')`. If false, `wp-env run wordpress service apache2 reload`. Ideally automated via a `.wp-env.json` mapping that drops a custom `.conf` — but a manual reload is the fastest dev fix.

---

## 2026-04-28 — [WordPress / Auto-recovery] WP silently disables a plugin that fatals during load

**Context**: After fixing a fatal (broken CPT capabilities), I tested via curl + browser. The menu didn't appear. I looked through the code — everything looked OK.

**Mistake**: I searched the code for 20 minutes. Real cause: `get_option('active_plugins')` returned `[]` — the plugin had been **auto-disabled by WordPress** during a previous fatal. `wp plugin list` kept reporting it as "active" (inconsistency between the option and the API), misleading. The user on their side saw "Settings reappears when I disable the plugin" → that was the symptom: their plugin alternated between "active (and fataling)" and "auto-disabled".

**Fix**: `wp plugin activate AB-testing-wordpress` after fixing the root cause.

**Rule for me**: When weird behavior changes appear (missing menu, hook not wired, classes not loaded), **always check `get_option('active_plugins')` first** — not `wp plugin list`. WP has an auto-recovery mechanism that silently disables plugins throwing a fatal during admin load (since WP 5.2 — fatal-error protection / recovery mode). Classic trap: you fix the bug, you test, it doesn't work, you look for another bug — when you just need to re-activate the plugin.

Also: `wp plugin list` can lie. Source of truth = the `active_plugins` option.

---

## 2026-04-28 — [WordPress / Hook timing] `register_post_type` must be called on `init`, never earlier

**Context**: `Plugin::boot()` was hooked on `plugins_loaded` and called `Experiment::register()` directly, which ran `register_post_type('ab_experiment', ...)` immediately.

**Mistake**: Fatal on **every** admin page (login included):
```
Call to a member function add_rewrite_tag() on null in wp-includes/rewrite.php:176
register_post_type → WP_Post_Type->add_rewrite_rules → add_rewrite_tag → $wp_rewrite->add_rewrite_tag
```
The `$wp_rewrite` global is instantiated by WP on `init` priority 0. Before `init`, it's `null`, so any `register_post_type` that touches rewrite rules (default case) crashes.

**Fix**: `Plugin::register_components()` no longer makes the direct call; it hooks on `init`:
```php
add_action( 'init', [ Experiment::class, 'register' ] );
```

**Rule for me**: Any WP function that touches routing/rewrite/query (`register_post_type`, `register_taxonomy`, `add_rewrite_rule`, etc.) must be called **on `init`** at the earliest. `plugins_loaded` is too early — reserved for: loading the textdomain, instantiating classes, wiring hooks. No side-effects on WP state.

Bonus: this bug didn't appear via wp-cli (which boots differently) nor via my front-end curl tests (which don't trigger the same init sequence). First real admin navigation → fatal. **Always test via wp-admin in a browser**, not just front + CLI.

---

## 2026-04-28 — [WordPress / Block Themes] `the_post` action isn't called by block themes

**Context**: Implementing the content swap in `Router::maybe_route()`. I used `add_action('the_post', ...)` to intercept each post at the moment WordPress iterates over it in the loop, and apply the swap to the variant.

**Mistake**: e2e test on Twenty Twenty-Four (default block theme since WP 6.4) → cookie correctly set to "B", but the render still showed A's content. Invisible bug with a classic theme but blocking with any block theme.

**Fix**: Block themes don't run `while(have_posts()) { the_post(); }`. They use `WP_Block_Template` which renders blocks (`core/post-content`, `core/post-title`) reading directly from `global $post` and `$wp_query->queried_object`. The `the_post` hook never fires.

Solution: mutate the global objects in place on `template_redirect` priority 1:
- `$wp_query->post`
- `$wp_query->posts[0]`
- `$wp_query->queried_object`
- `$post` (global)

See `Router::swap_to_variant()` (commit fixing this bug).

**Rule for me**: For any filter/action on a singular page's content, **always test on a block theme**, not just a classic theme. Loop hooks (`the_post`, `loop_start`, `loop_end`) are obsolete for block-based renders — prefer direct mutation of the globals, or `the_content` / `render_block` filters which stay universal.

---

## 2026-06-12 — [Workflow] Diagnose before shipping; batch releases — don't tag a wp.org version per file change

**Context**: A user reported a conversion goal "doesn't work" / "had to click twice" on an imported-HTML landing. I shipped a sequence of patch releases (v0.15.9 tracker-on-blank-canvas, v0.15.10 cookie-independence) reasoning from the code, then finally opened a real browser and found the actual cause: WP Rocket "Delay JavaScript Execution" rewrites the tracker's `<script type>` to `rocketlazyloadscript`, deferring it until the first interaction (so the first click only wakes it). ~10 tag+deploy cycles in an hour.

**Mistake**: (1) Reasoned from code and shipped fixes before reproducing the symptom in a browser — v0.15.10 addressed the wrong root cause. (2) Tagged + deployed a new wp.org release after almost every file change, creating noise and burning version numbers.

**Fix**: For a front-end "it doesn't work" report, **reproduce in a real browser FIRST** (playwright MCP: navigate the live URL, inspect `window.AbtestTracker`, the script `type`/attributes, the network call, click and watch). On blank-canvas pages the tracker is injected by `Tracker::blank_canvas_script_tags()` (not `wp_enqueue_scripts`), but cache/optimisation plugins still process the HTML output buffer — so WP Rocket/Perfmatters/LiteSpeed delay-JS can defer it. Exclude via `rocket_delay_js_exclusions` / `perfmatters_delay_js_exclusions`.

**Rule for me**: Commit locally and **batch** changes; do NOT `git tag` + push a wp.org release per change. Tag/deploy only when the user asks, or once a coherent batch is verified. Diagnose (browser/repro) before coding a fix, especially for "doesn't work on my site" reports where the environment (cache/CDN/optimiser) is the likely culprit.
