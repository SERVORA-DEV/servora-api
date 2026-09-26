<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\ClientCancelRequest;
use App\Http\Requests\Client\ClientRescheduleRequest;
use App\Http\Requests\Client\ClientReviewRequest;
use App\Service\Client\ClientBookingService;
use Illuminate\Http\Request;

class ClientBookingController extends Controller
{
    public function __construct(private ClientBookingService $bookings)
    {
    }

    public function index(Request $request)
    {
        $request->validate([
            'scope' => ['nullable', 'in:upcoming,past,cancelled,all'],
            'per_page' => ['nullable', 'integer', 'between:1,50'],
        ]);

        return $this->bookings->list($request->user(), $request->query('scope'), (int) $request->query('per_page', 20));
    }

    public function show(Request $request, string $uuid)
    {
        return $this->bookings->show($request->user(), $uuid);
    }

    public function cancel(ClientCancelRequest $request, string $uuid)
    {
        return $this->bookings->cancel($request->user(), $uuid, $request->validated('reason'));
    }

    public function reschedule(ClientRescheduleRequest $request, string $uuid)
    {
        return $this->bookings->reschedule($request->user(), $uuid, $request->validated());
    }

    public function queue(Request $request, string $uuid)
    {
        return $this->bookings->queue($request->user(), $uuid);
    }

    public function review(ClientReviewRequest $request, string $uuid)
    {
        return $this->bookings->review($request->user(), $uuid, $request->validated());
    }

    public function reviews(Request $request)
    {
        return $this->bookings->myReviews($request->user());
    }
}
