<?php

declare(strict_types=1);

return [

    /*
    |---------------------------------------------------------------------
    | Master key
    |---------------------------------------------------------------------
    |
    | Base64 of 32 bytes. Wraps every organisation data key, and therefore
    | transitively protects every stored credential.
    |
    | In production this belongs in a KMS, not here. The local driver exists
    | so development and tests do not need cloud access; it is real
    | authenticated encryption rather than a stub, so moving to a KMS changes
    | the implementation and not the threat model for data at rest.
    |
    | Generate one with: php artisan vault:generate-key
    |
    */

    'master_key' => env('VAULT_MASTER_KEY', ''),

    /*
    |---------------------------------------------------------------------
    | Master key identifier
    |---------------------------------------------------------------------
    |
    | Recorded against every wrapped data key. A master key rotation finds
    | the rows still on the old key by this value, so changing the key
    | without changing the id would make the rotation invisible.
    |
    */

    'master_key_id' => env('VAULT_MASTER_KEY_ID', 'local'),

    /*
    |---------------------------------------------------------------------
    | Previous master key
    |---------------------------------------------------------------------
    |
    | Set these during a rotation, alongside the new key and id. The vault
    | needs both keys at once: the old one to unwrap what is already on disk,
    | the new one to re-wrap it. Unwrapping with the new key cannot work.
    |
    | Sequence:
    |   1. Move the current values into VAULT_PREVIOUS_MASTER_KEY and
    |      VAULT_PREVIOUS_MASTER_KEY_ID.
    |   2. Set VAULT_MASTER_KEY to a fresh key and give it a new id.
    |   3. php artisan vault:rewrap
    |   4. Clear the previous values once it reports nothing pending.
    |
    | The id must change as well as the key, or rotated and un-rotated rows
    | cannot be told apart and a partial rotation is unrecoverable.
    |
    */

    'previous_master_key' => env('VAULT_PREVIOUS_MASTER_KEY', ''),

    'previous_master_key_id' => env('VAULT_PREVIOUS_MASTER_KEY_ID', ''),

];
