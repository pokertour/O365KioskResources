<?php

namespace App\Services;

use RuntimeException;

/**
 * Booking refused for a reason that can be shown as-is to the person in front of the screen.
 */
class BookingException extends RuntimeException {}
