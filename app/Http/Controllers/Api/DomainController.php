<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DomainController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Domain::query()->with('user:id,name,email');

        if (! $request->user()->is_admin) {
            $query->where('user_id', $request->user()->id);
        }
        if ($search = $request->string('search')->toString()) {
            $query->where('domain', 'like', "%{$search}%");
        }
        if ($ssl = $request->string('ssl_status')->toString()) {
            $query->where('ssl_status', $ssl);
        }

        return response()->json($query->orderByDesc('id')->paginate($request->integer('per_page', 25)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'domain'   => ['required', 'string', 'max:255', 'unique:domains,domain', 'regex:/^([a-z0-9-]+\.)+[a-z]{2,}$/i'],
            'registrar'=> ['nullable', 'string', 'max:120'],
            'wildcard' => ['boolean'],
        ]);

        $data['user_id'] = $request->user()->id;

        $domain = Domain::create($data);

        return response()->json($domain, 201);
    }

    public function show(Domain $domain): JsonResponse
    {
        $this->authorizeAccess(request()->user(), $domain);
        $domain->load(['proxyRules', 'activeCertificate']);
        return response()->json($domain);
    }

    public function update(Request $request, Domain $domain): JsonResponse
    {
        $this->authorizeAccess($request->user(), $domain);

        $data = $request->validate([
            'registrar'  => ['nullable', 'string', 'max:120'],
            'wildcard'   => ['boolean'],
            'auto_renew' => ['boolean'],
            'is_active'  => ['boolean'],
            'cdn_enabled'=> ['boolean'],
        ]);

        $domain->update($data);
        return response()->json($domain);
    }

    public function destroy(Domain $domain): JsonResponse
    {
        $this->authorizeAccess(request()->user(), $domain);
        $domain->delete();
        return response()->json(['message' => 'Domain deleted']);
    }

    private function authorizeAccess($user, Domain $domain): void
    {
        if (! $user) return;
        if ($user->is_admin) return;
        if ($domain->user_id !== $user->id) abort(403);
    }
}
