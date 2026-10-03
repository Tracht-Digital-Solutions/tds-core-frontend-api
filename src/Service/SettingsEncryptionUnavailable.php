<?php

declare(strict_types=1);

namespace Tds\CoreFrontendApi\Service;

/** A secret setting was written while SETTINGS_ENCRYPTION_KEY is unset. */
final class SettingsEncryptionUnavailable extends \RuntimeException
{
}
