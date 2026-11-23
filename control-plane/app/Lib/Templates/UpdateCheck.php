<?php

declare(strict_types=1);

namespace App\Lib\Templates;

/**
 * What a newly fetched version of an installed template changes. A new version
 * is never applied automatically: a change of download host or image
 * repository needs an explicit confirmation, and a new publisher for an
 * existing id is refused (docs/specs/templates.md section 3).
 */
final class UpdateCheck
{
    /**
     * @param  array{version: int, publisher: string, download_host: ?string, image_repo: ?string}  $installed
     * @return array{allowed: bool, confirm: bool, changes: list<string>, refused: ?string}
     */
    public static function compare(array $installed, Definition $new, string $newPublisher): array
    {
        if ($newPublisher !== $installed['publisher']) {
            return ['allowed' => false, 'confirm' => false, 'changes' => [], 'refused' => "the publisher changed from {$installed['publisher']} to $newPublisher; a new publisher for an existing template is refused"];
        }
        if ($new->version <= $installed['version']) {
            return ['allowed' => false, 'confirm' => false, 'changes' => [], 'refused' => 'version '.$new->version.' is not newer than the installed version '.$installed['version']];
        }

        $changes = ['version '.$installed['version'].' to '.$new->version];
        $confirm = false;
        if ($new->downloadHost() !== $installed['download_host']) {
            $changes[] = 'the download host changed from '.($installed['download_host'] ?? 'none').' to '.($new->downloadHost() ?? 'none');
            $confirm = true;
        }
        if ($new->imageRepository() !== $installed['image_repo']) {
            $changes[] = 'the image repository changed from '.($installed['image_repo'] ?? 'none').' to '.($new->imageRepository() ?? 'none');
            $confirm = true;
        }

        return ['allowed' => true, 'confirm' => $confirm, 'changes' => $changes, 'refused' => null];
    }
}
