<?php

declare(strict_types=1);

namespace App\Lib\Templates;

use RuntimeException;

/** A template file that failed validation. Carries every error, not just the first. */
final class InvalidDefinition extends RuntimeException
{
    /** @param list<array{path: string, line: ?int, message: string}> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode("\n", array_map(self::format(...), $errors)));
    }

    /** @param array{path: string, line: ?int, message: string} $error */
    public static function format(array $error): string
    {
        $where = $error['path'];
        if ($error['line'] !== null) {
            $where .= ($where === '' ? 'line ' : ' (line ').$error['line'].($where === '' ? '' : ')');
        }

        return ($where === '' ? '' : $where.': ').$error['message'];
    }
}
