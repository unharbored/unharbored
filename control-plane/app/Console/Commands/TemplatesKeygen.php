<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Makes an ed25519 signing key for a template index. The secret goes to a file
 * (mode 0600) and is never printed; only the public key is shown.
 */
final class TemplatesKeygen extends Command
{
    protected $signature = 'templates:keygen {secret-file : where to write the secret key (base64, mode 0600)} {--force : overwrite an existing file}';

    protected $description = 'Generate an ed25519 key for signing a template index; prints only the public key';

    public function handle(): int
    {
        $file = (string) $this->argument('secret-file');
        if (file_exists($file) && ! $this->option('force')) {
            $this->error("$file already exists; refusing to overwrite it (use --force only if you are sure it is not a key in use).");

            return self::FAILURE;
        }
        if (! is_dir(dirname($file))) {
            $this->error('the folder '.dirname($file).' does not exist');

            return self::FAILURE;
        }

        $pair = sodium_crypto_sign_keypair();
        $secret = base64_encode(sodium_crypto_sign_secretkey($pair));
        $old = umask(0177);
        $written = @file_put_contents($file, $secret."\n");
        umask($old);
        sodium_memzero($secret);
        if ($written === false) {
            $this->error("could not write $file");

            return self::FAILURE;
        }
        chmod($file, 0600);

        $this->line(base64_encode(sodium_crypto_sign_publickey($pair)));
        $this->newLine();
        $this->comment('That is the public key (base64): it goes into the control plane as CM_TEMPLATES_OFFICIAL_KEY or CM_TEMPLATES_COMMUNITY_KEY.');
        $this->comment("The secret key is in $file. Store it as the signing secret in CI (CM_TEMPLATES_SIGNING_KEY) and keep one offline backup, then delete this file from the machine you made it on. Anyone holding it can publish templates your installs will trust, so it is never committed, pasted into chat or logged; if it may have leaked, rotate it (docs/specs/templates-keys.md).");

        return self::SUCCESS;
    }
}
