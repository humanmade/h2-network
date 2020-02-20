<?php

namespace H2\Network\UI;

use H2\Network;

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
		'sanitize_callback' => '\\H2\\Network\\sanitize_sites',
	] );
	register_setting( PAGE_SLUG, 'h2_default_private', [] );
	register_setting( PAGE_SLUG, 'h2_allow_short_usernames', [] );
	register_setting( PAGE_SLUG, 'h2_override_moderation', [] );
	register_setting( PAGE_SLUG, 'h2_allow_editing_own_comments', [] );
	register_setting( PAGE_SLUG, 'h2_allow_listing_users', [] );
	register_setting( PAGE_SLUG, 'h2_link_anonymizer', [] );

	add_filter( 'pre_update_site_option_h2_default_private', __NAMESPACE__ . '\\sanitize_checkbox_value' );
	add_filter( 'pre_update_site_option_h2_default_theme', __NAMESPACE__ . '\\sanitize_checkbox_value' );
	add_filter( 'pre_update_site_option_h2_allow_short_usernames', __NAMESPACE__ . '\\sanitize_checkbox_value' );
	add_filter( 'pre_update_site_option_h2_override_moderation', __NAMESPACE__ . '\\sanitize_checkbox_value' );
	add_filter( 'pre_update_site_option_h2_allow_editing_own_comments', __NAMESPACE__ . '\\sanitize_checkbox_value' );
	add_filter( 'pre_update_site_option_h2_allow_listing_users', __NAMESPACE__ . '\\sanitize_checkbox_value' );
	add_filter( 'pre_update_site_option_h2_link_anonymizer', 'sanitize_text_field' );
}

/**
 * Register Network Admin settings page.
 */
function register_admin_page() {
	add_submenu_page(
		'settings.php',
		'H2 Network',
		'H2 Network',
		'manage_network_options',
		PAGE_SLUG,
		__NAMESPACE__ . '\\render_admin_page'
	);
	add_settings_section( 'default', null, false, PAGE_SLUG );

	add_settings_field(
		'h2_default_private',
		'Network settings',
		__NAMESPACE__ . '\\render_checkbox_list',
		PAGE_SLUG,
		'default',
		[
			'options' => [
				[
					'option_name' => 'h2_default_private',
					'label' => __( 'Make new sites private by default', 'h2' ),
				],
				[
					'option_name' => 'h2_allow_short_usernames',
					'label' => __( 'Allow usernames shorter than 4 characters', 'h2' ),
				],
			],
		]
	);
	add_settings_field(
		'h2_override_moderation',
		'Override settings',
		__NAMESPACE__ . '\\render_checkbox_list',
		PAGE_SLUG,
		'default',
		[
			'options' => [
				[
					'option_name' => 'h2_override_moderation',
					'label' => __( "Disable WordPress comment moderation", 'h2' ),
					'description' => __( 'This will disable comment moderation and limits on links for all H2 sites on the network.', 'h2' ),
				],
				[
					'option_name' => 'h2_allow_editing_own_comments',
					'label' => __( 'Allow users to edit their own comments', 'h2' ),
					'description' => __( 'Overrides comment permissions to allow users to edit and delete their own comments.', 'h2' ),
				],
				[
					'option_name' => 'h2_allow_listing_users',
					'label' => __( "Allow all users to view other users", 'h2' ),
					'description' => __( 'Overrides user permissions to allow all users to view all other users on a site.', 'h2' ),
				],
			],
		]
	);
	add_settings_field(
		'h2_link_anonymizer',
		'Link anonymizer',
		__NAMESPACE__ . '\\render_text_field',
		PAGE_SLUG,
		'default',
		[
			'option_name' => 'h2_link_anonymizer',
			'label_for' => 'h2_link_anonymizer',
			'description' => __( 'Set the URL for a link anonymizer. All external links will pass via this. %s will be replaced with the external URL.', 'h2' ),
			'placeholder' => 'https://href.li/?%s',
		]
	);
	add_settings_field(
		'h2_sites',
		'Selectable sites',
		__NAMESPACE__ . '\\render_selector_field',
		PAGE_SLUG,
		'default'
	);
}

/**
 * Render the form field for the site selector.
 */
function render_selector_field() {
	// Output a dummy input to ensure the setting is saved.
	printf( '<input type="hidden" name="%s[]" value="" />', 'h2_sites' );

	// Output the sites that can be activated.
	$sites = Network\get_available_sites();
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
 * Render a list of checkbox fields.
 */
function render_checkbox_list( $args ) {
	echo '<fieldset>';
	foreach ( $args['options'] as $option ) {
		render_checkbox_field( $option );
		echo '<br />';
	}
	echo '</fieldset>';
}

/**
 * Render the form field for regular checkboxes.
 */
function render_checkbox_field( $args ) {
	$option = $args['option_name'];
	$current = get_site_option( $option, false );
	printf(
		'<label><input type="checkbox" name="%s" %s /> %s</label>',
		$option,
		checked( $current, true, false ),
		$args['label']
	);

	if ( isset( $args['description'] ) ) {
		printf(
			'<p class="description">%s</p>',
			esc_html( $args['description'] )
		);
	}
}

/**
 * Render the form field for a text field.
 */
function render_text_field( $args ) {
	$option = $args['option_name'];
	$value = get_site_option( $option, '' );
	printf(
		'<input id="%s" type="text" name="%s" value="%s" placeholder="%s" />',
		$args['label_for'],
		$option,
		$value,
		$args['placeholder'] ?? ''
	);

	if ( isset( $args['description'] ) ) {
		printf(
			'<p class="description">%s</p>',
			esc_html( $args['description'] )
		);
	}
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
			<h1><?php esc_html_e( 'H2 Network', 'h2' ); ?></h1>
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
 * Sanitize a boolean value from a form.
 *
 * @param string|null $value One of 'on' or null
 * @return boolean
 */
function sanitize_checkbox_value( $value ) : bool {
	return $value === 'on';
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
		$value = isset( $_POST[ $option ] ) ? wp_unslash( $_POST[ $option ] ) : null;
		update_site_option( $option, $value );
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
