<?php

namespace Tests\Unit;

use App\Http\Requests\Business\FacilityRequest;
use App\Http\Requests\Business\PackageRequest;
use App\Http\Requests\Business\ServiceRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rules\Unique;
use Tests\TestCase;

// A deleted facility, service or package no longer holds its name or code (the unique indexes are
// over live rows only, migration 2026_11_15_090000), and a refused form says each problem once.
// No database.
//   php artisan test --filter=CatalogNamesTest
class CatalogNamesTest extends TestCase
{
    public function test_name_and_code_checks_ignore_deleted_rows(): void
    {
        // FacilityRequest asks whether facilities.max_clients exists; answer it without a database.
        \Illuminate\Support\Facades\Cache::setDefaultDriver('array');
        \Illuminate\Support\Facades\Cache::forever('schema:facility_client_limit', true);

        $checks = [
            [FacilityRequest::class, ['name']],
            [ServiceRequest::class, ['name']],
            [PackageRequest::class, ['name']],
        ];
        foreach ($checks as [$class, $fields]) {
            $request = $class::create('/', 'POST');
            $rules = $request->rules();
            // The code is given automatically (SVC-0001, PKG-0001), so a request takes none.
            if ($class !== FacilityRequest::class) {
                $this->assertArrayNotHasKey('code', $rules, "$class still takes a typed code");
            }
            foreach ($fields as $field) {
                $unique = collect($rules[$field])->first(fn ($rule) => $rule instanceof Unique);
                $this->assertNotNull($unique, "$class $field has no unique rule");
                $this->assertStringContainsString('deleted_at,"NULL"', (string) $unique, "$class $field still counts deleted rows");
            }
        }
    }

    public function test_a_refused_form_names_each_problem_once(): void
    {
        Route::post('/api/_catalog_names_test', fn (Request $r) => $r->validate([
            'name' => 'required',
            'code' => 'required',
            'price' => 'required',
        ]));

        $response = $this->postJson('/api/_catalog_names_test', []);

        $response->assertStatus(422);
        $message = $response->json('message');
        $this->assertStringNotContainsString('more error', $message);
        $this->assertStringContainsString('name', $message);
        $this->assertStringContainsString('code', $message);
        $this->assertStringContainsString('price', $message);
        $this->assertArrayHasKey('name', $response->json('errors'));
    }
}
