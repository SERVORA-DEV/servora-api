<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// A read-only report, separate from the pending queue and verified-branch list.
class RegistrationReportController extends Controller
{
    public function __invoke(Request $request)
    {
        $input = $request->validate(['per_page' => 'sometimes|integer|min:1|max:250', 'page' => 'sometimes|integer|min:1']);
        $submissions = fn (string $table) => DB::table('audit_logs')
            ->where('table_name', $table)->where('action', 'Submit')
            ->select('record_id')->selectRaw('MIN(created_at) AS submitted_at')->groupBy('record_id');
        $statuses = ['Pending', 'Verified', 'Rejected', 'Suspended'];

        $businesses = DB::table('spa_businesses as b')
            ->leftJoin('users as u', 'u.id', '=', 'b.owner_id')
            ->leftJoinSub($submissions('spa_businesses'), 's', 's.record_id', '=', 'b.id')
            ->whereNull('b.deleted_at')
            ->where(fn ($q) => $q->whereIn('b.verification_status', $statuses)->orWhereNotNull('s.submitted_at'))
            ->selectRaw("'business' AS type, b.uuid, b.uuid AS business_uuid, b.business_name, NULL AS branch_name,
                u.first_name AS owner_first_name, u.last_name AS owner_last_name, b.verification_status AS status,
                COALESCE(s.submitted_at, b.created_at) AS registered_at,
                CASE WHEN s.submitted_at IS NULL THEN 'created' ELSE 'submitted' END AS date_basis");
        $branches = DB::table('spa_branches as r')
            ->join('spa_businesses as b', 'b.id', '=', 'r.spa_business_id')
            ->leftJoin('users as u', 'u.id', '=', 'b.owner_id')
            ->leftJoinSub($submissions('spa_branches'), 's', 's.record_id', '=', 'r.id')
            ->whereNull('b.deleted_at')->whereNull('r.deleted_at')
            ->where(fn ($q) => $q->whereIn('r.verification_status', $statuses)->orWhereNotNull('s.submitted_at'))
            ->selectRaw("'branch' AS type, r.uuid, b.uuid AS business_uuid, b.business_name, r.branch_name,
                u.first_name AS owner_first_name, u.last_name AS owner_last_name, r.verification_status AS status,
                COALESCE(s.submitted_at, r.created_at) AS registered_at,
                CASE WHEN s.submitted_at IS NULL THEN 'created' ELSE 'submitted' END AS date_basis");

        $rows = DB::query()->fromSub($businesses->unionAll($branches), 'registrations')
            ->orderByDesc('registered_at')->orderBy('type')->orderBy('uuid')
            ->paginate($input['per_page'] ?? 100)
            ->through(function ($row) {
                $row->registered_at = $row->registered_at ? Carbon::parse($row->registered_at)->toIso8601String() : null;
                return $row;
            });

        return response()->json($rows);
    }
}
