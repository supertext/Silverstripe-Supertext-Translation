<?php

/**
 * @package     Supertext Translation for Silverstripe
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace Supertext\Silverstripe\Api;

/**
 * An export or import of a translation file that can't go ahead. `$reason` is shown in the CMS
 * user's language by Service\Messages: nothing_to_translate, invalid_request, invalid_file,
 * missing_metadata, too_large, other_system (args: the file's plugin), not_found,
 * no_language (args: the locale), no_file.
 */
final class FileTransferException extends \RuntimeException
{
    /** @param list<string> $args */
    public function __construct(public readonly string $reason, public readonly array $args = [])
    {
        parent::__construct($reason . ($args !== [] ? ' (' . implode(', ', $args) . ')' : ''));
    }
}
