<?php
declare(strict_types=1);

namespace Placard\Admin;

/** First-run setup was attempted after an admin password already exists. */
final class AlreadyConfigured extends \RuntimeException
{
}
