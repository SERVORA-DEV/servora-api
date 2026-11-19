<?php

namespace App\Http\Requests\Client;

use Illuminate\Foundation\Http\FormRequest;

// POST api/client/rewards/redeem (ClientRewardsController::redeem).
class RedeemRewardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['program_uuid' => ['required', 'uuid']];
    }
}
