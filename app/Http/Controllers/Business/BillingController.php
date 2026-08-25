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

    public function show(Request $request, string $uuid)
    {
        return $this->appointmentService->getBilling($request->user(), $uuid);
    }
}
