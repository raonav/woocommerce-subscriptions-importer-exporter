<?php
class Wilderness_Importer {

	public static $results = array();

	/* The current row number of CSV */
	public static $row_number;

	public static $membership_plans = null;
	public static $all_virtual      = true;

	/* Specifically for the shutdown handler */
	public static $fields = array();
	public static $row    = array();

	/** Subscription post meta */
	public static $order_totals_fields = array(
		'order_shipping',
		'order_shipping_tax',
		'cart_discount',
		'cart_discount_tax',
		'order_total',
		'order_tax',
	);

	public static $user_meta_fields = array(
		'billing_first_name', // Billing Address Info
		'billing_last_name',
		'billing_company',
		'billing_address_1',
		'billing_address_2',
		'billing_city',
		'billing_state',
		'billing_postcode',
		'billing_country',
		'billing_email',
		'billing_phone',
		'shipping_first_name', // Shipping Address Info
		'shipping_last_name',
		'shipping_company',
		'shipping_address_1',
		'shipping_address_2',
		'shipping_city',
		'shipping_state',
		'shipping_postcode',
		'shipping_country',
	);

	public static function import_data($data) {

		$file_path = addslashes( $data['file_path'] );
		self::$row_number = $data['starting_row'];
        
        self::import_start( $file_path, $data['file_start'], $data['file_end'] );

		return self::$results;
	}

	public static function import_start( $file_path, $start_position, $end_position ) {

		$file_encoding = mb_detect_encoding( $file_path, 'UTF-8, ISO-8859-1', true );

		if ( $file_encoding ) {
			setlocale( LC_ALL, 'en_US.' . $file_encoding );
		}

		@ini_set( 'auto_detect_line_endings', true );

		if ( $file_path ) {
			if ( ( $file_handle = fopen( $file_path, 'r' ) ) !== false ) {
				$data = array();
				$column_headers = fgetcsv( $file_handle, 0 );

				if ( 0 != $start_position ) {
					fseek( $file_handle, $start_position );
				}

				while (($csv_row = fgetcsv($file_handle, 0)) !== false) {

					foreach ( $column_headers as $key => $header ) {
                        $header = wilderness_column_map($header);
						if (!$header) {
							continue;
						}
                        $csv_row[$key] = wilderness_process_field($header, $csv_row[$key]);
						$data[ $header ] = ( isset( $csv_row[ $key ] ) ) ? trim( wildernessi_format_data( $csv_row[ $key ], $file_encoding ) ) : '';
					}

					self::$row_number++;
                    $data = wilderness_add_missing_date($data);
					self::import_subscription($data);
                    
                    if (ftell($file_handle) >= $end_position) {
						break;
					}
				}
				fclose($file_handle);
			}
		}
	}

	public static function import_subscription($data) {
		global $wpdb;
        global $woocommerce;

		self::$row  = $data;
		$set_manual = $requires_manual_renewal = false;
		$post_meta  = array();
		$result     = array(
			'warning'    => array(),
			'error'      => array(),
			'items'      => '',
			'row_number' => self::$row_number,
		);

		$user_id = wilderness_check_customer($data);
        $result['username'] = sprintf('<a href="%s">%s</a>', get_edit_user_link( $user_id ), self::get_user_display_name( $user_id ));

		if ( is_wp_error( $user_id ) ) {
			$result['error'][] = $user_id->get_error_message();

		} elseif ( empty( $user_id ) ) {
			$result['error'][] = 'An error occurred with the customer information provided.';
		}

		if (!empty( $result['error'])){
			$result['status'] = 'failed';

			array_push( self::$results, $result );
			return;
		}

		$missing_shipping_addresses = $missing_billing_addresses = array();

		foreach ( array_merge( self::$order_totals_fields, self::$user_meta_fields, array( 'payment_method' ) ) as $column ) {
			switch ( $column ) {
				case 'cart_discount':
				case 'cart_discount_tax':
				case 'order_shipping':
				case 'order_shipping_tax':
				case 'order_total':
					$value = ( ! empty( $data[ $column ] ) ) ? $data[ $column ] : 0;
					$post_meta[] = array( 'key' => '_' . $column, 'value' => $value );
					break;

				case 'payment_method':
					$requires_manual_renewal = true;
					break;

				case 'shipping_address_1':
				case 'shipping_city':
				case 'shipping_postcode':
				case 'shipping_state':
				case 'shipping_country':
				case 'billing_address_1':
				case 'billing_city':
				case 'billing_postcode':
				case 'billing_state':
				case 'billing_country':
				case 'billing_phone':
				case 'billing_company':
				case 'billing_email':
					$value = (!empty($data[$column])) ? $data[$column] : '';

					if (empty($value)) {
						$metadata = get_user_meta( $user_id, $column );
						$value    = (!empty($metadata[0])) ? $metadata[0] : '';
					}

					if (empty($value) && 'billing_email' == $column) {
						$value = (!empty($data['customer_email'])) ? $data['customer_email'] : get_userdata($user_id)->user_email;
					}

					if ( empty( $value ) ) {
						$missing_shipping_addresses[] = $column;
					}

					$post_meta[] = array( 'key' => '_' . $column, 'value' => $value );
					break;

				default:
					$value = (!empty( $data[ $column ])) ? $data[$column] : '';
					$post_meta[] = array( 'key' => '_' . $column, 'value' => $value );
			}
		}

        // set subscription status to active
		$status = 'active';

        $dates_to_update = array(
            'start' => (!empty($data['start_date'])) ? gmdate('Y-m-d H:i:s', strtotime($data['start_date'])) : gmdate('Y-m-d H:i:s', time() - 1)
        );

		foreach (array( 'trial_end_date', 'next_payment_date', 'end_date', 'last_payment_date' ) as $date_type ) {
			$dates_to_update[$date_type] = (!empty($data[$date_type])) ? gmdate('Y-m-d H:i:s', strtotime($data[$date_type])) : '';
        }

		foreach ( $dates_to_update as $date_type => $datetime ) {

			if ( empty( $datetime ) ) {
				continue;
			}

			switch ( $date_type ) {
				case 'end_date' :
					if (!empty($dates_to_pdate['next_payment_date']) && strtotime($datetime) <= strtotime($dates_to_update['next_payment_date'])) {
						$result['error'][] = sprintf('The %s date must occur after the next payment date.', $date_type );
					}
				case 'next_payment_date' :
					if ( ! empty( $dates_to_update['trial_end_date'] ) && strtotime( $datetime ) < strtotime( $dates_to_update['trial_end_date'] ) ) {
						$result['error'][] = sprintf('The %s date must occur after the trial end date.', $date_type );
					}
				case 'trial_end_date' :
					if ( strtotime( $datetime ) <= strtotime( $dates_to_update['start'] ) ) {
						$result['error'][] = sprintf('The %s must occur after the start date.', $date_type );
					}
			}
		}

		// make the sure end of prepaid term exists for subscription that are about to be set to pending-cancellation - continue to use the next payment date if that exists
		if ((empty($dates_to_update['next_payment_date']) || strtotime($dates_to_update['next_payment_date']) < current_time('timestamp', true))){
			if ( !empty( $dates_to_update['end_date'] ) && strtotime( $dates_to_update['end_date'] ) > current_time( 'timestamp', true ) ) {
				$dates_to_update['next_payment_date'] = $dates_to_update['end_date'];
				unset( $dates_to_update['end_date'] );
			} else {
				$result['error'][] = 'Importing a pending cancelled subscription requires an end date in the future.';
			}
		}

        try {

            // before we create the subscription we want to get some further product related details
            $subperiod = strip_and_trim($data['subperiod']); //this is the equivalent of billing_period
            $subtype = strip_and_trim($data['subtype']); //this is the equivalent of billing_interval 
            $billing_period = wilderness_find_period($subperiod);
            $billing_interval = wilderness_find_interval($subtype);


            $wpdb->query('START TRANSACTION');

            $subscription = wcs_create_subscription(array(
                'customer_id'      => $user_id,
                'start_date'       => $dates_to_update['start'],
                'billing_interval' => $billing_interval, 
                'billing_period'   => $billing_period,
                'created_via'      => 'importer',
                'customer_note'    => (!empty($data['customer_note'])) ? $data['customer_note'] : '',
                'currency'         => (!empty($data['order_currency'])) ? $data['order_currency'] : '',
            ));

            if (is_wp_error($subscription)) {
                throw new Exception(sprintf('Could not create subscription: %s'), $subscription->get_error_message());
            }

            foreach ( $post_meta as $meta_data ) {
                update_post_meta( $subscription->id, $meta_data['key'], $meta_data['value'] );
            }

            $subscription->update_dates( $dates_to_update );

            add_filter( 'woocommerce_can_subscription_be_updated_to_cancelled', '__return_true' );
            add_filter( 'woocommerce_can_subscription_be_updated_to_pending-cancel', '__return_true' );

            $subscription->update_status($status);

            remove_filter( 'woocommerce_can_subscription_be_updated_to_cancelled', '__return_true' );
            remove_filter( 'woocommerce_can_subscription_be_updated_to_pending-cancel', '__return_true' );

            if ( !$set_manual && !$subscription->has_status(wcs_get_subscription_ended_statuses()) ) {}

            if ( $set_manual || $requires_manual_renewal ) {
                $subscription->update_manual(true);
            }

            $chosen_tax_rate_id = 0;
            if (!empty($data['tax_items'])) {
                $chosen_tax_rate_id = self::add_taxes( $subscription, $data );
            }

            $productId = wilderness_find_product($data["memberplan"]);
            $result['items'] = self::add_product( $subscription, array( 'product_id' => $productId ), $chosen_tax_rate_id );

            //self::maybe_add_memberships( $user_id, $subscription->id, $product_id );

            $wpdb->query( 'COMMIT' );

        } catch ( Exception $e ) {
            $wpdb->query( 'ROLLBACK' );
            $result['error'][] = $e->getMessage();
        }

        if ( empty( $result['error'] ) ) {
            $result['status']= 'success';
            $result['subscription']  = sprintf( '<a href="%s">#%s</a>', esc_url( admin_url( 'post.php?post=' . absint( $subscription->id ) . '&action=edit' ) ), $subscription->get_order_number() );
            $result['subscription_status'] = $subscription->get_status();

        } else {
            $result['status']  = 'failed';
        }

		array_push( self::$results, $result );
	}

	public static function get_user_display_name( $customer ) {

		if ( !is_object($customer)) {
			$customer = get_userdata( $customer );
		}

		$username = '';

		if ( false !== $customer ) {
			$username  = '<a href="user-edit.php?user_id=' . absint( $customer->ID ) . '">';

			if ( $customer->first_name || $customer->last_name ) {
				$username .= esc_html( ucfirst( $customer->first_name ) . ' ' . ucfirst( $customer->last_name ) );
			} else {
				$username .= esc_html( ucfirst( $customer->display_name ) );
			}

			$username .= '</a>';

		}
		return $username;
	}

	public static function maybe_add_memberships( $user_id, $subscription_id, $product_id ) {

		if ( function_exists( 'wc_memberships_get_membership_plans' ) ) {

			self::$membership_plans = wc_memberships_get_membership_plans();

			foreach ( self::$membership_plans as $plan ) {
				if ( $plan->has_product( $product_id ) ) {
					$plan->grant_access_from_purchase( $user_id, $product_id, $subscription_id );
				}
			}
		}
	}

	public static function add_product( $subscription, $data, $chosen_tax_rate_id ) {

        $variationId = wilderness_find_variation($data['product_id'], $data['subperiod'], $data['subtype']);
        $orderID = wilderness_add_order($data, $variationId);

		$item_args = array();
		$item_args['qty'] = isset( $data['quantity'] ) ? $data['quantity'] : 1;
        
        // get the product object from a product id
		$_product = wc_get_product( $data['product_id'] );

		if ( ! $_product ) {
			throw new Exception( sprintf('No product or variation in your store matches the product ID #%s.', $data['product_id']) );
		}

		$line_item_name = (!empty($data['name'])) ? $data['name'] : $_product->get_title();
		$product_string = sprintf( '<a href="%s">%s</a>', get_edit_post_link( $_product->id ), $line_item_name );

		foreach ( array( 'total', 'tax', 'subtotal', 'subtotal_tax' ) as $line_item_data ) {
			switch ( $line_item_data ) {
				case 'total' :
					$default = WC_Subscriptions_Product::get_price( $data['product_id'] );
					break;
				case 'subtotal' :
					$default = (!empty($data['total'])) ? $data['total'] : WC_Subscriptions_Product::get_price($data['product_id']);
					break;
				default :
					$default = 0;
			}
			$item_args['totals'][$line_item_data] = (!empty($data[$line_item_data])) ? $data[$line_item_data] : $default;
		}

		// Add this site's variation meta data if no line item meta data was specified in the CSV
		if (empty($data['meta'])) {
			$item_args['variation'] = array();

			foreach ( $_product->variation_data as $attribute => $variation ) {
				$item_args['variation'][$attribute] = $variation;
			}

            $variation_id = $customer_data['sub_variation'];

			$product_string .= ' [#' . $data['product_id'] . ']';
            die(var_dump($product_string));
		}

		if ( ! empty( $item_args['totals']['tax'] ) && ! empty( $chosen_tax_rate_id ) ) {
			$item_args['totals']['tax_data']['total']    = array( $chosen_tax_rate_id => $item_args['totals']['tax'] );
			$item_args['totals']['tax_data']['subtotal'] = array( $chosen_tax_rate_id => $item_args['totals']['tax'] );
		}

        $item_id = $subscription->add_product( $_product, $item_args['qty'], $item_args );

        // Set the name used in the CSV if it's different to the product's current title (which is what WC_Abstract_Order::add_product() uses)
        if ( ! empty( $data['name'] ) && $_product->get_title() != $data['name'] ) {
            wc_update_order_item( $item_id, array( 'order_item_name' => $data['name'] ) );
        }

        // Add any meta data for the line item
        if ( ! empty( $data['meta'] ) ) {
            foreach ( explode( '+', $data['meta'] ) as $meta ) {
                $meta = explode( '=', $meta );
                wc_update_order_item_meta( $item_id, $meta[0], $meta[1] );
            }
        }

        if ( !$item_id ) {
            throw new Exception( __( 'An unexpected error occurred when trying to add product "%s" to your subscription. The error was caught and no subscription for this row will be created. Please fix up the data from your CSV and try again.', 'wilderness-import-export' ) );
        }

		return $product_string;
	}
}
