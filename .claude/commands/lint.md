---
description: Run PHPCS (WordPress Coding Standards) on the plugin
---

Run the PHP linter with the WordPress ruleset.

If `composer.json` exists and exposes a `lint` script: use `composer run lint`.
Otherwise: `./vendor/bin/phpcs --standard=WordPress includes/ variolab-ab-testing.php`.

If warnings are surfaced, suggest `composer run lint:fix` (or `phpcbf`) for the auto-fixable ones, then list the remaining ones to fix by hand.

Don't bypass errors — they often indicate a real security risk (missing escape, forgotten capability).
