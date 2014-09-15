<?php

class WCS_Admin_Importer {
	var $import_results = array();

	var $upload_error = '';

	public function __construct() {

		$this->admin_url        = admin_url( 'admin.php?page=import_subscription' );
		$this->rows_per_request = ( defined( 'WCS_IMPORT_ROWS_PER_REQUEST' ) ) ? WCS_IMPORT_ROWS_PER_REQUEST : 20;

		add_action( 'admin_init', array( &$this, 'post_request_handler' ) );

		add_action( 'admin_init', array( &$this, 'add_import_tool' ) );

		add_action( 'admin_menu', array( &$this, 'add_sub_menu' ), 10 );

		add_action( 'admin_enqueue_scripts', array( &$this, 'enqueue_scripts' ) );

		add_action( 'wp_ajax_wcs_import_request', array( &$this, 'ajax_request_handler' ) );
	}

	/**
	 *
	 * @since 1.0
	 */
	public function add_import_tool() {
		register_importer( 'woocommerce_subscription_csv', 'WooCommerce Subscriptions (CSV)', __( 'Import <strong>subscriptions</strong> to your WooCommerce store via a CSV file.', 'wcs-importer' ), array( &$this, 'admin_page' ) );
	}

	/**
	 * Add menu item under Woocommerce > Subscription CSV Importer
	 *
	 * @since 1.0
	 */
	public function add_sub_menu() {
		add_submenu_page( 'woocommerce', __( 'Subscription Importer', 'wcs-importer' ),  __( 'Subscription Importer', 'wcs-importer' ), 'manage_options', 'import_subscription', array( &$this, 'admin_page' ) );
	}

	/**
	 *
	 * @since 1.0
	 */
	public function enqueue_scripts() {

		if ( isset( $_GET['page'] ) && 'import_subscription' == $_GET['page'] ) {

			wp_enqueue_style( 'wcs-importer-admin', plugin_dir_url( WC_Subscription_Importer::$plugin_file ) . '/css/wcs-importer.css' );

			if ( isset( $_GET['step'] ) && 3 == absint( $_GET['step'] )  ) {

				wp_enqueue_script( 'wcs-importer-admin', plugin_dir_url( WC_Subscription_Importer::$plugin_file ) . '/js/wcs-importer.js' );

				$file_id = absint( $_GET['file_id'] );
				$file    = get_attached_file( $_GET['file_id'] );
				$enc     = mb_detect_encoding( $file, 'UTF-8, ISO-8859-1', true );

				if ( $enc ) {
					setlocale( LC_ALL, 'en_US.' . $enc );
				}

				@ini_set( 'auto_detect_line_endings', true );

				$file_positions = $row_start = array();
				$payment_method_error = $payment_meta_error = array();

				$count = 0;
				$total = 0;
				$previous_pos = 0;
				$position = 0;
				$row_start[] = 1;

				$mapped_fields = get_post_meta( $file_id, '_mapped_rules', true );

				if ( ( $handle = fopen( $file, "r" ) ) !== FALSE ) {
					$row = $raw_headers = array();

					$header = fgetcsv( $handle, 0 );
					while ( ( $postmeta = fgetcsv( $handle, 0 ) ) !== FALSE ) {
						$count++;
						foreach ( $header as $key => $heading ) {
							if ( ! $heading ) continue;
							$s_heading = strtolower( $heading );
							$row[$s_heading] = ( isset( $postmeta[$key] ) ) ? WCS_Import_Parser::format_data_from_csv( $postmeta[$key], $enc ) : '';
						}

						// Checks the row for missing required payment meta
						$this->check_row_payment_meta( $row, $mapped_fields, $payment_method_error, $payment_meta_error );

						if ( $count >= $this->rows_per_request ) {
							$previous_pos = $position;
							$position = ftell( $handle );
							$row_start[] = end( $row_start ) + $count;
							reset( $row_start );
							$count = 0;
							$total++;
							// Import rows between $previous_position $position
							$file_positions[] = $previous_pos;
							$file_positions[] = $position;
						}
					}

					// Account for the remainder
					if ( $count > 0 ) {
						$total++;
						$file_positions[] = $position;
						$file_positions[] = ftell( $handle );
					}
					fclose( $handle );
				}

				if ( count( $payment_method_error ) > 0 ) {
					$error_message = sprintf( __( "You're importing subscriptions for %s without specifying %s . This will create subscriptions that use the manual renewal process, not the automatic process. Are you sure you want to do this?", 'wcs-importer' ), str_replace( '"', ' ', json_encode( array_unique( $payment_method_error ) ) ), str_replace( '"', ' ', json_encode( array_unique( $payment_meta_error ) ) ) );
				} else {
					$error_message = '';
				}

				$script_data = array(
					'success' 				=> __( 'success', 'wcs-importer' ),
					'failed' 				=> __( 'failed', 'wcs-importer' ),
					'error_string'			=> sprintf( __( "Row #%s from CSV %sfailed to import%s with error/s: %s", 'wcs-importer' ), '{row_number}', '<strong>', '</strong>', '{error_messages}' ),
					'finished_importing' 	=> __( 'Finished Importing', 'wcs-importer' ),
					'edit_order' 			=> __( 'Edit Order', 'wcs-importer' ),
					'warning'				=> __( 'Warning', 'wcs-importer' ),
					'warnings'				=> __( 'Warnings', 'wcs-importer' ),
					'located_at'			=> __( 'Located at rows', 'wcs-importer' ),
					'error_message'         => $error_message,

					// Data for procesing the file
					'file_id'          => absint( $_GET['file_id'] ),
					'file_positions'   => $file_positions,
					'start_row_num'    => $row_start,
					'ajax_url'         => admin_url( 'admin-ajax.php' ),
					'rows_per_request' => $this->rows_per_request,
					'test_mode'        =>( 'yes' == $_GET['test_mode'] ) ? "true" : "false",
					'email_customer'   =>( 'yes' == $_GET['email_customer'] ) ? "true" : "false",
					'cancelled_url'    => add_query_arg( 'cancelled', 'true', $this->admin_url ),
					'total'            => $total,
				);

				wp_localize_script( 'wcs-importer-admin', 'wcs_script_data', $script_data );
			}
		}
	}

	/* From the list of supported payment gateways, check if there's a row in the csv
	 * that is missing crucial data needed for importing. Add missing information to
	 * both &method_error and &meta_error arrays.
	 * 
	 * @since 1.0
	 */
	function check_row_payment_meta( $row, $mapped_fields, &$method_error, &$meta_error ) {
		$has_missing_meta = false;
		$supported_gateways = WCS_Import_Parser::$supported_payment_gateways;
		$payment_method = ( ! empty ( $row[$mapped_fields['payment_method']] ) ) ? strtolower( $row[$mapped_fields['payment_method']] ) : '';

		if( ! empty( $payment_method ) && array_key_exists( $payment_method, $supported_gateways ) ) {
			foreach( $supported_gateways[$payment_method] as $meta_data ) {
				if ( empty ( $row[$mapped_fields[$meta_data]] ) ) {
					$has_missing_meta = true;
					$meta_error[] = $meta_data;
				}
			}
			if( $has_missing_meta ) {
				$method_error[] = $row[$mapped_fields['payment_method']];
			}
		} else {
			// unsupported value for payment gateway
		}
	}

	/**
	 * Displays header followed by the current pages content
	 *
	 * @since 1.0
	 */
	public function admin_page() {

		echo '<div class="wrap">';
		echo '<h2>' . __( 'Subscription CSV Importer', 'wcs-importer' ) . '</h2>';
		if ( ! isset( $_GET['step'] ) || isset( $_GET['cancelled'] ) ) :
		?>
		<div id="message" class="updated woocommerce-message wc-connect">
			<?php if ( isset( $_GET['cancelled'] ) ) : ?>
			<div id="message" class="updated error">
				<p><?php _e( 'Import cancelled.', 'wcs-importer' ); ?></p>
			</div>
			<?php endif; ?>
			<?php if ( ! isset( $_GET['step'] ) ) : ?>
			<div class="squeezer">
				<h4><?php _e( '<strong>Before you begin</strong>, please prepare your CSV file.', 'wcs-importer' ); ?></h4>
				<p class="submit">
					<a href="http://docs.woothemes.com/document/subscriptions-importer/" class="button-primary"><?php _e( 'Documentation', 'wcs-importer' ); ?></a>
					<a href="<?php echo plugins_url( 'wcs-import-sample.csv', WC_Subscription_Importer::$plugin_file ); ?>" class="button wcs-importer-download"><?php _e( 'Download Example CSV', 'wcs-importer' ); ?></a>
				</p>
			</div>
			<?php endif; ?>
		</div>
		<?php
		endif;

		$page = ( isset( $_GET['step'] ) ) ? $_GET['step'] : 1;

		switch( $page ) {
			case 1 : //Step: Upload File
				$this->upload_page();
				break;
			case 2 : // check mapping
				$this->mapping_page();
				break;
			case 3 :
				$this->import_page();
				break;
			default : //default to home page
				$this->upload_page();
				break;
		}

		echo '</div>';
	}

	/**
	 * Initial plugin page. Prompts the admin to upload the CSV file containing subscription details.
	 *
	 * @since 1.0
	 */
	private function upload_page() {

		$upload_dir = wp_upload_dir();

		// Set defaults for admin flags
		$test_mode      = ( isset( $_POST['test_mode'] ) ) ? $_POST['test_mode'] : 'yes';
		$email_customer = ( isset( $_POST['email_customer'] ) ) ? $_POST['email_customer'] : 'no';

		if ( ! empty( $this->upload_error ) ) : ?>
	<div id="message" class="error">
		<p><?php printf( __( 'Error uploading file: %s', 'wcs-importer' ), $this->upload_error ); ?></p>
	</div>
		<?php endif;

		echo '<h3>' . __( 'Step 1: Upload CSV File', 'wcs-importer' ) . '</h3>';
		if ( ! empty( $upload_dir['error'] ) ) : ?>
			<div class="error"><p><?php _e( 'Before you can upload your import file, you will need to fix the following error:' ); ?></p>
			<p><strong><?php echo $upload_dir['error']; ?></strong></p></div><?php
		else :
			?>
			<p><?php _e( 'Upload a CSV file containing details about your subscriptions to bring across to your store with WooCommerce.', 'wcs-importer' ); ?></p>
			<p><?php _e( 'Choose a CSV (.csv) file to upload, then click Upload file and import.', 'wcs-importer' ); ?></p>
			<form enctype="multipart/form-data" id="import-upload-form" method="post" action="<?php echo esc_attr( $this->admin_url ); ?>">
				<?php wp_nonce_field( 'import-upload' ); ?>
				<table class="form-table">
					<tbody>
						<tr>
							<th>
								<label for="upload"><?php _e( 'Choose a file:' ); ?></label>
							</th>
							<td>
								<input type="file" id="upload" name="import" size="25" />
								<input type="hidden" name="action" value="upload_file" />
								<small><?php printf( __( 'Maximum size: %s' ), size_format( apply_filters( 'import_upload_size_limit', wp_max_upload_size() ) ) ); ?></small>
							</td>
						</tr>
						<tr>
							<th><?php _e( 'Run in Test Mode', 'wcs-importer' ); ?>:</th>
							<td>
								<input type="checkbox" name="test_mode" value="yes" <?php checked( $test_mode, 'yes' ); ?> />
								<em><?php _e( 'Check your CSV file for errors and warnings without creating subscriptions, users or orders.', 'wcs-importer' ); ?></em>
							</td>
						</tr>
						<tr>
							<th><?php _e( 'Email passwords?', 'wcs-importer' ); ?></th>
							<td>
								<input type="checkbox" name="email_customer" value="yes" <?php checked( $email_customer, 'yes' ); ?> />
								<em><?php _e( 'If importing new users, you can email customers their account details.', 'wcs-importer' ); ?></em>
							</td>
						</tr>
					</tbody>
				</table>
				<p class="submit">
					<input type="submit" class="button" value="<?php esc_attr_e( 'Upload file and import' ); ?>" />
				</p>
			</form>
			<?php
		endif;
	}

	/**
	 * Step 2: Once uploaded file is recognised, the admin will be required to map CSV columns to the required fields.
	 *
	 * @since 1.0
	 */
	private function mapping_page() {

		$file_id = absint( $_GET['file_id'] );

		$file = get_attached_file( $file_id );

		if ( $file ) {

			$enc = mb_detect_encoding( $file, 'UTF-8, ISO-8859-1', true );

			if ( $enc ) {
				setlocale( LC_ALL, 'en_US.' . $enc );
			}

			@ini_set( 'auto_detect_line_endings', true );

			// Get headers
			if ( ( $handle = fopen( $file, "r" ) ) !== FALSE ) {
				$row = array();

				$column_headers = fgetcsv( $handle, 0 );
				while ( ( $postmeta = fgetcsv( $handle, 0 ) ) !== false ) {
					foreach ( $column_headers as $key => $column_header ) {
						if ( ! $column_header ) continue;
						$column_header = strtolower( $column_header );
						$row[ $column_header ] = ( isset( $postmeta[ $key ] ) ) ? WCS_Import_Parser::format_data_from_csv( $postmeta[ $key ], $enc ) : '';
					}
					break;
				}
				fclose( $handle );
			}
		}

		$url_params = array(
			'step'           => '3',
			'file_id'        => $file_id,
			'test_mode'      => $_GET['test_mode'],
			'email_customer' => $_GET['email_customer'],
		);
		$action = add_query_arg( $url_params, $this->admin_url );

		$button_text = ( 'yes' == $_GET['test_mode'] ) ? __( 'Test CSV', 'wcs-importer' ) : __( 'Run Import', 'wcs-importer' );

		$row_number = 1;
		?>
		<h3><?php _e( 'Step 2: Map Fields to Column Names', 'wcs-importer' ); ?></h3>
		<form method="post" action="<?php echo esc_attr( $action ); ?>">
			<?php wp_nonce_field( 'import-upload' ); ?>
			<input type="hidden" name="action" value="field_mapping" />
			<table class="widefat widefat_importer">
				<thead>
					<tr>
						<th><?php _e( 'Map to', 'wcs-importer' ); ?></th>
						<th><?php _e( 'Column Header', 'wcs-importer' ); ?></th>
						<th><?php _e( 'Example Column Value', 'wcs-importer' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach( $row as $header => $sample ) : ?>
					<tr <?php echo ( ++$row_number % 2 ) ? '' : 'class="alternate"'; ?>>
						<td> <!-- Available mapping options -->
							<select name="mapto[<?php echo $header; ?>]">
								<option value="0"><?php _e( 'Do not import', 'wcs-importer' ); ?></option>
								<option value="custom_user_meta">custom_user_meta</option>
								<option value="custom_order_meta">custom_order_meta</option>
								<option value='custom_user_order_meta'>custom_user_order_meta</option>
								<optgroup label="<?php _e( 'Customer Fields', 'wcs-importer'); ?>">
									<option value="customer_id" <?php selected( $header, 'customer_id' ); ?>>customer_id</option>
									<option value="customer_email" <?php selected( $header, 'customer_email' ); ?>>customer_email</option>
									<option value="customer_username" <?php selected( $header, 'customer_username' ); ?>>customer_username</option>
									<option value="customer_password" <?php selected( $header, 'customer_password' ); ?>>customer_password</option>
									<option value="billing_first_name" <?php selected( $header, 'billing_first_name' ); ?>>billing_first_name</option>
									<option value="billing_last_name" <?php selected( $header, 'billing_last_name' ); ?>>billing_last_name</option>
									<option value="billing_address_1" <?php selected( $header, 'billing_address_1' ); ?>>billing_address_1</option>
									<option value="billing_address_2" <?php selected( $header, 'billing_address_2' ); ?>>billing_address_2</option>
									<option value="billing_city" <?php selected( $header, 'billing_city' ); ?>>billing_city</option>
									<option value="billing_state" <?php selected( $header, 'billing_state' ); ?>>billing_state</option>
									<option value="billing_postcode" <?php selected( $header, 'billing_postcode' ); ?>>billing_postcode</option>
									<option value="billing_country" <?php selected( $header, 'billing_country' ); ?>>billing_country</option>
									<option value="billing_email" <?php selected( $header, 'billing_email' ); ?>>billing_email</option>
									<option value="billing_phone" <?php selected( $header, 'billing_phone' ); ?>>billing_phone</option>
									<option value="billing_company" <?php selected( $header, 'billing_company' ); ?>>billing_company</option>
									<option value="shipping_first_name" <?php selected( $header, 'shipping_first_name' ); ?>>shipping_first_name</option>
									<option value="shipping_last_name" <?php selected( $header, 'shipping_last_name' ); ?>>shipping_last_name</option>
									<option value="shipping_address_1" <?php selected( $header, 'shipping_address_1' ); ?>>shipping_address_1</option>
									<option value="shipping_address_2" <?php selected( $header, 'shipping_address_2' ); ?>>shipping_address_2</option>
									<option value="shipping_city" <?php selected( $header, 'shipping_city' ); ?>>shipping_city</option>
									<option value="shipping_state" <?php selected( $header, 'shipping_state' ); ?>>shipping_state</option>
									<option value="shipping_postcode" <?php selected( $header, 'shipping_postcode' ); ?>>shipping_postcode</option>
									<option value="shipping_country" <?php selected( $header, 'shipping_country' ); ?>>shipping_country</option>
								</optgroup>
								<optgroup label="<?php _e( 'Order Fields', 'wcs-importer' ); ?>">
									<option value="recurring_line_total" <?php selected( $header, 'recurring_line_total' ); ?>>recurring_line_total</option>
									<option value="recurring_line_tax" <?php selected( $header, 'recurring_line_tax' ); ?>>recurring_line_tax</option>
									<option value="recurring_line_subtotal" <?php selected( $header, 'recurring_line_subtotal' ); ?>>recurring_line_subtotal</option>
									<option value="recurring_line_subtotal_tax" <?php selected( $header, 'recurring_line_subtotal_tax' ); ?>>recurring_line_subtotal_tax</option>
									<option value="line_total" <?php selected( $header, 'line_total' ); ?>>line_total</option>
									<option value="line_tax" <?php selected( $header, 'line_tax' ); ?>>line_tax</option>
									<option value="line_subtotal" <?php selected( $header, 'line_subtotal' ); ?>>line_subtotal</option>
									<option value="line_subtotal_tax" <?php selected( $header, 'line_subtotal_tax' ); ?>>line_subtotal_tax</option>
									<option value="order_discount" <?php selected( $header, 'order_discount' ); ?>>order_discount</option>
									<option value="cart_discount" <?php selected( $header, 'cart_discount' ); ?>>cart_discount</option>
									<option value="order_shipping_tax" <?php selected( $header, 'order_shipping_tax' ); ?>>order_shipping_tax</option>
									<option value="order_shipping" <?php selected( $header, 'order_shipping' ); ?>>order_shipping</option>
									<option value="order_tax" <?php selected( $header, 'order_tax' ); ?>>order_tax</option>
									<option value="order_total" <?php selected( $header, 'order_total' ); ?>>order_total</option>
									<option value="payment_method" <?php selected( $header, 'payment_method' ); ?>>payment_method</option>
									<option value="payment_method_title" <?php selected( $header, 'payment_method_title' ); ?>>payment_method_title</option>
									<option value="shipping_method" <?php selected( $header, 'shipping_method' ); ?>>shipping_method</option>
									<option value="shipping_method_title" <?php selected( $header, 'shipping_method_title' ); ?>>shipping_method_title</option>
									<option value="stripe_customer_id" <?php selected( $header, 'stripe_customer_id' ); ?>>stripe_customer_id</option>
									<option value="paypal_subscriber_id" <?php selected( $header, 'paypal_subscriber_id' ); ?>>paypal_subscriber_id</option>
									<option value="wc_authorize_net_cim_payment_profile_id" <?php selected( $header, 'wc_authorize_net_cim_payment_profile_id' ); ?>>wc_authorize_net_cim_payment_profile_id</option>
									<option value="wc_authorize_net_cim_customer_profile_id" <?php selected( $header, 'wc_authorize_net_cim_customer_profile_id' ); ?>>wc_authorize_net_cim_customer_profile_id</option>
									<option value="download_permission_granted" <?php selected( $header, 'download_permission_granted' ); ?>>download_permission_granted</option>
								</optgroup>
								<optgroup label="<?php _e( 'Subscription Status', 'wcs-importer' ); ?>">
									<option value="subscription_status" <?php selected( $header, 'subscription_status' ); ?>>subscription_status</option>
									<option value="subscription_start_date" <?php selected( $header, 'subscription_start_date' ); ?>>subscription_start_date</option>
									<option value="subscription_expiry_date" <?php selected( $header, 'subscription_expiry_date' ); ?>>subscription_expiry_date</option>
									<option value="subscription_end_date" <?php selected( $header, 'subscription_end_date' ); ?>>subscription_end_date</option>
									<option value="product_id" <?php selected( $header, 'product_id' ); ?>>product_id</option>
								</optgroup>
							</select>
						</td>
						<td width="25%"><?php echo $header; ?></td> <!-- Column deader from csv file -->
						<td><code><?php echo ( ! empty( $sample ) ) ? esc_html( $sample ) : '-'; ?></code></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="submit">
				<input type="submit" class="button" value="<?php echo esc_attr( $button_text ); ?>" />
			</p>
		</form>
		<?php
	}

	/**
	 * Shows information dependant on whether $_POST['test-mode'] is set or not.
	 * If set, the admin is provided with a list of critical errors and non-critical warnings
	 * @since 1.0
	 */
	private function import_page() {

		if ( 'yes' == $_GET['test_mode'] ):

			$url_params = array(
				'step'           => '3',
				'file_id'        => $_GET['file_id'],
				'test_mode'      => 'no',
				'email_customer' => $_GET['email_customer'],
			);

			$action = add_query_arg( $url_params, $this->admin_url );
			?>
			<h3><?php _e( 'Test Run Results', 'wcs-importer' ); ?></h3>
			<table id="wcs-import-progress" class="widefat_importer widefat">
				<thead>
					<tr>
						<th class="row" colspan="2"><?php _e( 'Importer Test Results', 'wcs-importer' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr class="alternate">
						<th><strong><?php _e( 'Results', 'wcs-importer' ); ?></strong></th>
						<td id="wcs-importer_test_results"><strong><?php echo sprintf( __( '%s0%s tests passed, %s0%s tests failed ( %s0%s of the CSV will be imported ).', 'wcs-importer' ), '<span id="wcs-test-passed">', '</span>', '<span id="wcs-test-failed">', '</span>', '<span id="wcs-test-ratio">', '</span>%' ); ?></strong></td>
					</tr>
					<tr>
						<th><strong><?php _e( 'Details', 'wcs-importer' ); ?></strong></th>
						<td id="wcs-importer_test_details"><strong><?php echo sprintf( __( '%s0%s fatal errors and %s0%s warnings found.', 'wcs-importer' ), '<span id="wcs-fatal-details">', '</span>', '<span id="wcs-warning-details">', '</span>' ); ?></strong></td>
					</tr>
					<tr class="alternate" id="wcs-importer_test_errors"><th><?php _e( 'Error Messages', 'wcs-importer' ); ?>:</th><td></td></tr>
					<tr id="wcs-importer_test_warnings"><th><?php _e( 'Warnings', 'wcs-importer' ); ?>:</th><td></td></tr>
				</tbody>
			</table>
			<div id="wcs-completed-message" style="display: none;">
				<p><?php _e( 'Test Finished!', 'wcs-importer' );?></p>
				<a class="button" href="<?php echo esc_attr( wp_nonce_url( $action, 'import-upload' ) ); ?> "><?php _e( 'Run Import' , 'wcs-importer' ); ?></a>
			</div>
		<?php else : ?>
			<h3><?php _e( 'Importing Results', 'wcs-importer' ); ?></h3>
			<p id="wcs-import-timeout" style="display: none;"><?php echo sprintf( __( 'ERROR: The importing process has timed out. Please check the CSV is correct and do a test run before importing by enabling the checkbox on the Importer Home screen. %s Start Over. %s', 'wcs-importer' ), '<a href="' . $this->admin_url . '">', '</a>' ); ?></p>
			<p id="wcs-import-time-completion"><?php echo sprintf( __( 'Total Estimated Import Time Between:  %s 0%s minutes. ( %s0%s Completed! )', 'wcs-importer'), '<span id="wcs-estimated-time">', '</span>', '<span id="wcs-completed-percent">', '%</span>' ); ?></p>
			<table id="wcs-import-progress" class="widefat_importer widefat">
				<thead>
					<tr>
						<th class="row"><?php _e( 'Import Status', 'wcs-importer' ); ?></th>
						<th class="row"><?php _e( 'Order #', 'wcs-importer' ); ?></th>
						<th class="row"><?php _e( 'Subscription', 'wcs-importer' ); ?></th>
						<th class="row"><?php _e( 'User Name', 'wcs-importer' ); ?></th>
						<th class="row"><?php _e( 'Subscription Status', 'wcs-importer' ); ?></th>
						<th class="row"><?php _e( 'Number of Warnings', 'wcs-importer' ); ?></th>
					</tr>
				</thead>
				<tfoot>
					<tr class="importer-loading">
						<td colspan="6"></td>
					</tr>
				</tfoot>
				<tbody></tbody>
			</table>
			<p id="wcs-completed-message" style="display: none;">
				<?php printf( __( 'Import Complete! %sView Subscriptions%s, %sView Orders%s or %sImport another file%s.', 'wcs-importer' ), '<a href="' . admin_url( 'admin.php?page=subscriptions' ) . '">', '</a>', '<a href="' . admin_url( 'edit.php?post_type=shop_order' ) . '">', '</a>', '<a href="' . $this->admin_url . '">', '</a>' ); ?>
			</p>
		<?php endif;
	}

	/**
	 * Checks the mapping provides enough information to continue importing subscriptions
	 *
	 * @since 1.0
	 */
	function save_mapping() {

		// Possible mapping options
		$mapped_fields = array(
			'custom_user_meta'							=> array(),
			'custom_order_meta'							=> array(),
			'custom_user_order_meta'					=> array(),
			'product_id'						   		=> '',
			'customer_id' 						   		=> '',
			'customer_email' 					   		=> '',
			'customer_username' 				   		=> '',
			'customer_password'					   		=> '',
			'billing_first_name' 				   		=> '',
			'billing_last_name' 				   		=> '',
			'billing_address_1' 				   		=> '',
			'billing_address_2' 				   		=> '',
			'billing_city' 						   		=> '',
			'billing_state' 					   		=> '',
			'billing_postcode' 					   		=> '',
			'billing_country' 					   		=> '',
			'billing_email' 					   		=> '',
			'billing_phone' 					   		=> '',
			'billing_company'					   		=> '',
			'shipping_first_name' 				   		=> '',
			'shipping_last_name' 				   		=> '',
			'shipping_company' 					   		=> '',
			'shipping_address_1' 				   		=> '',
			'shipping_address_2' 				   		=> '',
			'shipping_city' 					   		=> '',
			'shipping_state' 					   		=> '',
			'shipping_postcode' 				   		=> '',
			'shipping_country' 					   		=> '',
			'subscription_status'				   		=> '',
			'subscription_start_date'			   		=> '',
			'subscription_trial_expiry_date'	   		=> '',
			'subscription_expiry_date'			   		=> '',
			'subscription_end_date'				   		=> '',
			'payment_method' 					   		=> '',
			'shipping_method' 					   		=> '',
			'shipping_method_title'				   		=> '',
			'recurring_line_total' 				   		=> '',
			'recurring_line_tax' 				   		=> '',
			'recurring_line_subtotal' 			   		=> '',
			'recurring_line_subtotal_tax'		   		=> '',
			'line_total' 						   		=> '',
			'line_tax' 							   		=> '',
			'line_subtotal' 					   		=> '',
			'line_subtotal_tax' 				   		=> '',
			'order_discount' 					   		=> '',
			'cart_discount' 					   		=> '',
			'order_shipping_tax' 				   		=> '',
			'order_shipping'					   		=> '',
			'order_tax'							   		=> '',
			'order_total' 						 		=> '',
			'order_recurring_total'				 		=> '',
			'stripe_customer_id'				  		=> '',
			'paypal_subscriber_id'				  		=> '',
			'payment_method_title'						=> '',
			'wc_authorize_net_cim_payment_profile_id' 	=> '',
			'wc_authorize_net_cim_customer_profile_id' 	=> '',
			'download_permission_granted'		   		=> '',
		);

		$mapping_rules = $_POST['mapto'];

		// Doesnt yet handle multiple fields mapped to the same field
		foreach( $mapped_fields as $key => $value) {
			if ( $key != 'custom_user_meta' && $key != 'custom_order_meta' && $key != 'custom_user_order_meta') {
				$m_key = array_search( $key, $mapping_rules );
				if ( $m_key ) {
					$mapped_fields[$key] = $m_key;
				}
			}
		}

		// Add the custom post type to their associated arrays in $mapped_fields
		foreach( $mapping_rules as $key => $value ) {
			if( $value == 'custom_user_meta' || $value == 'custom_order_meta' || $value == 'custom_user_order_meta' ) {
				array_push( $mapped_fields[$value], $key );
			}
		}
		update_post_meta( $_GET['file_id'], '_mapped_rules', $mapped_fields );
	}

	/**
	 * Displays header followed by the current pages content
	 *
	 * @since 1.0
	 */
	public function post_request_handler() {

		if ( isset( $_GET['page'] ) && 'import_subscription' == $_GET['page'] && isset( $_POST['action'] ) ) {

			check_admin_referer( 'import-upload' );

			$next_step_url_params = array(
				'file_id'        => isset( $_GET['file_id'] ) ? $_GET['file_id'] : 0,
				'test_mode'      => isset( $_REQUEST['test_mode'] ) ? $_REQUEST['test_mode'] : 'no',
				'email_customer' => isset( $_REQUEST['email_customer'] ) ? $_REQUEST['email_customer'] : 'no',
			);

			if ( 'upload_file' == $_POST['action'] ) {

				$file = wp_import_handle_upload();

				if ( isset( $file['error'] ) ) {

					$this->upload_error = $file['error'];

				} else { // Successful upload, let's move onto the next step

					$next_step_url_params['step'] = '2';
					$next_step_url_params['file_id'] = $file['id'];
					wp_redirect( add_query_arg( $next_step_url_params, $this->admin_url ) );
					exit;

				}

			} elseif ( 'field_mapping' == $_POST['action'] ) {

				$this->save_mapping();

				$next_step_url_params['step'] = 3;

				wp_redirect( add_query_arg( $next_step_url_params, $this->admin_url ) );

				exit();
			}
		}
	}

	/**
	 * AJAX request holding the file and mapping information is sent to this function.
	 *
	 * @since 1.0
	 */
	function ajax_request_handler() {
		if ( ! current_user_can( 'manage_woocommerce' ) ){
			wp_die( "Cheatin' huh?");
		}

		@set_time_limit(0);

		// Requests to admin-ajax.php use the front-end memory limit, we want to use the admin (i.e. max) memory limit
		@ini_set( 'memory_limit', apply_filters( 'admin_memory_limit', WP_MAX_MEMORY_LIMIT ) );

		if( isset( $_POST['file_id'] ) && isset( $_POST['row_num'] ) ) {
			$file_path          = get_attached_file( absint( $_POST['file_id'] ) );
			$mapped_fields      = get_post_meta( absint( $_POST['file_id'] ), '_mapped_rules', true );
			$file_pointer_start = ( isset( $_POST['start'] ) ) ? absint( $_POST['start'] ) : 0;
			$file_pointer_end   = ( isset( $_POST['end'] ) ) ? absint( $_POST['end'] ) : 0;
			$starting_row_num   = absint( $_POST['row_num'] );
			$test_mode          = isset( $_POST['test_mode'] ) ? $_POST['test_mode'] : false;
			$email_customer     = isset( $_POST['email_customer'] ) ? $_POST['email_customer'] : false;
			$results = WCS_Import_Parser::import_data( $file_path, $mapped_fields, $file_pointer_start, $file_pointer_end, $starting_row_num, $test_mode, $email_customer );

			header( 'Content-Type: application/json; charset=utf-8' );
			echo json_encode( $results );
		}
		exit; // End
	}
}
?>