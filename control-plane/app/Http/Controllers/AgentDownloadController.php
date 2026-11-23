<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Lib\Agent\AgentRelease;
use App\Lib\Agent\Exceptions\AgentBinaryMissing;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the agent binary to booting nodes.
 *
 * Unauthenticated, and necessarily so: a Droplet fetches this during
 * cloud-init, before it has any credential at all. That is the whole reason
 * enrollment exists.
 *
 * What makes it safe is not access control but the checksum. The control
 * plane embeds the SHA-256 in the cloud-init it generates, and the node
 * verifies the download against it before making the file executable. A
 * hijacked download therefore fails rather than running as root on a fresh
 * machine.
 *
 * The binary is also not a secret. It is the same build for every customer,
 * and anyone can download it; possessing it grants nothing without an
 * enrollment token and an organisation CA.
 *
 * Serving it from here rather than from object storage removes a dependency:
 * the node must already be able to reach the control plane to enroll, so
 * there is no second thing to host, publish, or keep in sync.
 */
final class AgentDownloadController extends Controller
{
    public function __construct(private readonly AgentRelease $release) {}

    /**
     * Download a platform build.
     */
    public function show(string $platform): Response
    {
        try {
            $path = $this->release->path($platform);
        } catch (AgentBinaryMissing $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }

        return (new BinaryFileResponse($path))
            ->setContentDisposition('attachment', 'cloud-manager-agent');
    }

    /**
     * The checksum a node must verify before running the binary.
     *
     * Published separately so a build pipeline, or an operator checking by
     * hand, can confirm what the control plane will put into cloud-init
     * without downloading the whole binary.
     */
    public function checksum(string $platform): JsonResponse
    {
        try {
            return response()->json([
                'platform' => $platform,
                'sha256' => $this->release->sha256($platform),
                'bytes' => $this->release->size($platform),
                'version' => $this->release->version(),
            ]);
        } catch (AgentBinaryMissing $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }
    }
}
