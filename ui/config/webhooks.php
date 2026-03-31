<?php

return [
    'page_rate_limit_per_minute' => (int) env('WEBHOOK_PAGE_RATE_LIMIT_PER_MINUTE', 60),
    'delivery_rate_limit_per_minute' => (int) env('WEBHOOK_DELIVERY_RATE_LIMIT_PER_MINUTE', 240),
];
