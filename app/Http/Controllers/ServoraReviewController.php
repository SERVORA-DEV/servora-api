<?php

namespace App\Http\Controllers;

use App\Enums\ServoraReviewStatus;
use App\Http\Requests\ServoraReviewRequest;
use App\Service\ServoraReviewService;
use Illuminate\Http\Request;

// Owners' reviews of SERVORA: the owner's own one, the public list, and the
// system administrator's controls for public visibility.
class ServoraReviewController extends Controller
{
    public function __construct(private ServoraReviewService $reviews) {}

    public function mine(Request $request)
    {
        return $this->reviews->mine($request->user());
    }

    public function save(ServoraReviewRequest $request)
    {
        $data = $request->validated();

        return $this->reviews->save($request->user(), $data, $request);
    }

    public function published()
    {
        return $this->reviews->published();
    }

    public function adminIndex(Request $request)
    {
        $status = $request->query('status', 'All');

        return $this->reviews->adminList(in_array($status, [ServoraReviewStatus::Published->value, ServoraReviewStatus::Hidden->value], true) ? $status : 'All');
    }

    public function adminShow(string $uuid)
    {
        return $this->reviews->adminShow($uuid);
    }

    public function publish(Request $request, string $uuid)
    {
        return $this->reviews->setStatus($request->user(), $uuid, ServoraReviewStatus::Published->value, $request);
    }

    public function hide(Request $request, string $uuid)
    {
        return $this->reviews->setStatus($request->user(), $uuid, ServoraReviewStatus::Hidden->value, $request);
    }
}
