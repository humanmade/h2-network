<?php
/**
 * Search query parameters.
 */

namespace H2\Network\Search;

use DateTimeInterface;

/**
 * Value object describing a network search.
 */
class Query {
	/**
	 * Text to search for.
	 *
	 * @var string
	 */
	public string $search = '';

	/**
	 * Match any term (discovery), or require all terms.
	 *
	 * @var string One of 'any' or 'all'.
	 */
	public string $match = 'any';

	/**
	 * Search the site switcher, or all accessible H2 sites on the network.
	 *
	 * @var string One of 'active' or 'network'.
	 */
	public string $scope = 'active';

	/**
	 * IDs of the sites to search.
	 *
	 * Empty to search every site available to the current user. Sites the
	 * current user cannot access are never searched, regardless of this value.
	 *
	 * @var int[]
	 */
	public array $sites = [];

	/**
	 * Result types to include.
	 *
	 * Keys from get_types(); empty to include every type.
	 *
	 * @var string[]
	 */
	public array $types = [];

	/**
	 * Only include results published after this date.
	 *
	 * @var DateTimeInterface|null
	 */
	public ?DateTimeInterface $after = null;

	/**
	 * Only include results published before this date.
	 *
	 * @var DateTimeInterface|null
	 */
	public ?DateTimeInterface $before = null;

	/**
	 * Only include results written by this user.
	 *
	 * @var int|null
	 */
	public ?int $author = null;

	/**
	 * Field to order results by.
	 *
	 * @var string One of 'relevance' or 'date'.
	 */
	public string $orderby = 'relevance';

	/**
	 * Direction to order results in.
	 *
	 * @var string One of 'asc' or 'desc'.
	 */
	public string $order = 'desc';

	/**
	 * Page of results to return.
	 *
	 * @var int
	 */
	public int $page = 1;

	/**
	 * Number of results per page.
	 *
	 * @var int
	 */
	public int $per_page = 10;

	/**
	 * Get the number of results to skip for the requested page.
	 *
	 * @return int
	 */
	public function get_offset() : int {
		return ( max( $this->page, 1 ) - 1 ) * $this->per_page;
	}
}
