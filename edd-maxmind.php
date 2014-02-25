<?php
/*
Plugin Name: EDD MaxMind
Plugin URI: http://www.designwritebuild.com/edd/maxmind/
Description: Integrate MaxMind into your checkout process to help prevent fraud.
Author: DesignWriteBuild
Author URI: http://www.designwritebuild.com
Version: 1.1

/*
This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/


if( class_exists( 'EDD_License' ) ) {
	$edd_max_license = new EDD_License( __FILE__, 'MaxMind', '1.1', 'Pippin Williamson', 'maxmind_license_key' );
}

function edd_maxmind_textdomain() {
	// Set filter for plugin's languages directory
	$edd_lang_dir = dirname( plugin_basename( __FILE__ ) ) . '/languages/';
	$edd_lang_dir = apply_filters( 'edd_maxmind_languages_directory', $edd_lang_dir );

	// Load the translations
	load_plugin_textdomain( 'edd_maxmind', false, $edd_lang_dir );
}
add_action('init', 'edd_maxmind_textdomain');

function edd_maxmind_process_purchase( $data, $post ) {
    global $edd_options;

    if(  empty( $edd_options['maxmind_api_key'] ) ) return;

    $endpoint = 'https://minfraud.maxmind.com/app/';

    $user = edd_get_purchase_form_user( $data );

    switch( $edd_options['maxmind_service'] ) {

        case 'minfraud':

            $url = $endpoint . 'ccv2r';

            $args = array(
                'license_key'       => trim( $edd_options['maxmind_api_key'] ),
                'i'                 => edd_get_ip(),
                'emailMD5'          => $user['user_email'],
                'domain'            => edd_maxmind_get_email_domain( $user['user_email'] ),
                'user_agent'        => $_SERVER['HTTP_USER_AGENT'],
                'accept_language'   => $_SERVER['HTTP_ACCEPT_LANGUAGE'],
                'order_amount'      => edd_get_cart_total(),
                'order_currency'    => edd_get_currency(),
                'country'           => sanitize_text_field( $_POST[ 'billing_country' ] ),
            );

            if( isset( $edd_options['maxmind_fields'] ) ) {
                
                if( array_key_exists( 'state', $edd_options['maxmind_fields'] ) ) {

                    $args['state'] = sanitize_text_field( $_POST[ 'card_state' ] );
                }

                if( array_key_exists( 'postal', $edd_options['maxmind_fields'] ) ) {

                    $args['postal'] = sanitize_text_field( $_POST[ 'card_zip' ] );
                }

                if( array_key_exists( 'city', $edd_options['maxmind_fields'] ) ) {

                    $args['city'] = sanitize_text_field( $_POST[ 'card_city' ] );
                }

            }

            if( isset( $data['edd-user-id'] ) && $data['edd-user-id'] > 0 && $user = get_userdata( $data['edd-user-id'] ) ) {
                $args['usernameMD5'] = md5( strtolower( $user->user_login ) );
                $args['passwordMD5'] = $user->user_pass;
            }
        break;

        case 'proxydetect':

            $url = $endpoint . 'ipauth_http';
            $args = array(
                'l'         => $edd_options['maxmind_api_key'],
                'ipaddr'    => edd_get_ip()
            );

        break;
    }

    $response  = wp_remote_retrieve_body( wp_remote_post( $url, array( 'body' => $args ) ) );
    $responses = explode( ';', $response );
    $data      = array();

    foreach( $responses as $response ) {

        $response = explode( '=', $response );
        $data[ $response[0] ] = $response[1];

    }

    if( ( isset( $data['proxyScore'] ) && $data['proxyScore'] >= $edd_options['maxmind_proxy_score'] ) || ( isset( $data['riskScore'] ) && $data['riskScore'] >= $edd_options['maxmind_risk_score'] ) ) {
        $reason = ! empty( $edd_options['maxmind_error_message'] ) ? $edd_options['maxmind_error_message'] : __( 'Transaction Failed: %s', 'edd_maxmind' );
        $reasons = array();
        if( isset( $data['proxyScore'] ) )
            $reasons[] = sprintf( __( 'Proxy Score: %s', 'edd_maxmind' ), $data['proxyScore'] );
        if( isset( $data['riskScore'] ) )
            $reasons[] = sprintf( __( 'Risk Score: %s', 'edd_maxmind' ), $data['riskScore'] );
        $reasons = implode( ', ', $reasons );
        edd_set_error( 'maxmind' , sprintf( $reason, $reasons ) );
    } else {
		$GLOBALS['edd_maxmind_response_id'] = $data['maxmindID'];
		add_filter( 'edd_maxmind_response_id', create_function( '$id', "return $id;" ) );
		add_action( 'edd_insert_payment', create_function( '$payment', 'edd_maxmind_add_payment_meta( $payment );' ) );
	}

}
add_action( 'edd_checkout_error_checks', 'edd_maxmind_process_purchase', 10, 2 );

function edd_maxmind_add_payment_meta( $payment ) {
	update_post_meta( $payment, 'edd_maxmind_response_id', $GLOBALS['edd_maxmind_response_id'] );
}

function edd_maxmind_payment_details( $payment ) {
	$id = get_post_meta( $payment, 'edd_maxmind_response_id', true );
	if( ! empty( $id ) ) {
		echo '<div class="edd-maxmind-payment-details"><h4>MaxMind</h4><a target="_blank" href="https://www.maxmind.com/en/ccfd_log_view_detail?mmid=' . $id . '">View MaxMind Query - ' . $id . '</a></div>';
	}
}
add_action( 'edd_payment_view_details', 'edd_maxmind_payment_details' );

function edd_maxmind_checkout_fields() {
	global $edd_options;

	if( empty( $edd_options['maxmind_api_key'] ) ) {
		return;
	}	

	if( array_key_exists( edd_get_chosen_gateway(), edd_get_option( 'maxmind_gateways', array() ) ) ) {
		return;
	}
?>
	<fieldset id="edd_maxmind">
		<?php if( isset( $edd_options['maxmind_fields'] ) && array_key_exists( 'postal', $edd_options['maxmind_fields'] ) ) { ?>
			<p id="edd-maxmind-postcode-wrap">
				<label for="card_zip" class="edd-label">
					<?php _e( 'Billing Zip / Postal Code', 'edd_maxmind' ); ?>
					<?php if( edd_field_is_required( 'card_zip' ) ) { ?>
						<span class="edd-required-indicator">*</span>
					<?php } ?>
				</label>
				<span class="edd-description"><?php _e( 'The zip or postal code for your billing address.', 'edd_maxmind' ); ?></span>
				<input type="text" size="4" name="card_zip" class="card-zip edd-input<?php if( edd_field_is_required( 'card_zip' ) ) { echo ' required'; } ?>" placeholder="<?php _e( 'Zip / Postal code', 'edd_maxmind' ); ?>" value=""/>
			</p>
		<?php } if( isset( $edd_options['maxmind_fields'] ) && array_key_exists( 'city', $edd_options['maxmind_fields'] ) ) { ?>
			<p id="edd-maxmind-city-wrap">
				<label for="card_city" class="edd-label">
					<?php _e( 'Billing City', 'edd_maxmind' ); ?>
					<?php if( edd_field_is_required( 'card_city' ) ) { ?>
						<span class="edd-required-indicator">*</span>
					<?php } ?>
				</label>
				<span class="edd-description"><?php _e( 'The city for your billing address.', 'edd_maxmind' ); ?></span>
				<input type="text" id="card_city" name="card_city" class="card-city edd-input<?php if( edd_field_is_required( 'card_city' ) ) { echo ' required'; } ?>" placeholder="<?php _e( 'City', 'edd_maxmind' ); ?>" value=""/>
			</p>
		<?php } ?>
		<div id="edd_cc_address">
			<p id="edd-card-country-wrap">
				<label for="billing_country" class="edd-label">
					<?php _e( 'Billing Country', 'edd' ); ?>
					<?php if( edd_field_is_required( 'billing_country' ) ) { ?>
						<span class="edd-required-indicator">*</span>
					<?php } ?>
				</label>
				<span class="edd-description"><?php _e( 'The country for your billing address.', 'edd' ); ?></span>
				<select id="billing_country" name="billing_country" id="billing_country" class="billing_country edd-select<?php if( edd_field_is_required( 'billing_country' ) ) { echo ' required'; } ?>">
					<?php

					$selected_country = edd_get_shop_country();
					$countries = edd_get_country_list();
					foreach( $countries as $country_code => $country ) {
					  echo '<option value="' . $country_code . '"' . selected( $country_code, $selected_country, false ) . '>' . $country . '</option>';
					}
					?>
				</select>
			</p>
			<?php if( isset( $edd_options['maxmind_fields'] ) && array_key_exists( 'state', $edd_options['maxmind_fields'] ) ) {  ?>
				<p id="edd-card-state-wrap">
					<label for="card_state" class="edd-label">
						<?php _e( 'Billing State / Province', 'edd' ); ?>
						<?php if( edd_field_is_required( 'card_state' ) ) { ?>
						<span class="edd-required-indicator">*</span>
						<?php } ?>
					</label>
					<span class="edd-description"><?php _e( 'The state or province for your billing address.', 'edd' ); ?></span>
					<?php
					$selected_state = edd_get_shop_state();
					$states         = edd_get_shop_states( $selected_country );

					if( ! empty( $states ) ) : ?>
					<select id="card_state" name="card_state" id="card_state" class="card_state edd-select<?php if( edd_field_is_required( 'card_state' ) ) { echo ' required'; } ?>">
						<?php
						foreach( $states as $state_code => $state ) {
							echo '<option value="' . $state_code . '"' . selected( $state_code, $selected_state, false ) . '>' . $state . '</option>';
						}
						?>
					</select>
					<?php else : ?>
						<input type="text" size="6" name="card_state" id="card_state" class="card_state edd-input" placeholder="<?php _e( 'State / Province', 'edd' ); ?>"/>
					<?php endif; ?>
				</p>

			<?php } ?>
		</div>
	</fieldset>
<?php }
add_action( 'edd_purchase_form_after_cc_form', 'edd_maxmind_checkout_fields', 5 );

function edd_maxmind_add_device_tracking_js() { 
	global $edd_options;
	if( $edd_options['maxmind_service'] == 'minfraud' && ! empty( $edd_options['maxmind_user_id'] ) ) { ?>
		<script type="text/javascript">
			maxmind_user_id = "<?php echo trim( $edd_options['maxmind_user_id'] ); ?>";
			(function() {
				var mt = document.createElement('script'); mt.type = 'text/javascript'; mt.async = true;
				mt.src = ('https:' == document.location.protocol ? 'https://' : 'http://') + 'device.maxmind.com/js/device.js';
				var s = document.getElementsByTagName('script')[0]; s.parentNode.insertBefore(mt, s);
			})();
		</script>
	<?php }
}
add_action( 'edd_checkout_form_bottom', 'edd_maxmind_add_device_tracking_js' );

function edd_maxmind_get_email_domain( $email ) {
	$email = explode( '@', $email );
	return isset( $email[1] ) ? $email[1] : '';
}

function edd_maxmind_add_settings( $settings ) {

	$gateways = array();
	foreach( edd_get_payment_gateways() as $key => $data ) {
		$gateways[ $key ] = $data['admin_label'];
	}
 
	$maxmind_settings = array(
		array(
			'id' => 'maxmind_settings',
			'name' => '<strong>' . __( 'MaxMind Settings', 'edd_maxmind' ) . '</strong>',
			'desc' => __( 'Configure MaxMind', 'edd_maxmind' ),
			'type' => 'header'
		),
		array(
			'id' => 'maxmind_api_key',
			'name' => __( 'API Key', 'edd_maxmind' ),
			'desc' => __( 'Enter your MaxMind API key.', 'edd_maxmind' ),
			'type' => 'text',
			'size' => 'regular'
		),
		array(
			'id' => 'maxmind_user_id',
			'name' => __( 'MaxMind User ID', 'edd_maxmind' ),
			'desc' => sprintf( __( 'Enter your MaxMind user ID to enable <a href="%s">device tracking</a>.', 'edd_maxmind' ), 'http://www.maxmind.com/en/device_tracking' ),
			'type' => 'text',
			'size' => 'regular'
		),
		array(
			'id' => 'maxmind_service',
			'name' => __( 'Service', 'edd_maxmind' ),
			'desc' => __( 'Select the service you wish to use.', 'edd_maxmind' ),
			'type' => 'select',
			'options' => array(
				'minfraud'		=> __( 'MinFraud', 'edd_maxmind' ),
				'proxydetect'	=> __( 'Proxy Detect', 'edd_maxmind' ),
			)
		),
		array(
			'id' => 'maxmind_risk_score',
			'name' => __( 'Risk Score Limit', 'edd_maxmind' ),
			'desc' => __( 'Enter the maxminum risk score to allow, a valid risk score is between 0.01 and 100. For example if you enter 20 you would allow up to a 20% chance of fraud.', 'edd_maxmind' ),
			'type' => 'text',
			'size' => 'regular'
		),
		array(
			'id' => 'maxmind_proxy_score',
			'name' => __( 'Proxy Score', 'edd_maxmind' ),
			'desc' => __( 'Enter the maxminum proxy score to allow, a valid proxy score is between 0.00 and 4.00. A score of 0 or 1 will mean a low chance of a proxy being used while 3 or 4 means a medium to high chance of the customer using a proxy.', 'edd_maxmind' ),
			'type' => 'text',
			'size' => 'regular'
		),
		array(
			'id' => 'maxmind_error_message',
			'name' => __( 'Error Message', 'edd_maxmind' ),
			'desc' => __( 'Enter the error message you want to display when the purchase meets or exceeds your risk or proxy score.', 'edd_maxmind' ),
			'type' => 'text',
			'size' => 'regular'
		),
		array(
			'id' => 'maxmind_fields',
			'name' => __( 'Optional Fields', 'edd_maxmind' ),
			'desc' => __( 'While these options are not required, they can help give more reliable risk scores These fields will be added if not already present on checkout.', 'edd_maxmind' ),
			'type' => 'multicheck',
			'options' => array(
				'postal'	=> __( 'Zip/Post Code', 'edd_maxmind' ),
				'city'		=> __( 'City', 'edd_maxmind' ),
				'state'		=> __( 'State/Region', 'edd_maxmind' ),
			)
		),
		array(
			'id' => 'maxmind_gateways',
			'name' => __( 'Gateway Support', 'edd_maxmind' ),
			'desc' => __( 'Disable the MaxMind country selector on gateways that already have their own country select box.', 'edd_maxmind' ),
			'type' => 'multicheck',
			'options' => $gateways
		)
	);
 
	return array_merge( $settings, $maxmind_settings );	
}
add_filter( 'edd_settings_extensions', 'edd_maxmind_add_settings' );