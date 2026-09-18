<?php

/*
 * События биллинга, о которых уведомляем участников организации (developer и выше),
 * и каналы по умолчанию. Пользователь может переопределить их в настройках.
 */
return [
    'events' => [
        'invoice.created' => ['label' => 'Инвойс выставлен', 'in_app' => true, 'mail' => false],
        'invoice.paid' => ['label' => 'Инвойс оплачен', 'in_app' => true, 'mail' => false],
        'payment.failed' => ['label' => 'Платёж не прошёл', 'in_app' => true, 'mail' => true],
        'subscription.created' => ['label' => 'Новая подписка', 'in_app' => true, 'mail' => false],
        'subscription.canceled' => ['label' => 'Подписка отменена', 'in_app' => true, 'mail' => true],
        'refund.succeeded' => ['label' => 'Возврат выполнен', 'in_app' => true, 'mail' => false],
    ],
];
