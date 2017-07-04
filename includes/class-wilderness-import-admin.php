<?php
/**
 * WooCommerce Subscriptions Import Admin class
 *
 * @since 1.0
 */
class Wilderness_Import_Admin {

	public $import_results = array();
	public $upload_error   = '';

	public function __construct() {

		$this->admin_url = admin_url( 'admin.php?page=import_subscription' );
		$this->rows_per_request = ( defined( 'IMPORT_ROWS_PER_REQUEST' ) ) ? IMPORT_ROWS_PER_REQUEST : 20;

		add_action( 'admin_init', array( &$this, 'post_request_handler' ) );

		add_action( 'admin_menu', array( &$this, 'add_sub_menu' ), 10 );

		add_action( 'admin_enqueue_scripts', array( &$this, 'enqueue_scripts' ) );

		add_action( 'wp_ajax_wcs_import_request', array( &$this, 'ajax_request_handler' ) );
	}

	public function add_sub_menu() {
		add_submenu_page( 'woocommerce', __( 'Subscription Importer', 'wilderness-import' ),  __( 'Subscription Importer', 'wilderness-import' ), 'manage_woocommerce', 'import_subscription', array( &$this, 'admin_page' ) );
	}

	public function enqueue_scripts() {

		if ( isset( $_GET['page'] ) && 'import_subscription' == $_GET['page'] ) {

			wp_enqueue_style( 'wilderness-importer', Wilderness_Importer::plugin_url() . 'assets/css/wcs-importer.css' );

			if ( isset( $_GET['step'] ) && 3 == absint( $_GET['step'] )  ) {

				wp_enqueue_script( 'wilderness-importer', Wilderness_Importer::plugin_url() . 'assets/js/wcs-importer.js' );

				$file_id = absint( $_GET['file_id'] );
				$file    = get_attached_file( $_GET['file_id'] );
				$enc     = mb_detect_encoding( $file, 'UTF-8, ISO-8859-1', true );

				if ( $enc ) {
					setlocale( LC_ALL, 'en_US.' . $enc );
				}

				@ini_set( 'auto_detect_line_endings', true );

				$file_positions = $row_start = array();

				$count        = 0;
				$total        = 0;
				$previous_pos = 0;
				$position     = 0;
				$row_start[]  = 1;

				if ( ( $handle = fopen( $file, 'r' ) ) !== false ) {
					$row       = $raw_headers = array();

					$header = fgetcsv( $handle, 0 );
					while ( ( $postmeta = fgetcsv( $handle, 0 ) ) !== false ) {
						$count++;

						foreach ( $header as $key => $heading ) {

							if ( ! $heading ) {
								continue;
							}

							$s_heading         = strtolower( $heading );
							$row[ $s_heading ] = ( isset( $postmeta[ $key ] ) ) ? wcsi_format_data( $postmeta[ $key ], $enc ) : '';
						}

						if ( $count >= $this->rows_per_request ) {
							$previous_pos = $position;
							$position     = ftell( $handle );
							$row_start[]  = end( $row_start ) + $count;

							reset( $row_start );

							$count = 0;
							$total++;

							$file_positions[] = $previous_pos;
							$file_positions[] = $position;
						}
					}

					if ( $count > 0 ) {
						$total++;
						$file_positions[] = $position;
						$file_positions[] = ftell( $handle );
					}

					fclose( $handle );
				}

				$script_data = array(
					'success' 				=> esc_html__( 'success', 'wilderness-import' ),
					'failed' 				=> esc_html__( 'failed', 'wilderness-import' ),
					'error_string'			=> esc_html( sprintf( __( 'Row #%1$s from CSV %2$sfailed to import%3$s with error/s: %4$s', 'wcs-import-export' ), '{row_number}', '<strong>', '</strong>', '{error_messages}' ) ),
					'finished_importing' 	=> esc_html__( 'Finished Importing', 'wcs-import-export' ),
					'edit_order' 			=> esc_html__( 'Edit Order', 'wcs-import-export' ),
					'warning'				=> esc_html__( 'Warning', 'wcs-import-export' ),
					'warnings'				=> esc_html__( 'Warnings', 'wcs-import-export' ),
					'error'					=> esc_html__( 'Error', 'wcs-import-export' ),
					'errors'				=> esc_html__( 'Errors', 'wcs-import-export' ),
					'located_at'			=> esc_html__( 'Located at rows', 'wcs-import-export' ),

					// Data for procesing the file
					'file_id'          => absint( $_GET['file_id'] ),
					'file_positions'   => $file_positions,
					'start_row_num'    => $row_start,
					'ajax_url'         => admin_url( 'admin-ajax.php' ),
					'rows_per_request' => $this->rows_per_request,
					'email_customer'   => ( 'yes' == $_GET['email_customer'] ) ? 'true' : 'false',
					'add_memberships'  => ( 'yes' == $_GET['add_memberships'] ) ? 'true' : 'false',
					'total'            => $total,
					'import_wpnonce'   => wp_create_nonce( 'process-import' ),
				);

				wp_localize_script( 'wilderness-importer', 'wcsi_data', $script_data );
			}
		}
	}

	/**
	 * Displays header followed by the current pages content
	 *
	 * @since 1.0
	 */
	public function admin_page() {

		echo '<div class="wrap">';
		echo '<h2>' . esc_html__( 'Wilderness CSV Importer', 'wilderness-import' ) . '</h2>';

		if ( isset( $_GET['cancelled'] ) ) : ?>
			<div id="message" class="updated woocommerce-message wc-connect">
			<?php if ( isset( $_GET['cancelled'] ) ) : ?>
				<div id="message" class="updated error">
					<p><?php esc_html_e( 'Import cancelled.', 'wilderness-import' ); ?></p>
				</div>
			<?php endif; ?>
		    </div>
		<?php endif;

		$page = ( isset( $_GET['step'] ) ) ? $_GET['step'] : 1;

		switch ( $page ) {
			case 1 : //Step: Upload File
				$this->upload_page();
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

		if ( isset( $POST['wcsi_wpnonce'] ) ) {
			check_admin_referer( 'import-upload', 'wcsi_wpnonce' );
		}

		// Set defaults for admin flags
		$email_customer  = ( isset( $_POST['email_customer'] ) ) ? $_POST['email_customer'] : 'no';
		$add_memberships = ( isset( $_POST['add_memberships'] ) ) ? $_POST['add_memberships'] : 'no';

		if ( ! empty( $this->upload_error ) ) : ?>
			<div id="message" class="error">
				<p><?php printf( esc_html__( 'Error uploading file: %s', 'wilderness-import' ), wp_kses_post( $this->upload_error ) ); ?></p>
			</div>
		<?php endif; ?>

		<h3><?php esc_html_e( 'Step 1: Upload CSV File', 'wilderness-import' ); ?></h3>
		<?php if ( ! empty( $upload_dir['error'] ) ) : ?>
			<div class="error"><p><?php esc_html_e( 'Before you can upload your import file, you will need to fix the following error:', 'wilderness-import' ); ?></p>
			<p><strong><?php echo esc_html( $upload_dir['error'] ); ?></strong></p></div>
		<?php else : ?>
			<form enctype="multipart/form-data" id="import-upload-form" method="post" action="<?php echo esc_attr( $this->admin_url ); ?>">
				<?php wp_nonce_field( 'import-upload', 'wcsi_wpnonce' ); ?>
				<table class="form-table">
					<tbody>
						<tr>
							<th>
								<label for="upload"><?php esc_html_e( 'Choose a file:', 'wilderness-import' ); ?></label>
							</th>
							<td>
								<input type="file" id="upload" name="import" size="25" />
								<input type="hidden" name="action" value="upload_file" />
								<small><?php printf( esc_html__( 'Maximum size: %s', 'wilderness-import' ), wp_kses_post( size_format( apply_filters( 'import_upload_size_limit', wp_max_upload_size() ) ) ) ); ?></small>
							</td>
						</tr>
						<tr>
							<th><?php esc_html_e( 'Email Passwords:', 'wilderness-import' ); ?></th>
							<td>
								<input type="checkbox" name="email_customer" value="yes" <?php checked( $email_customer, 'yes' ); ?> />
								<em><?php esc_html_e( 'If importing new users, you can email customers their account details.', 'wilderness-import' ); ?></em>
							</td>
						</tr>
						<?php $is_memberships_active = get_option( 'wc_memberships_is_active', false ); ?>
						<?php if ( ! empty( $is_memberships_active ) && class_exists( 'WC_Memberships' ) ) : ?>
							<tr>
								<th><?php esc_html_e( 'EnableMemberships Import:', 'wilderness-import' ); ?></th>
								<td>
									<input type="checkbox" name="add_memberships" value="yes" <?php checked( $add_memberships, 'yes' ); ?> />
								</td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>
				<p class="submit">
					<input type="submit" class="button" value="<?php esc_attr_e( 'Upload file and import', 'wilderness-import' ); ?>" />
				</p>
			</form>
		<?php endif;
	}

	private function import_page() {
		include( Wilderness_Importer::plugin_dir() . 'templates/import-results.php' );
	}

	/**
	 * Displays header followed by the current pages content
	 *
	 * @since 1.0
	 */
	public function post_request_handler() {

		if ( isset( $_GET['page'] ) && 'import_subscription' == $_GET['page'] && isset( $_POST['action'] ) ) {

			check_admin_referer( 'import-upload', 'wcsi_wpnonce' );

			$next_step_url_params = array(
				'file_id'         => isset( $_GET['file_id'] ) ? $_GET['file_id'] : 0,
				'email_customer'  => isset( $_REQUEST['email_customer'] ) ? $_REQUEST['email_customer'] : 'no',
				'add_memberships' => isset( $_REQUEST['add_memberships'] ) ? $_REQUEST['add_memberships'] : 'no',
			);

			if ( 'upload_file' == $_POST['action'] ) {

				$file = wp_import_handle_upload();

				if ( isset( $file['error'] ) ) {

					$this->upload_error = $file['error'];

				} else {
					$next_step_url_params['step']    = 3;
					$next_step_url_params['file_id'] = $file['id'];

					wp_safe_redirect( add_query_arg( $next_step_url_params, $this->admin_url ) );
					exit;
				}
			}
		}
	}

	/**
	 * Process the AJAX request and import the subscription with the information gathered.
	 *
	 * @since 1.0
	 */
	public function ajax_request_handler() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( "Cheatin' huh?" );
		}

		check_ajax_referer( 'process-import', 'wcsie_wpnonce' );

		@set_time_limit( 0 );

		// Requests to admin-ajax.php use the front-end memory limit, we want to use the admin (i.e. max) memory limit
		@ini_set( 'memory_limit', apply_filters( 'admin_memory_limit', WP_MAX_MEMORY_LIMIT ) );

		if ( isset( $_POST['file_id'] ) && isset( $_POST['row_num'] ) ) {
			$results = Wilderness_Importer::import_data( array(
					'file_path'       => get_attached_file( absint( $_POST['file_id'] ) ),
					'mapped_fields'   => get_post_meta( absint( $_POST['file_id'] ), '_mapped_rules', true ),
					'file_start'      => ( isset( $_POST['start'] ) ) ? absint( $_POST['start'] ) : 0,
					'file_end'        => ( isset( $_POST['end'] ) ) ? absint( $_POST['end'] ) : 0,
					'starting_row'    => absint( $_POST['row_num'] ),
					'email_customer'  => isset( $_POST['email_customer'] ) ? $_POST['email_customer'] : false,
					'add_memberships' => isset( $_POST['add_memberships'] ) ? $_POST['add_memberships'] : false,
				)
			);

			header( 'Content-Type: application/json; charset=utf-8' );
			echo json_encode( $results );
		}

		exit;
	}
}
