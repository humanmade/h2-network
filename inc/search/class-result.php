<?php
/**
 * Search result.
 */

namespace H2\Network\Search;

use WP_Comment;
use WP_Post;
use WP_Site;

/**
 * A single search result, resolved to the object it represents.
 */
class Result {
	/**
	 * Result type.
	 *
	 * @var string Key from get_types().
	 */
	public string $type;

	/**
	 * Site the result belongs to.
	 *
	 * @var WP_Site
	 */
	public WP_Site $site;

	/**
	 * The object the result represents.
	 *
	 * Belongs to $site, so must be accessed with the site switched to.
	 *
	 * @var WP_Post|WP_Comment
	 */
	public $object;

	/**
	 * Relevance score from Elasticsearch.
	 *
	 * Null when results are not ordered by relevance.
	 *
	 * @var float|null
	 */
	public ?float $score = null;

	/**
	 * Fragments of the result showing where the search terms matched.
	 *
	 * Keyed by field (e.g. `title`, `content`), each an array of HTML strings
	 * with matches wrapped in <mark> elements. Fields with no match are omitted.
	 *
	 * @var array<string, string[]>
	 */
	public array $highlight = [];
}
