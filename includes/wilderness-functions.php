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

    // adding missing subscription details
    // subperiod: 12 months
    // subtype: FMC / 12 month renewal
    // annual - 89.50 per year

    $data["order_items"] = "product_id:7396";
    //$data['billing_interval'] = 1;
    //$data['billing_period'] = "";
    return $data;
}

// associate our product id for order items
function wilderness_add_product($data){
    $productName = str_replace(' ', '', strtolower($data["memberplan"]));
    // we only care about digital member plan for now
    if($productName == 'digitalmemberplan'){
        // TODO: move this to somewhere editable and apparent
        $productId = 7396;
        //product_id:7392|name:Print & digital subscription|quantity:1|total:89.50|meta:subscription-options=Annually - $89.50 every year|tax:0.00
        $orderItem = "product_id:" . productId . "|name:Digital subscription|quantity:1|total";
    }
    return $data;
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

