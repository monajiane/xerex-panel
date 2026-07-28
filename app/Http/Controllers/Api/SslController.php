<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\SslCertificate;
use App\Services\CertbotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SslController extends Controller
{
    public function __construct(protected CertbotService $certbot) {}

    public function index(Request $request): JsonResponse
    {
        $query = SslCertificate::with('domain:id,domain');

        if ($status = $request->string('status')->toString()) {
            $query->where('status', $status);
        }
        if ($search = $request->string('search')->toString()) {
            $query->where('common_name', 'like', "%{$search}%");
        }

        return response()->json($query->orderBy('expires_at')->paginate($request->integer('per_page', 25)));
    }

    public function show(SslCertificate $certificate): JsonResponse
    {
        $certificate->load('domain');
        return response()->json($certificate);
    }

    public function destroy(SslCertificate $certificate): JsonResponse
    {
        $certificate->delete();
        return response()->json(['message' => 'Certificate deleted']);
    }

    /**
     * Issue a new Let's Encrypt certificate for a domain.
     */
    public function issue(Request $request, Domain $domain): JsonResponse
    {
        $data = $request->validate([
            'wildcard' => ['boolean'],
        ]);

        try {
            $cert = $this->certbot->issue($domain, $data['wildcard'] ?? false);
            return response()->json($cert, 201);
        } catch (\Throwable $e) {
            return response()->json([
                'error'   => 'cert_issue_failed',
                'message' => $e->getMessage(),
            ], 502);
        }
    }

    public function renew(SslCertificate $certificate): JsonResponse
    {
        try {
            $cert = $this->certbot->renew($certificate);
            return response()->json($cert);
        } catch (\Throwable $e) {
            return response()->json([
                'error'   => 'cert_renew_failed',
                'message' => $e->getMessage(),
            ], 502);
        }
    }

    public function revoke(SslCertificate $certificate): JsonResponse
    {
        try {
            $this->certbot->revoke($certificate);
            return response()->json(['message' => 'Certificate revoked']);
        } catch (\Throwable $e) {
            return response()->json([
                'error'   => 'cert_revoke_failed',
                'message' => $e->getMessage(),
            ], 502);
        }
    }

    /**
     * Get cert summary for a domain.
     */
    public function forDomain(Domain $domain): JsonResponse
    {
        return response()->json([
            'domain'       => $domain->only(['id', 'domain', 'ssl_status', 'ssl_expires_at', 'auto_renew']),
            'certificates' => $domain->sslCertificates()->orderByDesc('issued_at')->get(),
        ]);
    }
}
