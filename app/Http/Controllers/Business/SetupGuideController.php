<?php

namespace App\Http\Controllers\Business;

use App\Http\Controllers\Controller;
use App\Models\BusinessSetupGuide;
use App\Repository\SpaBusinessRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// The Management setup guide's progress for the signed-in user's business.
// GET reads it; PUT adds to it. Done steps are only ever added, never removed,
// so two devices saving at once can't undo each other. Before the migration
// has run, both answer with empty progress and save nothing.
class SetupGuideController extends Controller
{
    private const MAX_STEPS = 40;

    public function __construct(private SpaBusinessRepository $businesses)
    {
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present($this->find($request))]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'done' => ['present', 'array', 'max:' . self::MAX_STEPS],
            'done.*' => ['string', 'max:40', 'regex:/^[a-z][a-z0-9-]*$/'],
            'skipped' => ['present', 'array', 'max:' . self::MAX_STEPS],
            'skipped.*' => ['string', 'max:40', 'regex:/^[a-z][a-z0-9-]*$/'],
            'finished' => ['required', 'boolean'],
        ]);

        $business = $this->businesses->findForUser($request->user());
        if (! $business || ! BusinessSetupGuide::ready()) {
            return response()->json(['data' => $this->present(null)]);
        }

        $row = BusinessSetupGuide::firstOrNew(['spa_business_id' => $business->id]);
        $done = array_values(array_unique(array_merge($row->done ?? [], $data['done'])));
        $row->done = array_slice($done, 0, self::MAX_STEPS);
        $row->skipped = array_values(array_diff(array_unique($data['skipped']), $row->done));
        $row->finished = (bool) $data['finished'];
        $row->save();

        return response()->json(['data' => $this->present($row)]);
    }

    private function find(Request $request): ?BusinessSetupGuide
    {
        $business = $this->businesses->findForUser($request->user());
        if (! $business || ! BusinessSetupGuide::ready()) {
            return null;
        }

        return BusinessSetupGuide::where('spa_business_id', $business->id)->first();
    }

    private function present(?BusinessSetupGuide $row): array
    {
        return [
            'ready' => BusinessSetupGuide::ready(),
            'done' => $row?->done ?? [],
            'skipped' => $row?->skipped ?? [],
            'finished' => (bool) ($row?->finished ?? false),
        ];
    }
}
