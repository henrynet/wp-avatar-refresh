<?php
/**
* Plugin Name: WP Avatar Refresh
* Plugin URI: https://github.com/henrynet/wp-avatar-refresh/
* Description: A plugin to refresh and sync user avatar between Wordpress and Discourse.
* Version: 0.1
* Author: henrynet
* Author URI: https://github.com/henrynet/
* License: MIT
*/

if ( ! class_exists( 'WP_Avatar_Refresh' ) ) {

class WP_Avatar_Refresh {

    public static $debug = false;

    // Singleton
    private static $instance = false;

    public static function get_instance() {
        if ( ! self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

	function __construct() { 
		register_activation_hook( __FILE__, array( $this, 'install' ) );
		register_deactivation_hook( __FILE__, array( $this, 'uninstall' ) );

		add_action( 'init', array( $this, 'custom_wp_avatar_refresh' ) );

		add_action( 'show_user_profile', array( $this, 'refresh_user_avatar' ) );
		add_action( 'edit_user_profile', array( $this, 'refresh_user_avatar' ) );
	}

	/*
	 * Actions perform on activation of plugin 
	 */ 
	function install() { }
	/*
	 * Actions perform on de-activation of plugin
	 */
	function uninstall() { }

	function get_avatar_url( $user_id ) {
		$avatar = get_avatar( $user_id );
		if ( preg_match( "/src=['\"](.*?)['\"]/i", $avatar, $matches ) )
			return utf8_uri_encode( $matches[1] );
	}

	function _post_discourse_api( $url, $params, $err_name ) {
		return self::_discourse_api( $url, 'POST', $params, $err_name );
	}
	function _put_discourse_api( $url, $params, $err_name ) {
		return self::_discourse_api( $url, 'PUT', $params, $err_name );
	}
	function _discourse_api( $url, $method, $params, $err_name ) {
		$response = wp_remote_post( $url, array(
					'method' => $method,
					'timeout' => 60,
					'redirection' => 5,
					'httpversion' => '1.0',
					'blocking' => true,
					'headers' => array(),
					'body' => $params,
					'cookies' => array()
					)
				);

		if ( is_wp_error( $response ) ) {
			$error_message = $response->get_error_message();
			throw new Exception( $err_name . '_err_msg: ' . $error_message );
		} else {
			if ( self::$debug ) {
				echo 'Response:<pre>';
				print_r( $response );
				echo '</pre>';
			}
			$parsed_response = json_decode( $response['body'] );
			if ( ! empty( $parsed_response->error ) && ! empty( $parsed_response->error->code ) ) {
				throw new Exception( $err_name . '_err_code: ' . $parsed_response->error->code );
			}
			return $parsed_response;
		}
	}

	function refresh_discourse_avatar( $avatar_user, $avatar_url ) {
		if ( class_exists( 'Discourse' ) && class_exists( 'Discourse_SSO' ) ) {
			$discourse_options = wp_parse_args( get_option( 'discourse' ), Discourse::$options );
			$sso_secret = $discourse_options['sso-secret'];

			$params = array(
					'name' => $avatar_user->display_name,
					'username' => $avatar_user->user_login,
					'email' => $avatar_user->user_email,
					'about_me' => $avatar_user->description,
					'external_id' => $avatar_user->ID,
					'avatar_url' => $avatar_url
					);
			$payload = base64_encode( http_build_query( $params ) );
			$sig = hash_hmac( "sha256", $payload, $sso_secret );

			$url = $discourse_options['url'] . '/admin/users/sync_sso';
			$params = array(
					'api_key' => $discourse_options['api-key'], 
					'api_username' => $discourse_options['publish-username'], 
					'sso' => $payload,
					'sig' => $sig
					);

			$parsed_response = self::_post_discourse_api( $url, $params, 'discourse_sync_sso' );
			if ( self::$debug ) {
				echo "Username: $parsed_response->username, Avatar URL: $avatar_url";
			}

			if ( empty( $avatar_url ) ) {
				return;
			}

			$discourse_username = $parsed_response->username;
			if ( empty( $discourse_username ) ) {
				return; 
			}
			update_user_meta( $avatar_user->ID, 'discourse_username', $discourse_username );

			$url = $discourse_options['url'] . '/users/' . $discourse_username . '/preferences/user_image';
			$params = array( 
					'api_key' => $discourse_options['api-key'], 
					'api_username' => $discourse_options['publish-username'], 
					'username' => $discourse_username, 
					'file' => $avatar_url,
					'image_type' => 'avatar'
					);
			$parsed_response = self::_post_discourse_api( $url, $params, 'discourse_user_image' );
			$upload_id = $parsed_response->upload_id;

			$url = $discourse_options['url'] . '/users/' . $discourse_username . '/preferences/avatar/pick';
			$params = array( 
					'api_key' => $discourse_options['api-key'], 
					'api_username' => $discourse_options['publish-username'], 
					'username' => $discourse_username,
					'upload_id' => $upload_id
					);
			$parsed_response = self::_put_discourse_api( $url, $params, 'discourse_avatar_pick' );
		}
	}

	function curl_get_avatar_url( $avatar_url ) {
		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $avatar_url );
		curl_setopt( $ch, CURLOPT_HEADER, true );
		curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true ); // Must be set to true so that PHP follows any "Location:" header
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );

		$res = curl_exec( $ch ); // $res will contain all headers
		$url = curl_getinfo( $ch, CURLINFO_EFFECTIVE_URL ); // This is what you need, it will return you the last effective URL

		return $url;
	}

	function custom_wp_avatar_refresh() {
		if ( isset( $_GET['debug'] ) ) {
			self::$debug = true;
		}
		if ( isset( $_GET['avatar'] ) && is_user_logged_in() ) {
			$var_avatar = $_GET['avatar'];
			if ( $var_avatar === 'refresh' ) {
				global $current_user;
				get_currentuserinfo();

				if ( isset( $_GET['avatar_user'] ) && current_user_can( 'administrator' ) ) {
					$avatar_user = get_user_by( 'id', $_GET['avatar_user'] );
					if ( ! $avatar_user ) {
						wp_safe_redirect( get_home_url() );
						exit;
					}
				}
				else {
					$avatar_user = $current_user;
				}

				$avatar_id = get_user_meta( $avatar_user->ID, 'wp_user_avatar', true );
				if ( ! empty( $avatar_id ) ) {
					$avatar_url = self::get_avatar_url( $avatar_user->ID );
				}
				else {
					$meta_key = '_wc_social_login_profile_image';
					$profile_image = get_user_meta( $avatar_user->ID, $meta_key, true );
					if ( ! empty( $profile_image ) ) {
						$avatar_url = wp_nonce_url( $profile_image, $meta_key . wp_rand() );
						update_user_meta( $avatar_user->ID, $meta_key, $avatar_url );
					}
				}

				// get real image url without redirect
				if ( ! empty ( $avatar_url ) ) {
					$avatar_url = self::curl_get_avatar_url( $avatar_url );
				}
				self::refresh_discourse_avatar( $avatar_user, $avatar_url );

				// redirect back to referer
				if ( ! self::$debug ) {
					if ( wp_get_referer() ) {
						wp_safe_redirect( wp_get_referer() );
					}
					else {
						wp_safe_redirect( get_home_url() );
					}
				}

				exit;
			}
		}
	}

	function refresh_user_avatar( $user ) { 
		global $profileuser;
		$user_id = $profileuser->ID;
		?>
		<table class="form-table">
			<tbody>
			<tr>
			<th><?php _e( 'Refresh User Avatar', 'textdomain' ); ?></th>
			<td>
			<a href="/?avatar=refresh&avatar_user=<?php echo $user_id; ?>" class="button"><?php _e( 'Refresh', 'textdomain' ); ?></a>
			</td>
			</tr>
			</tbody>
		</table> 
	<?php
	}
}

WP_Avatar_Refresh::get_instance();

}
