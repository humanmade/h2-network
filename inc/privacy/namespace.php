<?php

namespace H2\Network\Privacy;

use DOMDocument;

/**
 * Bootstrap privacy functions.
 */
function bootstrap() {
	add_action( 'wpmu_new_blog', __NAMESPACE__ . '\\make_site_private' );
	add_action( 'plugins_loaded', __NAMESPACE__ . '\\anonymize_links' );
}

/**
 * Anonymize external links.
 */
function anonymize_links() {
	$anonymizer = get_site_option( 'h2_link_anonymizer', false );
	if ( ! $anonymizer ) {
		return;
	}

	add_filter( 'the_content', __NAMESPACE__ . '\\filter_content' );
	add_filter( 'comment_text', __NAMESPACE__ . '\\filter_content' );
}

/**
 * Filter content to change link references
 *
 * @param string $content Content to parse
 * @return string Altered content
 */
function filter_content( string $content ) : string {
	// Get all links and generate the DomDocument from the content
	$post_array = find_links( $content );

	// Grab the network information so we can check if it's external.
	$network = get_network();

	// Loop through each link in the post and prepend the href
	foreach ( $post_array['links'] as $link ) {
		$old_link = $link->getAttribute( 'href' );

		// Ignore if the link host is hmn.md
		if ( strpos( parse_url( $old_link, PHP_URL_HOST ), $network->domain ) !== false ) {
			continue;
		}

		$new_href = make_url_private( $old_link );
		$link->setAttribute( 'href', $new_href );
		$content = clean_dom_html( $post_array['dom']->saveHTML() );

		// We fixed it! On to the next one
		continue;
	}

	return $content;
}

/**
 * Get all links from the content
 *
 * @param string $content Content to fetch links from
 * @return array Array including `dom` (DOMDocument object) and `links` (array of DOMElement objects)
 */
function find_links( string $content ) : array {
	$doc_content = '<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />' . $content;

	// Disable PHP-level XML errors.
	$use_errors = libxml_use_internal_errors( true );
	$dom = new DOMDocument();
	$dom->loadHTML( $doc_content );
	$links = $dom->getElementsByTagName( 'a' );

	// Restore previous error level.
	libxml_use_internal_errors( $use_errors );

	return [
		'dom' => $dom,
		'links' => $links,
	];
}

/**
 * Change a URL to a private URL.
 *
 * @param string $url URL to make private
 * @return string URL bounced via private service
 */
function make_url_private( string $url ) : string {
	$bouncer = get_site_option( 'h2_link_anonymizer', false );
	if ( ! $bouncer ) {
		return $url;
	}

	return sprintf( $bouncer, $url );
}

/**
 * Clean HTML exported from DOMDocument
 *
 * @param string $html HTML exported from DOMDocument
 * @return string Cleaned HTML
 */
function clean_dom_html( $html ) {
	$tags = [
		'<html>',
		'</html>',
		'<head>',
		'</head>',
		'<body>',
		'</body>',
		'<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">',
	];
	$stripped = str_replace( $tags, '', $html );
	$stripped = preg_replace( '/^<!DOCTYPE.+?>/', '', $stripped );
	return trim( $stripped );
}
