<?php

return [
    /*
    | Persist timestamps in UTC and convert them only at the presentation and
    | reporting boundary. This keeps storage stable while every surface shows
    | the same local business time.
    */
    'timezone' => env('DISPLAY_TIMEZONE', env('ORDER_DISPLAY_TIMEZONE', 'Africa/Cairo')),
];
