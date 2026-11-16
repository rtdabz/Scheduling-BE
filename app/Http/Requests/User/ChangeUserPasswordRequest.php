<?php

namespace App\Http\Requests\User;

use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * VPAA-set password for another account. Separate from the self-service Forgot/Reset Password
 * flow: no token is involved, and the strength rule matches the one that flow enforces.
 */
class ChangeUserPasswordRequest extends FormRequest
{
    public function authorize(): Response
    {
        // The route group already requires role:vpaa; this check keeps the action VPAA-only even
        // if the route is ever moved out of that group.
        if (! $this->user()?->isVpaa()) {
            return Response::deny('Only the VPAA can change another user\'s password.');
        }

        return $this->targetUser()->isVpaa()
            ? Response::deny('The VPAA account cannot be changed here.')
            : Response::allow();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'max:255', 'confirmed', Password::min(10)->letters()->mixedCase()->numbers()],
        ];
    }

    private function targetUser(): User
    {
        return $this->route('user');
    }
}
