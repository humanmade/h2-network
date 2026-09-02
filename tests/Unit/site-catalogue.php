<?php

class WP_REST_Request {}

class WP_Error {
	private $code;
	private $data;

	public function __construct( $code, $message, $data = null ) {
		$this->code = $code;
		$this->data = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_data() {
		return $this->data;
	}
}

class WP_REST_Response {
	public $data;
	public $headers = [];

	public function __construct( $data ) {
		$this->data = $data;
	}

	public function header( $name, $value ) {
		$this->headers[ $name ] = $value;
	}
}

class WP_Site {
	public $id;
	public $blog_id;
	public $network_id;
	public $site_id;
	public $registered;
	public $last_updated;
	public $public;
	public $archived;
	public $blogname;
	public $home;
	public $siteurl;

	public function __construct( array $data ) {
		foreach ( $data as $key => $value ) {
			$this->$key = $value;
		}

		$this->id = $this->blog_id;
		$this->network_id = $this->site_id;
		$this->blogname = $this->blogname ?? "Switcher Site {$this->blog_id}";
		$this->home = $this->home ?? "https://switcher-{$this->blog_id}.hmn.md";
		$this->siteurl = $this->siteurl ?? "https://wordpress-{$this->blog_id}.hmn.md";
	}
}

$current_site_id = null;
$get_sites_args = null;
$registered_routes = [];
$sites_by_id = [];
$site_urls = [];
$site_stylesheets = [
	1 => 'h2',
	2 => 'h2',
	3 => 'h2',
	4 => 'h2',
	5 => 'twentytwentysix',
];

function add_action() {}

function register_rest_route( $namespace, $route, $args ) {
	global $registered_routes;
	$registered_routes[ $namespace . '/' . $route ] = $args;
}

function get_sites( $args ) {
	global $get_sites_args, $sites_by_id;
	$get_sites_args = $args;

	$sites = array_map( function ( $data ) {
		return new WP_Site( array_merge( [
			'site_id'      => 1,
			'registered'   => '2025-01-01 00:00:00',
			'last_updated' => '2026-01-01 00:00:00',
			'public'       => 1,
			'archived'     => 0,
		], $data ) );
	}, [
		[ 'blog_id' => 1 ],
		[ 'blog_id' => 2, 'archived' => 1 ],
		[ 'blog_id' => 3, 'public' => 0 ],
		[ 'blog_id' => 4, 'public' => 0, 'archived' => 1 ],
		[ 'blog_id' => 5 ],
	] );
	$sites_by_id = array_column( $sites, null, 'blog_id' );
	return $sites;
}

function get_site_option( $name, $default = false ) {
	return 'h2_sites' === $name ? [ 1, 2, 3, 4 ] : $default;
}

function get_site( $site_id ) {
	global $sites_by_id;
	return $sites_by_id[ $site_id ] ?? null;
}

function current_user_can_for_blog( $site_id ) {
	return 4 !== $site_id;
}

function switch_to_blog( $site_id ) {
	global $current_site_id;
	$current_site_id = $site_id;
}

function restore_current_blog() {
	global $current_site_id;
	$current_site_id = null;
}

function get_stylesheet() {
	global $current_site_id, $site_stylesheets;
	return $site_stylesheets[ $current_site_id ];
}

function home_url() {
	global $current_site_id, $site_urls;
	return $site_urls[ $current_site_id ] ?? "https://site-{$current_site_id}.hmn.md";
}

function get_bloginfo( $field ) {
	global $current_site_id;

	if ( 'name' === $field ) {
		return "Site {$current_site_id} &amp; Co";
	}

	if ( 'description' === $field ) {
		return "Description {$current_site_id} &amp; notes";
	}

	return 'UTF-8';
}

function wp_parse_url( $url, $component ) {
	return parse_url( $url, $component );
}

function rest_ensure_response( $data ) {
	return new WP_REST_Response( $data );
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

require dirname( __DIR__, 2 ) . '/inc/namespace.php';
require dirname( __DIR__, 2 ) . '/inc/api/namespace.php';

function assert_same( $expected, $actual, $message ) {
	if ( $expected === $actual ) {
		return;
	}

	fwrite( STDERR, $message . PHP_EOL );
	fwrite( STDERR, 'Expected: ' . var_export( $expected, true ) . PHP_EOL );
	fwrite( STDERR, 'Actual: ' . var_export( $actual, true ) . PHP_EOL );
	exit( 1 );
}

$sites = H2\Network\API\get_catalogue_sites();
assert_same(
	[ 1, 2, 3, 4 ],
	array_map( function ( $site ) {
		return $site->blog_id;
	}, $sites ),
	'The catalogue should include active and archived H2 sites regardless of public visibility.'
);
assert_same(
	[
		'number'  => 0,
		'mature'  => false,
		'spam'    => false,
		'deleted' => false,
	],
	$get_sites_args,
	'The catalogue query should exclude mature, spam, and deleted sites.'
);

$archived = H2\Network\API\prepare_site_for_api( $sites[1] );
assert_same(
	[
		'id'              => 2,
		'network_id'      => 1,
		'name'            => 'Site 2 & Co',
		'description'     => 'Description 2 & notes',
		'url'             => 'https://site-2.hmn.md',
		'host'            => 'site-2.hmn.md',
		'registered_at'   => '2025-01-01 00:00:00',
		'last_updated_at' => '2026-01-01 00:00:00',
		'status'          => [
			'active'   => false,
			'public'   => true,
			'archived' => true,
		],
	],
	$archived,
	'The shared site projection should expose the complete Human catalogue contract.'
);

$switcher = H2\Network\API\get_sites_for_api( new WP_REST_Request() );
assert_same(
	[ 1, 2, 3 ],
	array_column( $switcher->data, 'id' ),
	'The site switcher should continue to hide non-public sites the current user cannot read.'
);
assert_same(
	[
		'id'              => 1,
		'network'         => 1,
		'name'            => 'Switcher Site 1',
		'url'             => 'https://switcher-1.hmn.md',
		'siteurl'         => 'https://wordpress-1.hmn.md',
		'network_id'      => 1,
		'description'     => 'Description 1 & notes',
		'host'            => 'site-1.hmn.md',
		'registered_at'   => '2025-01-01 00:00:00',
		'last_updated_at' => '2026-01-01 00:00:00',
		'status'          => [
			'active'   => true,
			'public'   => true,
			'archived' => false,
		],
	],
	$switcher->data[0],
	'The existing site switcher should preserve its fields while exposing the shared metadata.'
);

$site_urls = [
	1 => 'https://zeta.hmn.md',
	2 => 'https://Alpha.hmn.md',
	3 => 'https://beta.hmn.md',
];
$catalogue = H2\Network\API\get_site_catalogue();
assert_same(
	[ 'Alpha.hmn.md', 'beta.hmn.md', 'site-4.hmn.md', 'zeta.hmn.md' ],
	array_column( $catalogue, 'host' ),
	'The reusable site catalogue should be ordered by hostname.'
);
assert_same(
	[
		'active'   => false,
		'public'   => false,
		'archived' => true,
	],
	$catalogue[2]['status'],
	'The catalogue should describe a non-public archived site without excluding it.'
);

$site_urls[2] = '';
$invalid = H2\Network\API\prepare_site_for_api( $sites[1] );
assert_same(
	true,
	is_wp_error( $invalid ),
	'A site without a valid home URL should fail catalogue projection.'
);
assert_same(
	'h2_network_site_catalogue_invalid_url',
	$invalid->get_error_code(),
	'An invalid site URL should return a stable error code.'
);
assert_same(
	[ 'status' => 500, 'site_id' => 2 ],
	$invalid->get_error_data(),
	'An invalid site URL should identify the affected site.'
);

$invalid_catalogue = H2\Network\API\get_site_catalogue();
assert_same(
	true,
	is_wp_error( $invalid_catalogue ),
	'The reusable catalogue should propagate a site projection error.'
);

$invalid_switcher = H2\Network\API\get_sites_for_api( new WP_REST_Request() );
assert_same(
	true,
	is_wp_error( $invalid_switcher ),
	'The site-switcher route should propagate a site projection error.'
);

echo "site catalogue tests passed\n";
