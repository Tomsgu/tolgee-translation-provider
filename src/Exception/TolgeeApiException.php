<?php

declare(strict_types=1);

namespace Tomsgu\TolgeeTranslationProvider\Exception;

use RuntimeException;

/**
 * Thrown when a Tolgee write call fails and continuing would silently report success.
 *
 * @author Tomas Jakl <tomasjakl@tomsgu.com>
 */
class TolgeeApiException extends RuntimeException
{
}
