<?php

namespace H2Selector\UI;

use H2Selector;

const PAGE_SLUG = 'h2sites';

/**
 * Bootstrap UI actions.
 */
function bootstrap() {
	add_action( 'admin_init', __NAMESPACE__ . '\\register_settings' );
	add_action( 'network_admin_menu', __NAMESPACE__ . '\\register_admin_page' );
	add_action( 'network_admin_edit_' . PAGE_SLUG,  __NAMESPACE__ . '\\handle_update_request' );
}

/**
 * Register network-wide setting.
 */
function register_settings() {
	register_setting( PAGE_SLUG, 'h2_sites', [
		'sanitize_callback' => '\\H2Selector\\sanitize_sites',
	] );
}

/**
 * Register Network Admin settings page.
 */
function register_admin_page() {
	add_submenu_page(
		'settings.php',
		'H2 Sites',
		'H2 Sites',
		'manage_network_options',
		PAGE_SLUG,
		__NAMESPACE__ . '\\render_admin_page'
	);
	add_settings_section( 'default', null, false, PAGE_SLUG );

	add_settings_field(
		'h2_sites',
		'Selectable sites',
		__NAMESPACE__ . '\\render_form_field',
		PAGE_SLUG,
		'default'
	);
}

/**
 * Render the form field for the site selector.
 */
function render_form_field() {
	// Output a dummy input to ensure the setting is saved.
	printf( '<input type="hidden" name="%s[]" value="" />', 'h2_sites' );

	// Output the sites that can be activated.
	$sites = H2Selector\get_available_sites();
	$current = get_site_option( 'h2_sites', [] );
	foreach ( $sites as $site ) {
		$value = absint( $site->blog_id );
		$enabled = in_array( $value, $current );
		printf(
			'<label><input type="checkbox" name="%s[]" value="%s" %s /> %s</label><br />',
			'h2_sites',
			esc_attr( $value ),
			checked( $enabled, true, false ),
			esc_html( $site->blogname )
		);
	}

	echo '<p class="description">' . esc_html__( 'Select which sites to display in the site selector. This setting applies to all H2 sites on the network.', 'h2' ) . '</p>';
}

/**
 * Render the admin page for the site selector settings.
 */
function render_admin_page() {
	if ( isset( $_GET['updated'] ) ) {
		?>
			<div id="message" class="updated notice is-dismissible"><p><?php _e( 'Options saved.' ) ?></p></div>
		<?php
	}

	?>
		<div class="wrap">
			<h1><?php esc_html_e( 'H2 Site Selector', 'h2' ); ?></h1>
			<form
				method="POST"
				action="<?php echo esc_attr( sprintf( 'edit.php?action=%s', PAGE_SLUG ) ) ?>"
			>
				<?php
					settings_fields( PAGE_SLUG );
					do_settings_sections( PAGE_SLUG );
					submit_button();
				?>
			</form>
		</div>
	<?php
}

/**
 * Handle a POST from the form.
 */
function handle_update_request() {
	// Check the nonce.
	check_admin_referer( PAGE_SLUG . '-options' );

	// This is the list of registered options.
	global $new_whitelist_options;
	$options = $new_whitelist_options[ PAGE_SLUG ];

	// Save H2 options.
	foreach ( $options as $option ) {
		if ( ! isset( $_POST[ $option ] ) ) {
			continue;
		}

		update_site_option( $option, $_POST[ $option ] );
	}

	// At last we redirect back to our options page.
	wp_redirect(
		add_query_arg(
			[
				'page' => PAGE_SLUG,
				'updated' => 'true'
			],
			network_admin_url( 'settings.php' )
		)
	);
	exit;
}
