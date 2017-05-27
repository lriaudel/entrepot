<?php
/**
 * Plugin Repositories functions.
 *
 * @package PluginRepositories\inc
 *
 * @since 1.0.0
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

function plugin_repositories_assets_url() {
	return plugin_repositories()->assets_url;
}

function plugin_repositories_assets_dir() {
	return plugin_repositories()->assets_dir;
}

function plugin_repositories_plugins_dir() {
	return apply_filters( 'plugin_repositories_plugins_dir', plugin_repositories()->repositories_dir );
}

function plugin_repositories_get_repository_json( $plugin = '' ) {
	if ( ! $plugin ) {
		return false;
	}

	$json = sprintf( '%1$s/%2$s.json', plugin_repositories_plugins_dir(), sanitize_file_name( $plugin ) );
	if ( ! file_exists( $json ) ) {
		return false;
	}

	$data = file_get_contents( $json );
	return json_decode( $data );
}

function plugin_repositories_get_plugin_latest_stable_release( $atom_url = '', $plugin = array() ) {
	$tag_data = new stdClass;
	$tag_data->is_update = false;

	if ( ! $atom_url  ) {
		// For Unit Testing purpose only. Do not use this constant in your code.
		if ( defined( 'PR_TESTING_ASSETS' ) && isset( $plugin['slug'] ) &&  'plugin-repositories' === $plugin['slug'] ) {
			$atom_url = trailingslashit( plugin_repositories()->dir ) . 'tests/phpunit/assets/releases';
		} else {
			return $tag_data;
		}
	}

	$atom_url = rtrim( $atom_url, '.atom' ) . '.atom';

	if ( ! class_exists( 'AtomParser') ) {
		require_once( ABSPATH . WPINC . '/atomlib.php' );
	}

	$atom = new AtomParser();
	$atom->FILE = $atom_url;
	$atom->parse();

	if ( ! isset( $atom->feed ) || ! isset( $atom->feed->entries ) ) {
		return $tag_data;
	}

	foreach ( $atom->feed->entries as $release ) {
		if ( ! isset( $release->id ) ) {
			continue;
		}

		$id     = explode( '/', $release->id );
		$tag    = $id[ count( $id ) - 1 ];
		$stable = str_replace( '.', '', $tag );

		if ( ! is_numeric( $stable ) ) {
			continue;
		}

		$response = array(
			'id'          => $release->id,
			'slug'        => '',
			'plugin'      => '',
			'new_version' => $tag,
			'url'         => '',
			'package'     => '',
		);

		if ( ! empty( $plugin['Version'] ) ) {
			if ( version_compare( $tag, $plugin['Version'], '<' ) ) {
				continue;
			}

			$response = wp_parse_args( array(
				'id'          => rtrim( str_replace( array( 'https://', 'http://' ), '', $plugin['GitHub Plugin URI'] ) ),
				'slug'        => $plugin['slug'],
				'plugin'      => $plugin['plugin'],
				'url'         => $plugin['GitHub Plugin URI'],
				'package'     => sprintf( '%1$sreleases/download/%2$s/%3$s',
					trailingslashit( $plugin['GitHub Plugin URI'] ),
					$tag,
					sanitize_file_name( $plugin['slug'] . '.zip' )
				),
			), $response );

			if ( ! empty( $release->content ) ) {
				$tag_data->full_upgrade_notice = end( $release->content );
			}

			$tag_data->is_update = true;
		}

		foreach ( $response as $k => $v ) {
			$tag_data->{$k} = $v;
		}

		break;
	}

	return $tag_data;
}

function plugin_repositories_extra_header( $headers = array() ) {
	if (  ! isset( $headers['GitHub Plugin URI'] ) ) {
		$headers['GitHub Plugin URI'] = 'GitHub Plugin URI';
	}

	return $headers;
}
add_filter( 'extra_plugin_headers', 'plugin_repositories_extra_header', 10, 1 );

function plugin_repositories_update_plugin_repositories( $option = null ) {
	if ( ! did_action( 'http_api_debug' ) ) {
		return $option;
	}

	$plugins      = get_plugins();
	$repositories = array_diff_key( $plugins, wp_list_filter( $plugins, array( 'GitHub Plugin URI' => '' ) ) );

	$repositories_data = array();
	foreach ( $repositories as $kr => $dp ) {
		$repository_name = trim( dirname( $kr ), '/' );
		$json = plugin_repositories_get_repository_json( $repository_name );

		if ( ! $json || ! isset( $json->releases ) ) {
			continue;
		}

		$response = plugin_repositories_get_plugin_latest_stable_release( $json->releases, array_merge( $dp, array(
			'plugin' => $kr,
			'slug'   => $repository_name,
		) ) );

		$repositories_data[ $kr ] = $response;
	}

	$updated_repositories = wp_list_filter( $repositories_data, array( 'is_update' => true ) );

	if ( ! $updated_repositories ) {
		return $option;
	}

	if ( isset( $option->response ) ) {
		$option->response = array_merge( $option->response, $updated_repositories );
	} else {
		$option->response = $repositories_data;
	}

	// Prevent infinite loops.
	remove_filter( 'set_site_transient_update_plugins', 'plugin_repositories_update_plugin_repositories' );

	set_site_transient( 'update_plugins', $option );
	return $option;
}
add_filter( 'set_site_transient_update_plugins', 'plugin_repositories_update_plugin_repositories' );

function plugin_repositories_plugin_repository_information() {
	global $tab;

	if ( empty( $_REQUEST['plugin'] ) ) {
		return;
	}

	$plugin = wp_unslash( $_REQUEST['plugin'] );

	if ( isset( $_REQUEST['section'] ) && 'changelog' === $_REQUEST['section'] ) {
		$repository_updates = get_site_transient( 'update_plugins' );

		if ( empty( $repository_updates->response ) ) {
			return;
		}

		$repository = wp_list_filter( $repository_updates->response, array( 'slug' => $plugin ) );
		if ( empty( $repository ) || 1 !== count( $repository ) ) {
			return;
		}

		$repository = reset( $repository );

		if ( ! empty( $repository->full_upgrade_notice ) ) {
			echo html_entity_decode( $repository->full_upgrade_notice, ENT_QUOTES, get_bloginfo( 'charset' ) );
		} else {
			wp_die( __( 'Sorry, this plugin repository has not included an upgrade notice.', 'plugin-repositories' ) );
		}
	} else {
		$repository_data = plugin_repositories_get_repository_json( $plugin );

		if ( ! $repository_data ) {
			return;
		}

		$repository_info = __( 'Sorry, the README.md file of this plugin repository is not reachable at the moment.', 'plugin-repositories' );
		if ( ! empty( $repository_data->README ) ) {
			$repository_info = file_get_contents( $repository_data->README );
			$has_readme = true;
		}

		if ( $has_readme ) {
			echo $repository_info;
		} else {
			wp_die( $repository_info );
		}
	}

	iframe_footer();
	exit;
}
add_action( 'install_plugins_pre_plugin-information', 'plugin_repositories_plugin_repository_information', 5 );

function plugin_repositories_admin_home() {
	$json         = plugin_repositories_assets_dir() . 'repositories.min.json';
	$raw          = file_get_contents( $json );
	$repositories =  json_decode( $raw );
	?>
	<h1><?php esc_html_e( 'Repositories', 'plugin-repositories' ); ?></h1>

	<div class="wrap">
		<?php var_dump( $repositories ); ?>
	</div>
	<?php
}

function plugin_repositories_add_menu() {
	add_menu_page(
		__( 'Repositories', 'plugin-repositories' ),
		__( 'Repositories', 'plugin-repositories' ),
		'manage_options',
		'repositories',
		'plugin_repositories_admin_home',
		plugin_repositories_assets_url() . 'repo.svg'
	);
}
