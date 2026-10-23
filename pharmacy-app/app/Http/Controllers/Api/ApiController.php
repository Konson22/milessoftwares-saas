<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\CurrentBranch;
use App\Support\Industry;
use Illuminate\Http\Request;
use Miles\PlatformClient\Access\CurrentAccess;

/**
 * The API the mobile counter app calls (routes/api.php): JSON only, amounts
 * in minor units, the member named by the app's access token and the branch
 * by X-Branch-Id. Same rules as the web counter: every action goes through
 * the module actions the web controllers use.
 */
abstract class ApiController extends Controller
{
    public function __construct(
        protected readonly CurrentBranch $branch,
        protected readonly Industry $industry,
        protected readonly CurrentAccess $access,
    ) {}

    /**
     * Whether the member holds this industry permission ("sales.void") in
     * the branch being worked in.
     */
    protected function may(string $permission): bool
    {
        return $this->access->get()?->allowsIn($this->industry->permission($permission), branchId: $this->branch->id()) ?? false;
    }

    protected function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
