# Plugin's WordPress conventions

Applies to: every `.php` file in the plugin.

## Prefixes (anti-collision in the global namespace)
- Global functions: `abtest_xxx()`
- Constants: `ABTEST_XXX`
- Options: `abtest_xxx`
- Meta keys: `_abtest_xxx` (underscore prefix = hidden from the custom-fields UI)
- Custom hooks: `abtest_xxx` (action) / `abtest_filter_xxx` (filter)
- CSS / JS handles: `abtest-xxx`
- Tables: `{$wpdb->prefix}abtest_xxx`
- Cookies: `abtest_xxx`
- Text domain: `variolab-ab-testing` (= plugin slug)

## PSR-4 namespace
Root namespace: `Abtest\` → mapped to `includes/`.
Sub-namespaces: `Abtest\Admin\`, `Abtest\Rest\`, `Abtest\Stats\`.
Class name = file name (e.g. `Abtest\Router` → `includes/Router.php`).
> Note: we drop the legacy `class-xxx.php` convention in favor of clean PSR-4.

## WordPress hooks
- Plugin actions: `add_action( 'init', [ $this, 'register' ] )`
- Always use an explicit priority when order is critical (default is 10)
- Prefer class methods to closures (testable + un-registerable)

## i18n
- Every user-facing string: `__( 'Text', 'uplift-ab-testing' )`
- Direct echo: `esc_html_e( 'Text', 'uplift-ab-testing' )`
- With variables: `sprintf( __( '%d conversions', 'uplift-ab-testing' ), $n )`
- Load: `load_plugin_textdomain( 'uplift-ab-testing', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' )` on the `init` hook
- **Source strings are always in English.** French (or any other language) translations live in `languages/*.po` if anyone provides them.

## Main file headers
```php
<?php
/**
 * Plugin Name: Variolab – A/B Testing
 * Description: Simple A/B testing, internal tracking, 50/50 cookie split.
 * Version: 0.1.0
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Author: Guillaume Ferrari
 * Text Domain: uplift-ab-testing
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;
```

## Defensive boot
Every PHP file starts with `defined( 'ABSPATH' ) || exit;` to prevent direct access.

## Activation / deactivation / uninstall
- `register_activation_hook( __FILE__, [ Abtest\Plugin::class, 'activate' ] )` — create tables, default options.
- `register_deactivation_hook( __FILE__, [ Abtest\Plugin::class, 'deactivate' ] )` — clean transients, keep the data.
- `uninstall.php` at the root — drop tables, delete options (only on uninstall).

## No side-effects at require
The main file registers hooks, does NOTHING at load time (avoids breaking other plugins' activation).
