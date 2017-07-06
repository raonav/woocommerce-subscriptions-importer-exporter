<?php

// often want/need to remove spaces and lowercase
function strip_and_trim($item){
    $item = str_replace(' ', '', strtolower($item));
    return $item;
}

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

function wilderness_add_missing_date($data){
    // adding missing end date date
    $endDate = date_create($data["end_date"]);
    // assume a year's difference to calculate the end date
    // TODO: add a way to better define the end date based on product variation
    $duration = $data['subperiod'];
    date_sub($endDate, date_interval_create_from_date_string($duration));
    $startDate = date_format($endDate, 'Y-m-d H:i:s');
    $data["start_date"] = $startDate;
    return $data;
}

function wilderness_add_order($data, $variationId){
    $order = wc_create_order();
    update_post_meta( $order->id, '_billing_first_name', $data['billing_first_name'] );
    update_post_meta( $order->id, '_billing_last_name', $data['billing_last_name'] );
    update_post_meta( $order->id, '_billing_email', $data['customer_email'] );
    update_post_meta( $order->id, '_billing_state', '' );
    update_post_meta( $order->id, '_billing_postcode', $data['billing_postcode'] );
    
    // we only care about digital member plan for now
    $variation = wilderness_find_variation($data['product_id'], $data['subperiod'], $data['subtype']);
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
    }
}


// find product id from member plan column
function wilderness_find_product($memberplan){
    $productID = '';
    $productName = strip_and_trim($memberplan);

    if($productName == 'digitalmemberplan'){
        return 7396;
    }
    // we default to the other non-trial subscription plan just because
    return 6867;
}

function wilderness_find_variation($productID, $subperiod, $subtype){

    // digital subscription
    if($productID == 7396){
        if(strip_and_trim($subtype) == "12monthrenewal"){
            $variationID = 7399;
        } elseif(strip_and_trim($subtype) == '3monthrenewal'){
            $variationID = 7398;
        } elseif(strip_and_trim($subtype) == '2monthrenewal'){
            $variationID = 9070;
        } elseif(strip_and_trim($subtype) == "12monthnew"){
            $variationID = 21451;
        } else {
            $variationId = 21451; // just default to the one that doesn't last a lifetime 
        }
    } else { // digital and print subscription
        if(strip_and_trim($subtype) == "12monthrenewal"){
            $variationID = 7392;
        } elseif(strip_and_trip($subtype) == '3monthrenewal'){
            $variationID = 9071;
        } elseif(strip_and_trip($subtype) == '2monthrenewal'){
            $variationID = 7391;
        } elseif(strip_and_trim($subtype) == "12monthnew"){
            $variationID = 21452;
        } else {
            $variationId = 21451; // just default to the one that doesn't last a lifetime 
        }
    }
    return $variationID;
}

function wilderness_find_period($subperiod){

    if($subperiod == "12months"){
        $billing_period = "month";
    } else {
        $billing_period = "year";
    }
    return $billing_period;
}

function wilderness_find_interval(){

    if($subtype == "12monthrenewal"){
        $billing_interval = 1;
    } elseif($subtype == "12monthnew"){
        $billing_interval = 1;
    } elseif($subtype == "3monthrenewal"){
        $billing_interval = 3;
    } else {
        $billing_interval = 1;
    }
    return $billing_interval;
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
function wildernessi_format_data( $data, $file_encoding = 'UTF-8' ) {
	return ( 'UTF-8' == $file_encoding ) ? $data : utf8_encode( $data );
}

 // checks customer information and creates a new store customer when no customer id has been given
function wilderness_check_customer( $data, $email_customer = false ) {
	$customer_email = (!empty($data['customer_email'])) ? $data['customer_email'] : '';

    $password = wp_generate_password( 12, true );
    $password_generated = true;

	$found_customer = false;

    if ( is_email( $customer_email ) && false !== email_exists( $customer_email ) ) {
        $found_customer = email_exists($customer_email);
    } elseif (is_email($customer_email)) {

        $maybe_username = explode( '@', $customer_email );
        $maybe_username = sanitize_user($maybe_username[0]);
        $counter = 1;
        $username = $maybe_username;

        $found_customer = wp_create_user( $username, $password, $customer_email );

        if ( ! is_wp_error( $found_customer ) ) {

            // update user meta data
            foreach ( Wilderness_Importer::$user_meta_fields as $key ) {
                switch ($key) {
                    case 'billing_email':
                        // user billing email if set in csv otherwise use the user's account email
                        $meta_value = (!empty($data[$key])) ? $data[$key] : $customer_email;
                        update_user_meta( $found_customer, $key, $meta_value );
                        break;

                    case 'billing_first_name':
                        $meta_value = ( ! empty( $data[ $key ] ) ) ? $data[ $key ] : $username;
                        update_user_meta( $found_customer, $key, $meta_value );
                        update_user_meta( $found_customer, 'first_name', $meta_value );
                        break;

                    case 'billing_last_name':
                        $meta_value = ( !empty($data[$key])) ? $data[ $key ] : '';

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
                        $meta_value = ( ! empty( $data[ $key ] ) ) ? $data[ $key ] : '';

                        if ( empty( $meta_value ) ) {
                            $n_key      = str_replace( 'shipping', 'billing', $key );
                            $meta_value = ( ! empty( $data[ $n_key ] ) ) ? $data[ $n_key ] : '';
                        }

                        update_user_meta( $found_customer, $key, $meta_value );
                        break;

                    default:
                        $meta_value = ( ! empty( $data[ $key ] ) ) ? $data[ $key ] : '';
                        update_user_meta( $found_customer, $key, $meta_value );
                }
            }

            wcs_make_user_active($found_customer);

            // send user registration email if admin as chosen to do so
            //$previous_option = get_option( 'woocommerce_registration_generate_password' );
            //update_option( 'woocommerce_registration_generate_password', 'yes' );
            //do_action( 'woocommerce_created_customer', $found_customer, array( 'user_pass' => $password ), true );
            //update_option( 'woocommerce_registration_generate_password', $previous_option );
        }
    }

	return $found_customer;
}
