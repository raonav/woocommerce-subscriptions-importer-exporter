<?php 

class WCS_Import_Parser {
	static $mapped_fields = array();
	static $results = array();
	static $result = array();
	static $file_pointer_start_position;
	static $file_pointer_end_position;
	static $starting_row_number;
	static $test_mode;
	static $email_customer;

	static $order_meta_fields = array(
		"order_shipping",
		"order_shipping_tax",
		"order_tax",
		"cart_discount",
		"order_discount",
		"order_total",
		"order_recurring_total",
		"payment_method",
		"shipping_method",
		"customer_user",
		"billing_first_name",
		"billing_last_name",
		"billing_company",
		"billing_address_1",
		"billing_address_2",
		"billing_city",
		"billing_state",
		"billing_postcode",
		"billing_country",
		"billing_email",
		"billing_phone",
		"shipping_first_name",
		"shipping_last_name",
		"shipping_company",
		"shipping_address_1",
		"shipping_address_2",
		"shipping_city",
		"shipping_state",
		"shipping_postcode",
		"shipping_country",
	);

	// User meta data
	static $user_data_titles = array (
		"billing_first_name",
		"billing_last_name",
		"billing_company",
		"billing_address_1",
		"billing_address_2",
		"billing_city",
		"billing_state",
		"billing_postcode",
		"billing_country",
		"billing_email",
		"billing_phone",
		"shipping_first_name",
		"shipping_last_name",
		"shipping_company",
		"shipping_address_1",
		"shipping_address_2",
		"shipping_city",
		"shipping_state",
		"shipping_postcode",
		"shipping_country",
	);

	static $order_item_meta_fields = array (
		"recurring_line_total",
		"recurring_line_tax",
		"recurring_line_subtotal",
		"recurring_line_subtotal_tax",
		"line_subtotal",
		"line_total",
		"line_tax",
		"line_subtotal_tax",
	);

	static $supported_payment_gateways = array (
		'paypal'            => array( 'paypal_subscriber_id' ),
		'stripe'            => array( 'stripe_customer_id' ),
		'authorize.net'     => array( 'wc_authorize_net_cim_customer_profile_id', 'wc_authorize_net_cim_payment_profile_id' ),
	);

	/**
	 *
	 * @since 1.0
	 */
	public static function import_data( $file_path, $mapped_fields, $file_pointer_start_position, $file_pointer_end_position, $starting_row_num, $test_mode, $email_customer ) {
		$file_path = addslashes( $file_path );
		self::$mapped_fields = $mapped_fields;
		self::$file_pointer_start_position = $file_pointer_start_position;
		self::$file_pointer_end_position = $file_pointer_end_position;
		self::$starting_row_number = $starting_row_num;
		self::$test_mode = ( $test_mode == 'true' ) ? true : false;
		self::$email_customer = ( $email_customer == 'true' ) ? true : false;
		self::import_start( $file_path );
		return self::$results;
	}

	/**
	 * Loads the csv file contents into the class variable self::$file
	 *
	 * @since 1.0
	 */
	public static function import_start( $file_path ) {
		$file_encoding = mb_detect_encoding( $file_path, 'UTF-8, ISO-8859-1', true );
		if ( $file_encoding ) setlocale( LC_ALL, 'en_US.' . $file_encoding );
		@ini_set( 'auto_detect_line_endings', true );

		if ( $file_path ) {
			if ( ( $file_handle = fopen( $file_path, "r" ) ) !== FALSE ) {
				$subscription_details = array();
				$column_headers = fgetcsv( $file_handle, 0 );

				if ( self::$file_pointer_start_position != 0 ) {
					fseek( $file_handle, self::$file_pointer_start_position );
				}

				while ( ( $csv_row = fgetcsv( $file_handle, 0 ) ) !== false ) {

					foreach ( $column_headers as $key => $header ) {
						if ( ! $header ) {
							continue;
						}
						$header = strtolower( $header );
						$subscription_details[ $header ] = ( isset( $csv_row[ $key ] ) ) ? trim( self::format_data_from_csv( $csv_row[ $key ], $file_encoding ) ) : '';
					}

					// will move to just sending $subscription_details instead of listing all these variables
					self::$starting_row_number++;

					self::import_subscription( $subscription_details );
					if( ftell( $file_handle ) >= self::$file_pointer_end_position ) {
						break;
					}
				}
				fclose( $file_handle );
			}
		}
	}

	/**
	 *
	 * @since 1.0
	 */
	public static function format_data_from_csv( $data, $file_encoding ) {
		return ( $file_encoding == 'UTF-8' ) ? $data : utf8_encode( $data );
	}

	/**
	 * Import Subscription
	 *
	 * @since 1.0
	 */
	private static function import_subscription( $subscription_details ) {
		$download_permissions_granted = false;
		$order_meta = array();
		$result['warning'] = $result['error'] = array();

		// Check Product id a woocommerce product
		if ( empty( self::$mapped_fields['product_id'] ) || ! ( self::check_product( $subscription_details[self::$mapped_fields['product_id']] ) ) ) {
			$result['error'][] = __( 'The product_id is not a subscription product in your store.', 'wcs-importer' );
		}

		// Check customer id is valid or create one
		$user_id = self::check_customer( $subscription_details );
		if ( empty( $user_id ) ) {
			$result['error'][] = __( 'An error occurred with the customer information provided.', ' wcs_import' );
		} elseif ( is_wp_error( $user_id ) ) {
			$result['error'][] = $user_id->get_error_message();
		} elseif ( ! self::$test_mode ) {
			$customer = get_user_by( 'id', $user_id );
			$result['user_id'] = $customer->ID;
			$result['username'] = $customer->user_login;
			$result['edit_user_link'] = sprintf( '<a href="%s">#%s</a>', get_edit_user_link( $user_id ), $user_id );
		}

		// Skip importing rows without the required information
		if ( ! empty( $result['error'] ) ) {
			$result['status'] = 'failed';
			$result['row_number'] = self::$starting_row_number;
			array_push( self::$results, $result );
			return;
		}

		$_product = get_product( $subscription_details[self::$mapped_fields['product_id']] );
		$result['item'] = $_product->get_title();

		$missing_shipping_addresses = $missing_billing_addresses = array();

		// Populate order meta data
		foreach( self::$order_meta_fields as $column ) {
			switch( $column ) {
				case 'shipping_method':
					$method = ( ! empty( $subscription_details[self::$mapped_fields['shipping_method']] ) ) ? $subscription_details[self::$mapped_fields['shipping_method']] : '';
					$title = ( ! empty( $subscription_details[self::$mapped_fields['shipping_method_title']] ) ) ? $subscription_details[self::$mapped_fields['shipping_method_title']] : '';
					$order_meta[] = array( 'key' => '_' . $column, 'value' => $method );
					$order_meta[] = array( 'key' => '_shipping_method_title', 'value' => $title );
					$order_meta[] = array( 'key' => '_recurring_shipping_method', 'value' => $method );
					$order_meta[] = array( 'key' => '_recurring_shipping_method_title', 'value' => $title );
					if( empty( $method ) || empty( $title ) ) {
						// set up warning message to show admin -  Do i need be more specific??
						$result['warning'][] = __( 'Shipping method and/or title for the order has been set to empty. ', 'wcs-importer' );
					}
					break;
				case 'order_shipping':
					$value = ( ! empty( $subscription_details[self::$mapped_fields[$column]] ) ) ? $subscription_details[self::$mapped_fields[$column]] : '';
					$order_meta[] = array( 'key' => '_' . $column, 'value' => $value );
					$order_meta[] = array( 'key' => '_order_recurring_shipping_total', 'value' => $value );
					break;
				case 'order_shipping_tax':
					$value = ( ! empty( $subscription_details[self::$mapped_fields[$column]] ) ) ? $subscription_details[self::$mapped_fields[$column]] : '';
					$order_meta[] = array( 'key' => '_' . $column, 'value' => $value );
					$order_meta[] = array( 'key' => '_order_recurring_shipping_tax_total', 'value' => $value );
					break;
				case 'payment_method':
					$payment_method = ( ! empty( $subscription_details[self::$mapped_fields[$column]] ) ) ? strtolower( $subscription_details[self::$mapped_fields[$column]] ) : '';
					$title = ( ! empty( $subscription_details[self::$mapped_fields['payment_method_title']] ) ) ? $subscription_details[self::$mapped_fields['payment_method_title']] : $payment_method;
					$use_manual_recurring = false;
					$tmp_ordermeta = array();

					if ( ! empty( $payment_method ) && array_key_exists( $payment_method, self::$supported_payment_gateways ) ) {
						$tmp_ordermeta[] = array( 'key' => '_' . $column, 'value' => $payment_method );
						$tmp_ordermeta[] = array( 'key' => '_payment_method_title', 'value' => $title );
						$tmp_ordermeta[] = array( 'key' => '_recurring_payment_method', 'value' => $payment_method );
						$tmp_ordermeta[] = array( 'key' => '_recurring_payment_method_title', 'value' => $title );
						foreach( self::$supported_payment_gateways[$payment_method] as $meta_data ) {
							if( ! empty ( $subscription_details[self::$mapped_fields[$meta_data]] ) ) {
								$meta_value = $subscription_details[self::$mapped_fields[$meta_data]];
								$tmp_ordermeta[] = array( 'key' => '_' . $meta_data, 'value' => $meta_value );
							} else {
								$use_manual_recurring = true;
							}
						}
					} else {
						$use_manual_recurring = true;
					}

					if( $use_manual_recurring ) {
						$order_meta[] = array( 'key' => '_wcs_requires_manual_renewal', 'value' => 'true' );
						$result['warning'][] = __( 'No recognisable payment method has been specified. Defaulting to manual recurring payments. ', 'wcs-importer' );
					} else {
						foreach ( $tmp_ordermeta as $tmp_meta ) {
							$order_meta[] = array( 'key' => $tmp_meta['key'], 'value' => $tmp_meta['value'] );
						}

						// After all the information has been checked, if the payment method is authorize.net, add the extra user_meta information
						if( $payment_method == 'authorize.net' ) {
							$profile_id = ( ! empty ( $subscription_details[self::$mapped_fields['wc_authorize_net_cim_customer_profile_id']] ) ) ? $subscription_details[self::$mapped_fields['wc_authorize_net_cim_customer_profile_id']] : '';
							update_user_meta( $user_id, '_wc_authorize_net_cim_profile_id', $profile_id );
						}
					}
					break;
				case 'shipping_address_1':
				case 'shipping_city':
				case 'shipping_postcode':
				case 'shipping_state':
				case 'shipping_country':
					$value = ( ! empty( $subscription_details[self::$mapped_fields[$column]] ) ) ? $subscription_details[self::$mapped_fields[$column]] : '';
					if( empty( $value ) ) {
						$metadata = get_user_meta( $user_id, $column );
						$value = ( ! empty( $metadata[0] ) ) ? $metadata[0] : '';
					}
					if( empty( $value ) ) {
						$missing_shipping_addresses[] = $column;
					}
					$order_meta[] = array( 'key' => '_' . $column, 'value' => $value );
					break;
				case 'billing_addresss_1':
				case 'billing_city':
				case 'billing_postcode':
				case 'billing_state':
				case 'billing_country':
					$value = ( ! empty( $subscription_details[self::$mapped_fields[$column]] ) ) ? $subscription_details[self::$mapped_fields[$column]] : '';
					if( empty( $value ) ) {
						$metadata = get_user_meta( $user_id, $column );
						$value = ( ! empty( $metadata[0] ) ) ? $metadata[0] : '';
					}
					if( empty( $value ) ) {
						$missing_billing_addresses[] = $column;
					}
					$order_meta[] = array( 'key' => '_' . $column, 'value' => $value );
					break;
				case 'customer_user':
					$order_meta[] = array( 'key' => '_' . $column, 'value' => $user_id );
					break;
				case 'order_total':
					$trial_length = WC_Subscriptions_Product::get_trial_length( $_product );
					$sign_up_fee  = WC_Subscriptions_Product::get_sign_up_fee( $_product );
					if ( ! empty( $subscription_details[self::$mapped_fields[$column]] ) ) {
						$value = $subscription_details[self::$mapped_fields[$column]];
					} elseif ( $trial_length > 0 ) {
						$value = $sign_up_fee;
					} else {
						$value = $sign_up_fee + $_product->subscription_price;
					}
					$order_meta[] = array( 'key' => '_' . $column, 'value' => $value );
					break;
				case 'order_recurring_total':
					$value = ( ! empty( $subscription_details[self::$mapped_fields[$column]] ) ) ? $subscription_details[self::$mapped_fields[$column]] : $_product->subscription_price;
					$order_meta[] = array( 'key' => '_' . $column, 'value' => $value );
					break;
				case 'order_discount':
					$value = ( ! empty( $subscription_details[self::$mapped_fields[$column]] ) ) ? $subscription_details[self::$mapped_fields[$column]] : '';
					$order_meta[] = array( 'key' => '_' . $column, 'value' => $value );
					$order_meta[] = array( 'key' => '_order_recurring_discount_total', 'value' => $value );
					break;
				case 'cart_discount':
					$value = ( ! empty( $subscription_details[self::$mapped_fields[$column]] ) ) ? $subscription_details[self::$mapped_fields[$column]] : '';
					$order_meta[] = array( 'key' => '_' . $column, 'value' => $value );
					$order_meta[] = array( 'key' => '_order_recurring_discount_cart', 'value' => $value );
					break;
				case 'order_tax':
					$value = ( ! empty( $subscription_details[self::$mapped_fields[$column]] ) ) ? $subscription_details[self::$mapped_fields[$column]] : '';
					$order_meta[] = array( 'key' => '_' . $column, 'value' => $value );
					$order_meta[] = array( 'key' => '_order_recurring_tax_total', 'value' => $value );
					break;
				default:
					$value = ( ! empty( $subscription_details[self::$mapped_fields[$column]] ) ) ? $subscription_details[self::$mapped_fields[$column]] : '';
					if( empty( $value ) ) {
						$metadata = get_user_meta( $user_id, $column );
						$value = ( ! empty( $metadata[0] ) ) ? $metadata[0] : '';
					}
					$order_meta[] = array( 'key' => '_' . $column, 'value' => $value );
			}
		}
		if( ! empty( $missing_shipping_addresses ) ) {
			$result['warning'][] = __( 'The following shipping address fields have been left empty: ' . rtrim( implode( ', ', $missing_shipping_addresses ), ',' ) . '. ', 'wcs-importer' );
		}
		if ( ! empty( $missing_billing_addresses ) ) {
			$result['warning'][] = __( 'The following billing address fields have been left empty: ' . rtrim( implode( ', ', $missing_billing_addresses ), ',' ) . '. ', 'wcs-importer' );
		}

		// Check and set download permissions boolean, will run in test-mode
		$download_permission = ( ! empty ( $subscription_details[self::$mapped_fields['download_permission_granted']] ) ) ? strtolower( $subscription_details[self::$mapped_fields['download_permission_granted']] ) : '';
		if( strcmp( $download_permission, 'true' ) == 0 || strcmp( $download_permission, 'yes' ) == 0 ) {
			$download_permissions_granted = true;
			if( get_option( 'woocommerce_downloads_grant_access_after_payment' ) == 'no' ) {
				$result['warning'][] = __( 'Download permissions cannot be granted because your current WooCommerce settings have disabled this feature.', 'wcs-importer' );
				$download_permissions_granted = false;
			}
		}

		// Skip this section when testing the importer for errors and/or warnings
		if( ! self::$test_mode ) {

			$start_date     = ( ! empty( $subscription_details[self::$mapped_fields['subscription_start_date']] ) ) ? get_date_from_gmt( date( 'Y-m-d H:i:s', strtotime( $subscription_details[self::$mapped_fields['subscription_start_date']] ) ) ) : current_time( 'mysql' );
			$start_date_gmt = ( ! empty( $subscription_details[self::$mapped_fields['subscription_start_date']] ) ) ? date( 'Y-m-d H:i:s', strtotime( $subscription_details[self::$mapped_fields['subscription_start_date']] ) ) : current_time( 'mysql', 1 );

			$order_data = array(
					'post_date'     => $start_date,
					'post_date_gmt' => $start_date_gmt,
					'post_type'     => 'shop_order',
					'post_title' 	=> sprintf( __( 'Order &ndash; %s', 'wcs-importer' ), strftime( _x( '%b %d, %Y @ %I:%M %p', 'Order date parsed by strftime', 'wcs-importer' ) ) ),
					'post_status'   => 'publish',
					'ping_status'   => 'closed',
					'post_author'   => 1,
					'post_password' => uniqid( 'order_' ),  // Protects the post just in case
			);

			$order_id = wp_insert_post( $order_data );
			$subscription_key = WC_Subscriptions_Manager::get_subscription_key( $order_id, $_product->id );
			foreach ( $order_meta as $meta ) {
				update_post_meta( $order_id, $meta['key'], $meta['value'] );
				if ( '_customer_user' == $meta['key'] && $meta['value'] ) {
					update_user_meta( $meta['value'], 'paying_customer', 1 );
				}
			}

			// Add in the custom post meta to order with $order_id
			foreach( self::$mapped_fields['custom_order_meta'] as $post_meta ) {
				update_post_meta( $order_id, $post_meta, $subscription_details[$post_meta] );
			}

			// Add custom user meta to the current user attached to the user
			foreach( self::$mapped_fields['custom_user_meta'] as $user_meta ) {
				update_user_meta( $user_id, $user_meta, $subscription_details[$user_meta] );
			}

			// Add the data listed as custom_post and custom
			foreach( self::$mapped_fields['custom_user_order_meta'] as $user_order_meta ) {
				update_user_meta( $user_id, $user_order_meta, $subscription_details[$user_order_meta] );
				update_post_meta( $order_id, $user_order_meta, $subscription_details[$user_order_meta] );
			}

			$order = new WC_Order( $order_id );

			remove_action( 'woocommerce_order_status_pending_to_completed_notification', array( WC()->mailer()->emails['WC_Email_New_Order'], 'trigger' ) );
			remove_action( 'woocommerce_order_status_completed_notification', array( WC()->mailer()->emails['WC_Email_Customer_Completed_Order'], 'trigger' ) );

			$order->update_status( 'completed' );

			add_action( 'woocommerce_order_status_pending_to_completed_notification', array( WC()->mailer()->emails['WC_Email_New_Order'], 'trigger' ) );
			add_action( 'woocommerce_order_status_completed_notification', array( WC()->mailer()->emails['WC_Email_Customer_Completed_Order'], 'trigger' ) );

			// Add line item
			$item_id = wc_add_order_item( $order_id, array(
				'order_item_name' => $_product->get_title(),
				'order_item_type' => 'line_item'
			) );

			// Add line item meta
			if ( $item_id ) {
				wc_add_order_item_meta( $item_id, '_qty', apply_filters( 'woocommerce_stock_amount', 1 ) );
				wc_add_order_item_meta( $item_id, '_tax_class', $_product->get_tax_class() );
				wc_add_order_item_meta( $item_id, '_product_id', $_product->id );
				wc_add_order_item_meta( $item_id, '_variation_id', ( ! empty ( $_product->variation_id ) ) ? $_product->variation_id : '');

				// Store variation data in meta so admin can view it
				if ( isset( $_product->variation_data ) && is_array( $_product->variation_data ) && ! empty( $_product->variation_data ) ) {
					foreach ( $_product->variation_data as $variation_key => $variation_value ) {
						$variation_key = str_replace( 'attribute_', '', $variation_key );
						wc_add_order_item_meta( $item_id, $variation_key, $variation_value );
					}
				}

				// Add line item meta for backorder status
				if ( $_product->backorders_require_notification() && $_product->is_on_backorder( 1 ) ) {
					wc_add_order_item_meta( $item_id, apply_filters( 'woocommerce_backordered_item_meta_name', __( 'Backordered', 'woocommerce' ), $cart_item_key, $order_id ), $values['quantity'] - max( 0, $_product->get_total_stock() ) );
				}

				// add download permissions if specified
				if( $download_permissions_granted ) {
					wc_downloadable_product_permissions( $order_id );
				}

				// Update the subscription meta data with values in $subscription_meta
				$subscription_meta = array (
						'start_date'         => $start_date_gmt,
						'trial_expiry_date'  => ( ! empty( $subscription_details[self::$mapped_fields['subscription_trial_expiry_date']] ) ) ? date( 'Y-m-d H:i:s', strtotime( $subscription_details[self::$mapped_fields['subscription_trial_expiry_date']] ) ) : WC_Subscriptions_Product::get_trial_expiration_date( $_product, $start_date_gmt ),
						'expiry_date'        => ( ! empty( $subscription_details[self::$mapped_fields['subscription_expiry_date']] ) ) ? date( 'Y-m-d H:i:s', strtotime( $subscription_details[self::$mapped_fields['subscription_expiry_date']] ) ) : WC_Subscriptions_Product::get_expiration_date( $_product, $start_date_gmt ),
						'end_date'           => ( ! empty( $subscription_details[self::$mapped_fields['subscription_end_date']] ) ) ? date( 'Y-m-d H:i:s', strtotime( $subscription_details[self::$mapped_fields['subscription_end_date']] ) ) : '',
						'status'             => 'pending',
						'completed_payments' => array( $start_date_gmt ),
				);

				// Record the payment at the end of the trial (if any) to make sure next payment date is calculated correctly
				if ( ! empty ( $subscription_meta['trial_expiry_date'] ) && strtotime( $subscription_meta['trial_expiry_date'] ) <= gmdate( 'U' ) ) {
					$subscription_meta['completed_payments'][] = $subscription_meta['trial_expiry_date'];
				}

				// Make sure that the expiration date is not after a specified end date
				if ( ! empty( $subscription_meta['end_date'] ) && strtotime( $subscription_meta['expiry_date'] ) >= strtotime( $subscription_meta['end_date'] ) ) {
					$subscription_meta['expiry_date'] = $subscription_meta['end_date'];
				}

				$_POST['order_id'] = $order_id;

				WC_Subscriptions_Order::prefill_order_item_meta( array( 'product_id' => $_product->id, 'variation_id' => $_product->variation_id ), $item_id );

				//Update the recurring subscription meta data
				foreach( self::$order_item_meta_fields as $metadata ) {
					switch ( $metadata ) {
						case 'line_subtotal' :
						case 'line_total' :
						case 'recurring_line_total' :
						case 'recurring_line_subtotal' :
							$prod_id = ( $_product->variation_id ) ? $_product->variation_id : $_product->id;
							$default = WC_Subscriptions_Product::get_price( $prod_id );
							break;
						default :
							$default = 0;
					}
					$value = ( ! empty( $subscription_details[self::$mapped_fields[$metadata]] ) ) ? $subscription_details[self::$mapped_fields[$metadata]] : $default;
					wc_update_order_item_meta( $item_id, '_' . $metadata, $value );
				}

				// set the _subscription_length if expiry_date given in CSV
				if ( ! empty( $subscription_details[self::$mapped_fields['subscription_expiry_date']] ) ) {
					$find_length_start = ( ! empty( $subscription_meta['trial_expiry_date'] ) ) ? strtotime( $subscription_meta['trial_expiry_date'] ) : strtotime( $subscription_meta['start_date'] );
					$period = WC_Subscriptions_Order::get_subscription_period( $order_id );
					$new_sub_length = self::calculate_sub_length( $find_length_start, strtotime( $subscription_meta['expiry_date'] ), $period );

					if( $new_sub_length != -1 ) {
						wc_update_order_item_meta( $item_id, '_subscription_length', $new_sub_length );
					} else { // set subscription as expired and display warning to admin
						$subscription_meta['status'] = 'expired';
						$result['warning'][] = __( 'Subscription set as expired due to the expiry date given in the CSV being invalid.', 'wcs-importer' );

					}
				}

				// Update the status of the subscription if not already set to expired
				if( $subscription_meta['status'] != 'expired' ) {
					if( ! empty( self::$mapped_fields['subscription_status'] ) && $subscription_details[self::$mapped_fields['subscription_status']] ) {
						$subscription_meta['status'] = strtolower( $subscription_details[self::$mapped_fields['subscription_status']] );
					} else {
						$subscription_meta['status'] = 'pending';
						$result['warning'][] = __( 'No subscription status was specified. Subscription has been created with the status "pending". ', 'wcs-importer' );
					}
				}

				// Add additional subscription meta data
				WC_Subscriptions_Manager::update_subscription( $subscription_key, $subscription_meta );

				// For active subscriptions, make sure payment and expiration dates are set correctly
				if ( ! empty( self::$mapped_fields['subscription_status'] ) && 'active' == $subscription_details[self::$mapped_fields['subscription_status']]  && $subscription_meta['status'] != 'expired' ) {

					// We also need to manually schedule the trial expiration date due to data duplication issues with Subscriptions
					if ( ! empty ( $subscription_meta['trial_expiry_date'] ) && strtotime( $subscription_meta['trial_expiry_date'] ) > gmdate( 'U' ) ) {
						WC_Subscriptions_Manager::set_trial_expiration_date( $subscription_key, '', $subscription_meta['trial_expiry_date'] );
					}

					$from_date = $start_date_gmt;

					do {
						$from_date = $next_payment_timestamp = WC_Subscriptions_Manager::calculate_next_payment_date( $subscription_key, $user_id, 'timestamp', $from_date );
					} while ( $next_payment_timestamp < gmdate( 'U' ) && $next_payment_timestamp != 0 );

					if ( $next_payment_timestamp != 0 ) {
						WC_Subscriptions_Manager::set_next_payment_date( $subscription_key, '', $next_payment_timestamp );
					}

					if ( ! empty ( $subscription_meta['expiry_date'] ) ) {
						if ( strtotime( $subscription_meta['expiry_date'] ) <= gmdate( 'U' ) && empty( $subscription_meta['end_date'] ) ) {
							WC_Subscriptions_Manager::update_users_subscriptions_for_order( $order_id, 'expired' );
						} else {
							// We also need to manually schedule the expiration date due to data duplication issues with Subscriptions
							WC_Subscriptions_Manager::set_expiration_date( $subscription_key, '', $subscription_meta['expiry_date'] );
						}
					}
				}
			}

			// Check if the subscription has been successfully added
			$subscription = WC_Subscriptions_Manager::get_subscription( $subscription_key );

			// Successfully added subscription. Attach information on each order to the results array.
			if( ! empty ( $subscription['order_id'] ) && ! empty ( $subscription['product_id'] ) ) {
				$result['status'] = 'success';
				$result['order_id'] = $subscription['order_id'];
				$result['edit_order_link'] = sprintf( '<a href="%s">#%s</a>', get_edit_post_link( $order_id ), $order_id );
				$result['subscription_status'] = $subscription['status'];
				$result['item_id'] = ( ! empty ( $subscription['variation_id'] ) ) ? $subscription['variation_id'] : $subscription['product_id'];
				$result['edit_post_link'] = sprintf( '<a href="%s">#%s</a>', get_edit_post_link( $subscription['product_id'] ), $result['item_id'] );
				array_push( self::$results, $result );
			} else {
				$result['status'] = 'failed';
				array_push( self::$results, $result );
			}
		} else {
			if( empty( self::$mapped_fields['subscription_status'] ) && empty( $subscription_details[self::$mapped_fields['subscription_status']] ) ) {
				$result['warning'][] = __( 'No subscription status was specified. Subscription will be created with the status "pending". ', 'wcs-importer' );
			}
			$result['row_number'] = self::$starting_row_number;
			array_push( self::$results, $result );
		}
	}

	/**
	 * Check the product is a woocommerce subscription - an error status will show up on table if this is not the case.
	 *
	 * @since 1.0
	 */
	public static function check_product( $product_id ) {
		$is_subscription = WC_Subscriptions_Product::is_subscription( $product_id );
		return ( empty( $is_subscription ) ) ? false : true;
	}

	/**
	 * Checks customer information and creates a new store customer when no customer Id has been given
	 *
	 * @since 1.0
	 */
	public static function check_customer( $subscription_details ) {
		$customer_email = ( ! empty ( $subscription_details[ self::$mapped_fields['customer_email'] ] ) ) ? $subscription_details[ self::$mapped_fields['customer_email'] ] : '';
		$username       = ( ! empty ( $subscription_details[ self::$mapped_fields['customer_username'] ] ) ) ? $subscription_details[ self::$mapped_fields['customer_username'] ] : '';
		$customer_id    = ( ! empty( $subscription_details[ self::$mapped_fields['customer_id'] ] ) ) ? $subscription_details[ self::$mapped_fields['customer_id'] ] : '';

		if ( ! empty( $subscription_details[ self::$mapped_fields['customer_password'] ] ) ) {
			$password = $subscription_details[ self::$mapped_fields['customer_password'] ];
			$password_generated = false;
		} else {
			$password = wp_generate_password( 12, true );
			$password_generated = true;
		}

		$found_customer = false;

		if ( empty( $customer_id ) ) {

			// check for registered email if customer id is not set
			if ( is_email( $customer_email ) ) {
				$found_customer = email_exists( $customer_email );
			}

			// if customer still not found, check by username
			if ( ! $found_customer && ! empty( $username ) ) {
				$found_customer = username_exists( $username );
			}

			if ( ! $found_customer && is_email( $customer_email ) ) {

				// In test mode, we just want to know if a user account can be created - as we have a valid email address, it can be.
				if ( self::$test_mode ) {

					$found_customer = true;

				} else {

					// Not in test mode, create a user account for this email
					if ( empty( $username ) ) {
						$maybe_username = explode( '@', $customer_email );
						$maybe_username = sanitize_user( $maybe_username[0] );
						$counter = 1;
						$username = $maybe_username;
						while ( username_exists( $username ) ) {
							$username = $maybe_username . $counter;
							$counter++;
						}
					}

					$found_customer = wp_create_user( $username, $password, $customer_email );

					if ( ! is_wp_error( $found_customer ) ) {

						// update user meta data
						foreach( self::$user_data_titles as $key ) {
							switch( $key ) {
								case 'billing_email':
									// user billing email if set in csv otherwise use the user's account email
									$meta_value = ( ! empty( $subscription_details[self::$mapped_fields[ $key ]] ) ) ? $subscription_details[self::$mapped_fields[ $key ]] : $customer_email;
									update_user_meta( $found_customer, $key, $meta_value );
									break;
								case 'billing_first_name':
									$meta_value = ( ! empty( $subscription_details[self::$mapped_fields[ $key ]] ) ) ? $subscription_details[self::$mapped_fields[ $key ]] : $username;
									update_user_meta( $found_customer, $key, $meta_value );
									update_user_meta( $found_customer, 'first_name', $meta_value );
									break;
								case 'billing_last_name':
									$meta_value = ( ! empty( $subscription_details[self::$mapped_fields[ $key ]] ) ) ? $subscription_details[self::$mapped_fields[ $key ]] : '';
									update_user_meta( $found_customer, $key, $meta_value );
									update_user_meta( $found_customer, 'last_name', $meta_value );
									break;
								case 'shipping_first_name':
								case 'shipping_last_name':
								case 'shipping_address_1':
								case 'shipping_address_2':
								case 'shipping_city':
								case 'shipping_postcode':
								case 'shipping_state':
								case 'shipping_country':
									// Set the shipping address fields to match the billing fields if not specified in CSV
									$meta_value = ( ! empty( $subscription_details[self::$mapped_fields[ $key ]] ) ) ? $subscription_details[self::$mapped_fields[ $key ]] : '';
									if( empty ( $meta_value ) ) {
										$n_key = str_replace( "shipping", "billing", $key );
										$meta_value = ( ! empty( $subscription_details[ self::$mapped_fields[$n_key]] ) ) ? $subscription_details[self::$mapped_fields[$n_key]] : '';
									}
									update_user_meta( $found_customer, $key, $meta_value );
									break;
								default:
									$meta_value = ( ! empty( $subscription_details[self::$mapped_fields[ $key ]] ) ) ? $subscription_details[self::$mapped_fields[ $key ]] : '';
									update_user_meta( $found_customer, $key, $meta_value );
							}
						}

						// sets all new user's roles to the value set as default_inactive_role
						WC_Subscriptions_Manager::make_user_inactive( $found_customer );

						// send user registration email if admin as chosen to do so
						if( self::$email_customer && function_exists( 'wp_new_user_notification' ) ) {
							// keep the previous value of the option so the original value can be restored
							$previous_option  = get_option( 'woocommerce_registration_generate_password' );
							// force the option value so that the password will appear in the email
							update_option( 'woocommerce_registration_generate_password', 'yes' );
							do_action( 'woocommerce_created_customer', $found_customer, array( 'user_pass' => $password ), true );
							// restore the previous value stored in the option
							update_option( 'woocommerce_registration_generate_password', $previous_option );
						}
					}
				}
			}
			return $found_customer;
		} else {
			// check customer id
			$found_customer = get_user_by( 'id', $customer_id );

			if ( ! empty( $found_customer ) && ! is_wp_error( $found_customer ) ) {
				return absint( $customer_id );
			}

			return $found_customer; // should be false
		}
	}

	/* Calculates the number of payments between the start_date and expiry_date
	 * based on the period (i.e. Day, Month etc); return 0 if expiry_date is not in the future.
	 * @since 1.0
	 */
	public static function calculate_sub_length( $start_date, $expiry_date, $period ) {
		// check expiry date is in the future
		if( $expiry_date <= $start_date ) {
			return -1;
		}

		// $days = the number of days between start and expiry dates
		$days = ceil( ( $expiry_date - $start_date ) / ( 60 * 60 * 24 ) );
		switch( $period ) {
			case 'day' :
				return $days;
			case 'week' :
				return floor( $days / 7 );
			case 'month' :
				return floor( $days / 30 );
			case 'year' :
				return floor( $days / 365 );
		}
	}
}
?>