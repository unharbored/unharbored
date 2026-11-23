<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Enrollment\EnrollmentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Mints an enrollment token and writes out everything a node needs to use it.
 *
 * Normally the provisioner does this and embeds the result in cloud-init.
 * This command exists for the case where a machine already exists and you
 * want to bring it under management by hand, and for testing an agent against
 * a control plane without provisioning anything.
 *
 * The token is written to a file rather than printed, so it does not land in
 * scrollback or a terminal recording. It is single use and short lived, but
 * those are mitigations rather than reasons to be careless with it.
 */
final class IssueNodeToken extends Command
{
    protected $signature = 'cloud:issue-token
                            {organization : Organisation id}
                            {node : Node id to grant}
                            {--out=. : Directory to write ca.crt and enroll.token into}
                            {--ttl=3600 : Token lifetime in seconds}';

    protected $description = 'Mint an enrollment token and export the CA an agent needs';

    public function handle(EnrollmentService $enrollment): int
    {
        $organizationId = (string) $this->argument('organization');
        $nodeId = (string) $this->argument('node');
        $out = rtrim((string) $this->option('out'), '/');

        if (! DB::table('organizations')->where('id', $organizationId)->exists()) {
            $this->error(sprintf('Organisation %s does not exist. Create it with cloud:connect --name.', $organizationId));

            return self::FAILURE;
        }

        if (! is_dir($out) && ! mkdir($out, 0o700, true) && ! is_dir($out)) {
            $this->error(sprintf('Could not create %s.', $out));

            return self::FAILURE;
        }

        // Creates the organisation CA on first use, with the private key
        // going into the vault rather than beside the certificate.
        $ca = $enrollment->certificateAuthorityFor($organizationId);

        $token = $enrollment->issueToken($organizationId, $nodeId, (int) $this->option('ttl'));

        $caPath = $out.'/ca.crt';
        $tokenPath = $out.'/enroll.token';

        file_put_contents($caPath, $ca->certificatePem());
        file_put_contents($tokenPath, $token);
        @chmod($tokenPath, 0o600);

        $this->info(sprintf('Token issued for node %s in organisation %s.', $nodeId, $organizationId));
        $this->line('  CA:    '.$caPath);
        $this->line('  Token: '.$tokenPath.'  (single use, expires in '.$this->option('ttl').'s)');
        $this->newLine();
        $this->line('Start an agent with:');
        $this->newLine();
        $this->line(sprintf(
            "  agent -state-dir ./agent-state \\\n".
            "        -sites-root ./sites \\\n".
            "        -enroll-url https://%s/api/enroll \\\n".
            "        -server-name %s \\\n".
            "        -ca-file %s \\\n".
            "        -token-file %s",
            config('control_plane.server_name'),
            config('control_plane.server_name'),
            $caPath,
            $tokenPath,
        ));

        return self::SUCCESS;
    }
}
