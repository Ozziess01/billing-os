<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\MemberRequest;
use App\Http\Requests\OrganizationRequest;
use App\Http\Resources\MemberResource;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Services\OrganizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrganizationController extends Controller
{
    public function __construct(private readonly OrganizationService $organizations) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return OrganizationResource::collection(
            $request->user()->organizations()->withCount('members')->orderBy('name')->get()
        );
    }

    public function store(OrganizationRequest $request): JsonResponse
    {
        $organization = $this->organizations->create(
            $request->user(),
            $request->validated('name'),
            $request->validated('default_currency'),
        );

        return (new OrganizationResource($organization->loadCount('members')))->response()->setStatusCode(201);
    }

    public function show(Organization $organization): OrganizationResource
    {
        $this->authorize('view', $organization);

        return new OrganizationResource($organization->loadCount('members'));
    }

    public function update(OrganizationRequest $request, Organization $organization): OrganizationResource
    {
        $this->authorize('update', $organization);

        $organization->update($request->validated());

        return new OrganizationResource($organization->loadCount('members'));
    }

    public function members(Organization $organization): AnonymousResourceCollection
    {
        $this->authorize('view', $organization);

        return MemberResource::collection($organization->members()->with('user')->orderBy('created_at')->get());
    }

    public function addMember(MemberRequest $request, Organization $organization): JsonResponse
    {
        $this->authorize('manageMembers', $organization);

        $member = $this->organizations->addMember($organization, $request->validated('email'), Role::from($request->validated('role')));

        return (new MemberResource($member->load('user')))->response()->setStatusCode(201);
    }

    public function updateMember(MemberRequest $request, Organization $organization, OrganizationMember $member): MemberResource
    {
        $this->authorize('manageMembers', $organization);
        abort_unless($member->organization_id === $organization->id, 404);

        return new MemberResource($this->organizations->changeRole($member, Role::from($request->validated('role')))->load('user'));
    }

    public function removeMember(Request $request, Organization $organization, OrganizationMember $member): JsonResponse
    {
        $this->authorize('view', $organization);
        abort_unless($member->organization_id === $organization->id, 404);

        // выйти из организации может любой участник, кроме владельца; удалять других - admin+
        if ($member->user_id !== $request->user()->id) {
            $this->authorize('manageMembers', $organization);
        }

        $this->organizations->removeMember($member);

        return response()->json(null, 204);
    }

    public function transfer(Request $request, Organization $organization): OrganizationResource
    {
        $this->authorize('transfer', $organization);

        $data = $request->validate(['user_id' => ['required', 'integer']]);
        $this->organizations->transferOwnership($organization, User::query()->findOrFail($data['user_id']));

        return new OrganizationResource($organization->refresh()->loadCount('members'));
    }
}
