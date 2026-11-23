<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Lib\Enrollment\EnrollmentService;
use App\Lib\Enrollment\Exceptions\EnrollmentRejected;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The one unauthenticated endpoint in the system.
 *
 * A freshly booted Droplet has a single-use token and nothing else. It has no
 * certificate yet, which is the entire reason this endpoint exists, so
 * everything about it assumes the caller may be hostile:
 *
 *  - every failure returns the same opaque response, because distinguishing
 *    an unknown token from an expired one turns this into an oracle for
 *    probing which tokens exist
 *  - the reason goes to the audit log and the application log, never the wire
 *  - the body is size-limited, since an unauthenticated caller should not be
 *    able to make us parse an arbitrary amount of JSON
 *  - it is rate limited, because the token space is large but the endpoint is
 *    open
 *
 * TLS termination happens in front of this, at nginx or a load balancer. The
 * agent pins the organisation CA and verifies the server name, so that
 * terminator must present a certificate from the same CA.
 */
final class EnrollmentController extends Controller
{
    /**
     * Enrollment requests carry a CSR, which is a few kilobytes. Anything
     * approaching this is not a real agent.
     */
    private const MAX_BODY_BYTES = 64 * 1024;

    public function __construct(private readonly EnrollmentService $enrollment) {}

    public function store(Request $request): JsonResponse
    {
        if (strlen((string) $request->getContent()) > self::MAX_BODY_BYTES) {
            // Distinct from an enrollment rejection on purpose. Body size
            // says nothing about whether a token is valid, so a separate
            // status leaks nothing and is more useful to a real client.
            return response()->json(['error' => 'request_too_large'], 413);
        }

        $validated = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'csr_pem' => ['required', 'string', 'max:16384'],
            // Reported by the node for the audit trail. None of it influences
            // the identity granted, so none of it needs to be trusted.
            'agent_version' => ['nullable', 'string', 'max:64'],
            'hostname' => ['nullable', 'string', 'max:255'],
            'os' => ['nullable', 'string', 'max:32'],
            'arch' => ['nullable', 'string', 'max:32'],
        ]);

        try {
            $result = $this->enrollment->enroll(
                token: $validated['token'],
                csrPem: $this->decodeCsr($validated['csr_pem']),
                reported: [
                    'agent_version' => $validated['agent_version'] ?? null,
                    'hostname' => $validated['hostname'] ?? null,
                    'os' => $validated['os'] ?? null,
                    'arch' => $validated['arch'] ?? null,
                ],
                ipAddress: $request->ip(),
            );
        } catch (EnrollmentRejected $e) {
            // The cause is useful to us and must not reach the caller.
            Log::warning('Enrollment rejected', [
                'cause' => $e->cause(),
                'ip' => $request->ip(),
                'hostname' => $validated['hostname'] ?? null,
            ]);

            return $this->rejected();
        }

        return response()->json(
            $result->toArray((string) config('control_plane.session_address'))
        );
    }

    /**
     * The agent sends the CSR as a JSON string of PEM bytes.
     *
     * Go marshals a []byte field to base64, so a request from our agent
     * arrives base64 encoded. Accept both that and raw PEM, since a
     * hand-rolled client or a future SDK may send either and the difference
     * is unambiguous.
     */
    private function decodeCsr(string $value): string
    {
        if (str_contains($value, 'BEGIN CERTIFICATE REQUEST')) {
            return $value;
        }

        $decoded = base64_decode($value, true);

        // If it does not decode, hand the original through. The PKI layer
        // will reject it, and rejecting it there keeps the "all failures look
        // alike" property in one place.
        return $decoded === false ? $value : $decoded;
    }

    /**
     * The single response any failure produces.
     */
    private function rejected(): JsonResponse
    {
        return response()->json(['error' => 'enrollment_rejected'], 403);
    }
}
