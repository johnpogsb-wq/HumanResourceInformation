<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * The AI features are switched off, or the provider could not be reached.
 *
 * Distinct from a genuine failure: callers catch this and fall back to the
 * ordinary manual path rather than showing an error, because every AI feature
 * here is an accelerator for something the user can still do by hand.
 */
class AiUnavailableException extends RuntimeException {}
