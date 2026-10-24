<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The booking was already cancelled, rescheduled or has started.
 */
class BookingNotChangeableException extends RuntimeException {}
