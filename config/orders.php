<?php

return [
    /*
    | Orders remain stored in UTC. This timezone is used only when an
    | administrator views, filters, or exports order dates and times.
    */
    'display_timezone' => env('ORDER_DISPLAY_TIMEZONE', env('DISPLAY_TIMEZONE', 'Africa/Cairo')),
];
