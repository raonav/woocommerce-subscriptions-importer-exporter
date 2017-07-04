<?php

// 
function wilderness_process_field($header, $field){
    switch($header){
        case "end_date":
            $date = date_create_from_format('d/m/Y H:i:s', $field);
            $newDate = date_format($date, 'Y-m-d H:i:s');
            return $newDate;
            break;
    }
    return $field;
}

function wilderness_add_missing_data($data){
    // adding missing dates
    $endDate = date_create($data["end_date"]);
    $duration = $data['subperiod'];
    date_sub($endDate, date_interval_create_from_date_string($duration));
    $startDate = date_format($endDate, 'Y-m-d H:i:s');
    $data["start_date"] = $startDate;
    return $data;
}

// associate our product id for order items
function wilderness_add_product($data){
    return $data;
}

function wilderness_add_order($data, $custome){
    $order = wc_create_order();
    update_post_meta( $order->id, '_billing_first_name', $data['billing_first_name'] );
    update_post_meta( $order->id, '_billing_last_name', $data['billing_last_name'] );
    update_post_meta( $order->id, '_billing_email', $data['customer_email'] );
    update_post_meta( $order->id, '_billing_state', '' );
    update_post_meta( $order->id, '_billing_postcode', $data['billing_postcode'] );
    
    // we only care about digital member plan for now
    $productId = wilderness_find_product($data['memberplan']);
    if($productId){
        $product = get_product($productId); 
        $variation = wilderness_get_variations($data['subperiod'], $data['subtype']);
        if($variation){
            $variation_factory = new WC_Product_Variation($variation_id);
            $variation_obj = $variation_factory->get_variation_attributes();
            $quantity = 1;
            $price = $variation_factory->get_price();
            $price_params = array(
                'variation' => $variation_obj,
                'totals' => array(
                    'subtotal' => $price*$quantity,
                    'total' => $price*$quantity,
                    'subtotal_tax' => 0,
                    'tax' => 0
                )
            );
            $order->add_product(wc_get_product($product_id), $quantity, $price_params);
        } else {
        }
    }
}

// NOTE: Hardcoded hell
function wilderness_get_variations($subperiod, $subtype){
    // presume entry like "12 months", "1 year"

    // 12 months - 12 month renewal
    // 12 months - School
    // 12 months - 12 month new
    if($subperiod == "12 months" && $subtype == "12 month renewal"){
        return 7399;
    } elseif($subperiod == "12 months" && $subtype == "12 month new"){
        return 7398;
    } elseif($subperiod == "12 months"){
        return 9070;
    } elseif($subtype == "6 months"){
        return 21451;
    } else {
        return false;
    }
}

function strip_and_trim($item){
    $item = str_replace(' ', '', strtolower($item));
    return $item;
}

// find product id from member plan column
function wilderness_find_product($memberplan){
    $productName = str_replace(' ', '', strtolower($memberplan));
    if($productName == 'digitalmemberplan'){
        return 7396;
    }
    return false;
}

function wilderness_column_map($column){
    $column = str_replace(' ', '', strtolower($column));
    switch($column){
        case "lastname":
            return "billing_last_name";
            break;
        case "name":
            return "billing_first_name";
            break;
        case "emailaddress":
            return "customer_email";
            break;
        case "address1":
            return "billing_address_1";
            break;
        case "address2":
            return "billing_address_2";
            break;
        case "city":
            return "billing_city";
            break;
        case "postcode":
            return "billing_postcode";
            break;
        case "country":
            return "billing_country";
            break;
        case "expirydate":
            return "end_date";
            break;
        default:
            return $column;
            break;
    }
    return $column;
}

// ensure correct encoding
function wcsi_format_data( $data, $file_encoding = 'UTF-8' ) {
	return ( 'UTF-8' == $file_encoding ) ? $data : utf8_encode( $data );
}

 // checks customer information and creates a new store customer when no customer id has been given
function wcsi_check_customer( $data, $email_customer = false ) {
	$customer_email = ( ! empty( $data['customer_email'])) ? $data['customer_email'] : '';
	$username       = ( ! empty( $data['customer_username'])) ? $data['customer_username'] : '';
	$customer_id    = ( ! empty( $data['customer_id'])) ? $data['customer_id'] : '';

	if ( ! empty( $data[ $mapped_fields['customer_password'] ] ) ) {
		$password           = $data[ $mapped_fields['customer_password'] ];
		$password_generated = false;
	} else {
		$password           = wp_generate_password( 12, true );
		$password_generated = true;
	}

	$found_customer = false;

	if ( empty( $customer_id ) ) {

		if ( is_email( $customer_email ) && false !== email_exists( $customer_email ) ) {
			$found_customer = email_exists( $customer_email );
		} elseif ( ! empty( $username ) && false !== username_exists( $username ) ) {
			$found_customer = username_exists( $username );
		} elseif ( is_email( $customer_email ) ) {

            if ( empty( $username ) ) {

                $maybe_username = explode( '@', $customer_email );
                $maybe_username = sanitize_user( $maybe_username[0] );
                $counter        = 1;
                $username       = $maybe_username;

                while ( username_exists( $username ) ) {
                    $username = $maybe_username . $counter;
                    $counter++;
                }
            }

            $found_customer = wp_create_user( $username, $password, $customer_email );

            if ( ! is_wp_error( $found_customer ) ) {

                // update user meta data
                foreach ( WCS_Importer::$user_meta_fields as $key ) {
                    switch ( $key ) {
                        case 'billing_email':
                            // user billing email if set in csv otherwise use the user's account email
                            $meta_value = ( ! empty( $data[ $mapped_fields[ $key ] ] ) ) ? $data[ $mapped_fields[ $key ] ] : $customer_email;
                            update_user_meta( $found_customer, $key, $meta_value );
                            break;

                        case 'billing_first_name':
                            $meta_value = ( ! empty( $data[ $mapped_fields[ $key ] ] ) ) ? $data[ $mapped_fields[ $key ] ] : $username;
                            update_user_meta( $found_customer, $key, $meta_value );
                            update_user_meta( $found_customer, 'first_name', $meta_value );
                            break;

                        case 'billing_last_name':
                            $meta_value = ( ! empty( $data[ $mapped_fields[ $key ] ] ) ) ? $data[ $mapped_fields[ $key ] ] : '';

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
                            $meta_value = ( ! empty( $data[ $mapped_fields[ $key ] ] ) ) ? $data[ $mapped_fields[ $key ] ] : '';

                            if ( empty( $meta_value ) ) {
                                $n_key      = str_replace( 'shipping', 'billing', $key );
                                $meta_value = ( ! empty( $data[ $mapped_fields[ $n_key ] ] ) ) ? $data[ $mapped_fields[ $n_key ] ] : '';
                            }

                            update_user_meta( $found_customer, $key, $meta_value );
                            break;

                        default:
                            $meta_value = ( ! empty( $data[ $mapped_fields[ $key ] ] ) ) ? $data[ $mapped_fields[ $key ] ] : '';
                            update_user_meta( $found_customer, $key, $meta_value );
                    }
                }

                wcs_make_user_active( $found_customer );

                // send user registration email if admin as chosen to do so
                if ( $email_customer && function_exists( 'wp_new_user_notification' ) ) {

                    $previous_option = get_option( 'woocommerce_registration_generate_password' );

                    // force the option value so that the password will appear in the email
                    update_option( 'woocommerce_registration_generate_password', 'yes' );

                    do_action( 'woocommerce_created_customer', $found_customer, array( 'user_pass' => $password ), true );

                    update_option( 'woocommerce_registration_generate_password', $previous_option );
                }
            }
		}
	} else {
		$user = get_user_by( 'id', $customer_id );

		if ( ! empty( $user ) && ! is_wp_error( $user ) ) {
			$found_customer = absint( $customer_id );

		} else {
			$found_customer = new WP_Error( 'wcsi_invalid_customer', sprintf( __( 'User with ID (#%s) does not exist.', 'wcs-import-export' ), $customer_id ) );
		}
	}

	return $found_customer;
}
