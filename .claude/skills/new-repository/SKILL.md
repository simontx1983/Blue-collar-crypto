---
name: new-repository
description: Scaffold a new Repository class in the bcc-trust plugin following the §1–§5 conventions (repository-only $wpdb access, explicit COLUMNS const, bounded queries, generation-counter cache invalidation). Use when adding persistence for a new domain entity in app/Domain/{Core,Disputes,Onchain}/Repositories/.
---

# /new-repository

Scaffolds a Repository class that complies with the [bcc-trust architecture rules](../../../app/public/wp-content/plugins/bcc-trust/CLAUDE.md). Run `/duplicate-scan` first — most "new" repositories are extensions of existing ones.

## Inputs to gather from the user

- **Domain context** — Core, Disputes, or Onchain (determines namespace and directory).
- **Entity name** — e.g. `Endorsement`, `WalletLink`. PascalCase singular.
- **Table name** — the unprefixed table identifier (e.g. `endorsements`, `wallet_links`). Must already exist in `TableRegistry`.
- **Columns** — explicit list. No `SELECT *` is allowed downstream, so the COLUMNS const is mandatory.

## Steps

1. **Run `/duplicate-scan`** with the entity name as a keyword. If a similar repository exists, EXTEND it instead of creating a parallel one.

2. **Verify the table is registered** in `app/Infrastructure/Database/TableRegistry.php`. If not, add it there first — `$wpdb->prefix . 'bcc_…'` strings in the repository are a §1 violation.

3. **Scaffold the file** at `app/Domain/<Context>/Repositories/<Entity>Repository.php`:

```php
<?php
/**
 * <Entity> Repository
 *
 * Handles database operations for the <table> table.
 *
 * @package BCC\Trust\<Context>\Repositories
 */

namespace BCC\Trust\<Context>\Repositories;

use BCC\Trust\Infrastructure\Database\TableRegistry;

if (!defined('ABSPATH')) {
    exit;
}

class <Entity>Repository
{
    private const COLUMNS = '<col1>, <col2>, <col3>'; // §2 — never SELECT *

    private string $table;

    public function __construct()
    {
        $this->table = TableRegistry::<entity>();
    }

    /**
     * Read by primary key. Bounded by unique-key filter (§4).
     */
    public function findById(int $id): ?array
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT " . self::COLUMNS . " FROM {$this->table} WHERE id = %d LIMIT 1",
            $id
        ), ARRAY_A);

        return $row ?: null;
    }

    /**
     * Insert. Write paths bump the generation counter (§5) so cached
     * reads invalidate on next access.
     */
    public function create(/* typed params */): int
    {
        global $wpdb;

        $wpdb->insert($this->table, [
            // ...field => value
        ], [
            // ...format strings (%d, %s, %f)
        ]);

        $id = (int) $wpdb->insert_id;

        wp_cache_incr('<entity>_generation', 1, 'bcc_trust');

        return $id;
    }
}
```

4. **Replace placeholders** (`<Entity>`, `<Context>`, `<col1>`, etc.) with the actual values.

## Hard rules to enforce while scaffolding

- **§1**: ALL `$wpdb` calls live inside this class. Services and controllers must call this repository, never `$wpdb` directly.
- **§2**: Every `SELECT` references `self::COLUMNS`. Never `SELECT *`.
- **§4**: Every `SELECT` is bounded — `LIMIT`, unique-key filter, bounded `IN ()`, or aggregate. `SELECT ... FROM x` with no `WHERE` and no `LIMIT` is a violation.
- **§5**: Write methods (`create`, `update`, `delete`) bump a generation counter via `wp_cache_incr()`. Read methods key any caching by that generation.
- **§7**: Cross-plugin callers use positional arguments. Don't define methods that only make sense with named parameters (e.g. lots of optional flags).
- **PHPStan level 8**: Type every parameter and return. No `mixed` unless truly polymorphic. No `@var` overrides.

## After scaffolding

1. Update `composer.json` autoload only if a new namespace prefix was added (rare).
2. Run `composer dump-autoload -o`.
3. Run `php -l <file>` (the post-edit hook does this automatically).
4. Invoke the `arch-guardrails-reviewer` subagent before declaring done.
