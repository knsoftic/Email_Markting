<?php

namespace App\Support;

/**
 * What a search term means.
 *
 * ── Why the wildcards are escaped ───────────────────────────────────────────
 * `%` and `_` are LIKE's own operators, so a term containing either stops being
 * the thing the person typed. Searching for `%` matched every row in every
 * table; searching for a two-character `__` matched almost as many. Neither is
 * dangerous — the queries are bound and limited — but both are wrong: somebody
 * looking for an address containing an underscore got a list with nothing to
 * do with it, and nothing on the screen could explain why.
 *
 * The backslash is escaped first, because escaping it after would double the
 * escapes we had just added.
 *
 * This lives in one place because five callers build the same pattern — the
 * four models' `scopeSearch` and the global search — and a term that means one
 * thing in the box at the top of the page and another on the contacts screen
 * would be worse than either behaviour on its own.
 */
class Search
{
    /**
     * A `LIKE` pattern matching this term anywhere, treating it as literal text.
     */
    public static function contains(?string $term): ?string
    {
        $term = trim((string) $term);

        if ($term === '') {
            return null;
        }

        return '%'.self::escape($term).'%';
    }

    /** Escapes LIKE's wildcards so the term matches itself. */
    public static function escape(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
    }
}
