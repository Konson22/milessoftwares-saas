<?php

return [

    /*
    | The most a member allowed to give discounts (pharmacy.sales.discount)
    | may take off one line at the counter, in percent.
    */
    'max_discount_percent' => (int) env('PHARMACY_MAX_DISCOUNT_PERCENT', 20),

];
