# WordPress security rules

Applies to: every `.php` file in the plugin handling user input, the DB, or HTML output.

## SQL — always prepare
```php
// ❌ NEVER
$wpdb->query( "SELECT * FROM $table WHERE id = $user_input" );

// ✅ ALWAYS
$wpdb->get_results(
  $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}abtest_events WHERE experiment_id = %d", $exp_id )
);
```
Placeholders: `%d` (int), `%f` (float), `%s` (string). For `IN (?)`: build the `%d,%d,%d` placeholder string dynamically.

## Output escaping (always at the last moment, at the output)
| Context | Function |
|---|---|
| HTML text | `esc_html()` / `esc_html__()` |
| HTML attribute | `esc_attr()` / `esc_attr__()` |
| URL | `esc_url()` |
| Allowed HTML block | `wp_kses_post()` |
| Inline JS JSON | `wp_json_encode()` |
| Textarea | `esc_textarea()` |

Admin example:
```php
<input type="text" name="abtest_goal" value="<?php echo esc_attr( $goal ); ?>" />
<p><?php echo esc_html__( 'Goal URL', 'variolab-ab-testing' ); ?></p>
```

## Input sanitization (at intake)
| Type | Function |
|---|---|
| Single-line text | `sanitize_text_field()` |
| Email | `sanitize_email()` |
| URL | `esc_url_raw()` (storage) or `sanitize_url()` |
| Slug / key | `sanitize_key()` |
| Positive int | `absint()` |
| Allowed HTML | `wp_kses_post()` |
| Array via `$_POST` | iterate + sanitize each field |

## Nonces + capabilities (every state-changing action)
```php
// Admin form:
wp_nonce_field( 'abtest_save_experiment', '_abtest_nonce' );

// Handler:
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( esc_html__( 'Forbidden', 'variolab-ab-testing' ), 403 );
}
check_admin_referer( 'abtest_save_experiment', '_abtest_nonce' );
```

REST API: use `permission_callback` (never `__return_true` except for intentional public endpoints like conversion tracking — which must then have rate-limiting + dedup).

## REST endpoints — checklist
- `permission_callback` defined (even if `__return_true`, be explicit)
- `args` schema with `validate_callback` + `sanitize_callback`
- Don't return raw DB objects without filtering sensitive fields
- Per-IP / per-visitor rate limiting on public endpoints (e.g. conversion)

## Standard capabilities
- `manage_options`: plugin admin (create / edit experiments)
- `edit_posts`: if we let editors view stats
- Custom cap `manage_abtests`: consider in v2 for a dedicated role

## Cookies
- Prefix `abtest_` (our namespace)
- `httponly` true by default, `secure` over HTTPS
- `samesite=Lax`
- Reasonable lifetime (30 days max for assignment)

## Asset loading
- `wp_enqueue_script` / `_style` ONLY — never raw `<script>`.
- Version with `filemtime()` or the plugin version for cache busting.
- `wp_localize_script` or `wp_add_inline_script` to pass PHP→JS data.
