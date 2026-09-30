# Network search

`GET /wp-json/h2/v1/search?search=...` keeps the default site-switcher scope,
phrase/all-term/fuzzy ranking, recency boost, result array, and `X-WP-Total`
headers. Search text is limited to 200 characters.

Clients can opt into these additions:

- `scope=network`: search all accessible, nonarchived H2 sites instead of the
  site switcher. `sites[]` still narrows the selected scope. Public sites and
  sites where the current user has the `read` capability are eligible.
- `match=all`: require every term without the fuzzy any-term clause. Phrase
  matches retain their ranking boost. The default is `match=any`.
- `include_coverage=true`: return an envelope instead of the result array. For
  example, a five-hit page whose hits were all discarded:

```json
{
  "results": [],
  "total": 25,
  "total_exact": true,
  "next_page": 2,
  "coverage": {
    "site_ids": [2, 94],
    "searched_site_ids": [2, 94],
    "missing_indices": [],
    "stale_hit_count": 5,
    "complete": false,
    "result_window_exhausted": false
  }
}
```

`site_ids` is the authorized scope after any `sites[]` restriction;
`searched_site_ids` lists sites with at least one requested type index present.
`missing_indices` identifies missing site/type pairs as `{ "site_id": 2,
"type": "comment" }`. Index aliases count as present. Index presence does not
prove that a backfill has finished or that all WordPress content is indexed.

`total` counts Elasticsearch hits in the searched indices before live WordPress
validation. `total_exact` distinguishes an exact indexed count from a lower
bound; neither is a count of live results or of content in missing indices.
`stale_hit_count` counts hits discarded while resolving this page.
`complete` means no requested index was missing and no hit on this page was
discarded. It does not mean all result pages have been read.

Follow `next_page` even when `results` is empty: stale hits can occupy an entire
page ahead of live results. `result_window_exhausted` means further indexed hits
exist (or may exist for a lower-bound total), but the next full page would exceed
the 10,000-hit window. Clients should narrow their query at that point. A failed
shard, timeout, or unavailable coverage check returns a search error rather than
an apparently successful partial result.

The `h2_network_searchable_sites` filter receives candidate sites and the selected
scope. Access and archived/deleted/spam status are checked after the filter, so
adding sites cannot grant access to private sites.
