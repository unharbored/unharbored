<?php

declare(strict_types=1);

namespace App\Lib\Enrollment;

use DateTimeInterface;

/**
 * What a successfully enrolled node receives.
 *
 * The CA certificate is included so the agent can pin it when dialling the
 * control plane. That is what lets a node verify us without trusting the
 * host's root store, and therefore what stops a coerced public CA being used
 * to impersonate the control plane.
 */
final readonly class EnrollmentResult
{
    public function __construct(
        public string $nodeId,
        public string $organizationId,
        public string $certificatePem,
        public string $caCertificatePem,
        public DateTimeInterface $notAfter,
    ) {}

    /**
     * The JSON body the agent expects. Field names match enroll.Response in
     * the Go agent and must not drift.
     *
     * @return array<string, mixed>
     */
    public function toArray(string $controlPlaneAddr): array
    {
        return [
            'node_id' => $this->nodeId,
            'org_id' => $this->organizationId,
            'cert_pem' => $this->certificatePem,
            'ca_pem' => $this->caCertificatePem,
            'control_plane_addr' => $controlPlaneAddr,
            'not_after' => $this->notAfter->format(DATE_RFC3339),
        ];
    }
}
