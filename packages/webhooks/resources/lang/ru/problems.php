<?php

declare(strict_types=1);

return [
    'invalid_webhook_signature' => [
        'title' => 'Неверная подпись вебхука',
        'detail' => 'Подпись вебхука не прошла проверку.',
    ],
    'webhook_delivery_not_found' => [
        'title' => 'Доставка не найдена',
        'detail' => 'У этого endpoint нет такой доставки.',
    ],
    'webhook_delivery_not_redeliverable' => [
        'title' => 'Доставку нельзя повторить',
        'detail' => 'Доставка ещё повторяется или её endpoint отключён.',
    ],
    'webhook_endpoint_not_found' => [
        'title' => 'Webhook endpoint не найден',
        'detail' => 'Такого webhook endpoint не существует.',
    ],
];
