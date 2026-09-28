<?php

declare(strict_types=1);

namespace WebhookAdmin;

final class Version
{
    /** SDK version. Kept equal to CHANGELOG.md by a test. */
    public const VERSION = '0.1.0';

    /** Sent as User-Agent so API request logs show the SDK and its version. */
    public const USER_AGENT = 'webhookadmin-php/' . self::VERSION;
}
