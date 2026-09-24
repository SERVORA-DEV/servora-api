<?php

namespace App\Http\Requests\Business\BranchSettings;

use Illuminate\Foundation\Http\FormRequest;

class BranchMarketplaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'listing_visible' => 'sometimes|boolean',
            'promo_text' => 'nullable|string|max:80',
            'highlights' => 'sometimes|array|max:12',
            'highlights.*' => 'string|max:40',

            'display' => 'sometimes|array',
            'display.show_prices' => 'sometimes|boolean',
            'display.show_therapist_profiles' => 'sometimes|boolean',
            'display.show_available_slots' => 'sometimes|boolean',
            'display.show_room_availability' => 'sometimes|boolean',
            'display.allow_online_payment' => 'sometimes|boolean',
            'display.allow_promo_codes' => 'sometimes|boolean',
            'display.show_reviews' => 'sometimes|boolean',
            'display.show_rating_badge' => 'sometimes|boolean',
            'display.show_review_photos' => 'sometimes|boolean',
            'display.review_sort' => 'sometimes|in:newest,highest,lowest',
        ];
    }
}
