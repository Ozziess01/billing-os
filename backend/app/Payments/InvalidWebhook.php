<?php

namespace App\Payments;

use RuntimeException;

/** Подпись не сошлась, timestamp протух или тело нельзя разобрать - событие не наше. */
class InvalidWebhook extends RuntimeException {}
