<?php

namespace App\Payments;

use RuntimeException;

/** Провайдер недоступен или ответил невалидно: это не отказ по платежу, а сбой связи. */
class ProviderException extends RuntimeException {}
