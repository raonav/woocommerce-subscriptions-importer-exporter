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
		add_submenu_page( 'woocommerce','Subscription Importer','Subscription Importer','manage_woocommerce','import_subscription', array( &$this, 'admin_page' ));
	}

	public function enqueue_scripts() {

		if ( isset( $_GET['page'] ) && 'import_subscription' == $_GET['page'] ) {

			wp_enqueue_style( 'wilderness-importer', Wilderness_Importer_Main::plugin_url() . 'assets/wilderness-importer.css' );

			if ( isset( $_GET['step'] ) && 3 == absint( $_GET['step'] )  ) {

				wp_enqueue_script( 'wilderness-importer', Wilderness_Importer_Main::plugin_url() . 'assets/wilderness-importer.js' );

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

							$s_heading = strtolower( $heading );
							$row[ $s_heading ] = ( isset( $postmeta[ $key ] ) ) ? wildernessi_format_data( $postmeta[ $key ], $enc ) : '';
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
					'success' 				=> 'success',
					'failed' 				=> 'failed',
					'error_string'			=> sprintf('Row #%1$s from CSV %2$sfailed to import%3$s with error/s: %4$s', '{row_number}', '<strong>', '</strong>', '{error_messages}'),
					'finished_importing' 	=> 'Finished Importing',
					'edit_order' 			=> 'Edit Order',
					'warning'				=> 'Warning',
					'warnings'				=> 'Warnings',
					'error'					=> 'Error',
					'errors'				=> 'Errors',
					'located_at'			=> 'Located at rows',

					// Data for procesing the file
					'file_id'          => absint( $_GET['file_id'] ),
					'file_positions'   => $file_positions,
					'start_row_num'    => $row_start,
					'ajax_url'         => admin_url( 'admin-ajax.php' ),
					'rows_per_request' => $this->rows_per_request,
					'total'            => $total
				);

				wp_localize_script( 'wilderness-importer', 'wcsi_data', $script_data );
			}
		}
	}

	public function admin_page() {

		echo '<div class="wrap">';
		echo '<h2>CSV Importer</h2>';

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

	private function upload_page() {

		$upload_dir = wp_upload_dir();

		if (!empty($this->upload_error)) : ?>
			<div id="message" class="error">
				<p><?php printf('Error uploading file: %s', wp_kses_post( $this->upload_error ) ); ?></p>
			</div>
		<?php endif; ?>

		<h3>Upload CSV File</h3>
		<?php if (!empty($upload_dir['error'])) : ?>
			<div class="error"><p><?php esc_html_e( 'Before you can upload your import file, you will need to fix the following error:', 'wilderness-import' ); ?></p>
			<p><strong><?php echo esc_html( $upload_dir['error'] ); ?></strong></p></div>
		<?php else : ?>
			<form enctype="multipart/form-data" id="import-upload-form" method="post" action="<?php echo esc_attr( $this->admin_url ); ?>">
				<table class="form-table">
					<tbody>
						<tr>
							<th>
								<label for="upload"><?php esc_html_e( 'Choose a file:', 'wilderness-import' ); ?></label>
							</th>
							<td>
								<input type="file" id="upload" name="import" size="25" />
								<input type="hidden" name="action" value="upload_file" />
							</td>
						</tr>
					</tbody>
				</table>
				<p class="submit">
					<input type="submit" class="button" value="<?php esc_attr_e( 'Upload file and import', 'wilderness-import' ); ?>" />
				</p>
			</form>
		<?php endif;
	}

	private function import_page() {
		include( Wilderness_Importer_Main::plugin_dir() . 'templates/import-results.php' );
	}

	public function post_request_handler() {
        
        if ( isset( $_GET['page'] ) && 'import_subscription' == $_GET['page'] && isset( $_POST['action'] ) ) {

			$next_step_url_params = array('file_id' => isset( $_GET['file_id'] ) ? $_GET['file_id'] : 0);

			if ('upload_file' == $_POST['action']) {

				$file = wp_import_handle_upload();

				if (isset($file['error'])) {
					$this->upload_error = $file['error'];
				} else {
					$next_step_url_params['step'] = 3;
					$next_step_url_params['file_id'] = $file['id'];

					wp_safe_redirect(add_query_arg($next_step_url_params, $this->admin_url));
					exit;
				}
			}
		}
	}

    // submit form data as ajax request and receive json response
	public function ajax_request_handler() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( "You do not have the necessary permissions to execute this action" );
		}

		@set_time_limit( 0 );

		// Requests to admin-ajax.php use the front-end memory limit, we want to use the admin (i.e. max) memory limit
		@ini_set( 'memory_limit', apply_filters( 'admin_memory_limit', WP_MAX_MEMORY_LIMIT ) );

		if ( isset( $_POST['file_id'] ) && isset( $_POST['row_num'] ) ) {
			$results = Wilderness_Importer::import_data( array(
					'file_path'       => get_attached_file( absint( $_POST['file_id'] ) ),
					'file_start'      => ( isset( $_POST['start'] ) ) ? absint( $_POST['start'] ) : 0,
					'file_end'        => ( isset( $_POST['end'] ) ) ? absint( $_POST['end'] ) : 0,
					'starting_row'    => absint( $_POST['row_num'] ),
				)
			);
            header( 'Content-Type: application/json; charset=utf-8' );
			echo json_encode( $results );
		}
		exit;
	}
}
