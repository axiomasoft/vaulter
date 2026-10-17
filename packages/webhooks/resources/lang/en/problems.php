<?php

declare(strict_types=1);

return [
    'invalid_webhook_signature' => [
        'title' => 'Invalid webhook signature',
        'detail' => 'The webhook signature did not verify.',
    ],
    'webhook_delivery_not_found' => [
        'title' => 'Webhook delivery not found',
        'detail' => 'The delivery does not exist for this endpoint.',
    ],
    'webhook_delivery_not_redeliverable' => [
        'title' => 'Delivery cannot be redelivered',
        'detail' => 'The delivery is still being retried or its endpoint is inactive.',
    ],
    'webhook_endpoint_not_found' => [
        'title' => 'Webhook endpoint not found',
        'detail' => 'The webhook endpoint does not exist.',
    ],
];
