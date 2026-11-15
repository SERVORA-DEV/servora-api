<?php

namespace App\Http\Controllers;

use App\Service\ServoraReviewService;
use Illuminate\Http\Request;

// Owners' reviews of SERVORA: the owner's own one, the public list, and the
// system administrator's publish / hide (ServoraReviewService).
class ServoraReviewController extends Controller
{
    public function __construct(private ServoraReviewService $reviews) {}

    public function mine(Request $request)
    {
        return $this->reviews->mine($request->user());
    }

    public function save(Request $request)
    {
        $data = $request->validate([
            'rating' => 'required|integer|between:1,5',
            'title' => 'nullable|string|max:80',
            'body' => 'required|string|min:20|max:1200',
            'show_business' => 'sometimes|boolean',
        ], [
            'body.min' => 'Write a little more: at least 20 characters.',
        ]);

        return $this->reviews->save($request->user(), $data, $request);
    }

    public function published()
    {
        return $this->reviews->published();
    }

    public function adminIndex(Request $request)
    {
        $status = (string) $request->query('status', 'Pending');

        return $this->reviews->adminList(in_array($status, ['Pending', 'Published', 'Hidden', 'All'], true) ? $status : 'Pending');
    }

    public function publish(Request $request, string $uuid)
    {
        return $this->reviews->setStatus($request->user(), $uuid, 'Published', $request);
    }

    public function hide(Request $request, string $uuid)
    {
        return $this->reviews->setStatus($request->user(), $uuid, 'Hidden', $request);
    }
}
