<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSystemSettingRequest;
use App\Service\System\SystemSettingService;

class SystemSettingController extends Controller
{
    private SystemSettingService $systemSettingService;

    public function __construct(SystemSettingService $systemSettingService)
    {
        $this->systemSettingService = $systemSettingService;
    }

    public function show()
    {
        return $this->systemSettingService->getSettings();
    }

    public function update(UpdateSystemSettingRequest $request)
    {
        return $this->systemSettingService->updateSettings($request->validated());
    }
}
