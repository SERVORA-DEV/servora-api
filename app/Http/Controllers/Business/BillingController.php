<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Service\Business\AppointmentService;
use Illuminate\Http\Request;

class BillingController extends Controller
{
    private AppointmentService $appointmentService;

    public function __construct(AppointmentService $appointmentService)
    {
        $this->appointmentService = $appointmentService;
    }

    public function index(Request $request)
    {
        $filters = $request->only(['status', 'payment_method', 'date_from', 'date_to', 'search']);
        return $this->appointmentService->listBillings($request->user(), $filters, $request->input('per_page', 15));
    }

    public function show(Request $request, string $uuid)
    {
        return $this->appointmentService->getBilling($request->user(), $uuid);
    }
}
