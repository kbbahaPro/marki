<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A host's connected calendar could not be read (revoked token, API outage…).
 * Availability fails closed: no slots are offered until it recovers.
 */
class CalendarUnavailableException extends RuntimeException {}
