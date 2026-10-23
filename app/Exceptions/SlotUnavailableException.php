<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The requested start time is not (or no longer) bookable.
 */
class SlotUnavailableException extends RuntimeException {}
