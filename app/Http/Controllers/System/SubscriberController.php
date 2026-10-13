<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Service\System\SubscriberService;

// Read-only: who is subscribed and where each subscription stands — the
// Subscribers tab beside Plans in the administrator's Subscriptions area.
class SubscriberController extends Controller
{
    public function __construct(private SubscriberService $subscribers) {}

    public function index()
    {
        return $this->subscribers->list();
    }
}
