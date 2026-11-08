<?php

namespace Tests\Unit;

use App\Support\LegalDocuments;
use Tests\TestCase;

class LegalDocumentsTest extends TestCase
{
    public function test_public_terms_do_not_depend_on_unconfirmed_operator_details(): void
    {
        config(['legal.operator' => [
            'name' => '[Company name]', 'address' => '[Business address]',
            'email' => '[Contact email]', 'dpo' => '[DPO name]', 'venue' => '[city, Philippines]',
        ]]);

        $terms = $this->getJson('/api/legal')->assertOk()->json('data.documents.terms');
        array_walk_recursive($terms, function ($value) {
            $this->assertDoesNotMatchRegularExpression('/\[[^\]]+\]|\{[a-z]+\}/', $value);
        });
        $this->assertCount(15, $terms['sections']);
        $this->assertSame('Contact Us', $terms['sections'][14]['heading']);
        $this->assertSame('Email: servoraofcl@gmail.com', $terms['sections'][14]['body'][3]);
        $this->assertSame([], LegalDocuments::unresolved());
    }

    public function test_terms_revision_does_not_change_privacy_content_or_shared_acceptance_version(): void
    {
        $privacyBefore = LegalDocuments::content()['documents']['privacy'];
        $versionBefore = config('legal.version');
        $effectiveBefore = config('legal.effective_date');

        config(['legal.documents.terms.revision_date' => '2026-11-08']);
        $content = $this->getJson('/api/legal')->assertOk()->json('data');

        $this->assertSame('2026-11-08', $content['documents']['terms']['revision_date']);
        $this->assertSame($privacyBefore, $content['documents']['privacy']);
        $this->assertArrayNotHasKey('revision_date', $content['documents']['privacy']);
        $this->assertSame($versionBefore, $content['version']);
        $this->assertSame($effectiveBefore, $content['effective_date']);
    }
}
