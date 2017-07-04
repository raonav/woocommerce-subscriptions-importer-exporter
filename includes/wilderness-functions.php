<?php

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

