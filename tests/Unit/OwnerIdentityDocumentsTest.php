<?php

namespace Tests\Unit;

use App\Models\OwnerIdentityVerification;
use PHPUnit\Framework\TestCase;

// Which photos of an ID an owner has to give: front and back, except a passport,
// which has no back.
class OwnerIdentityDocumentsTest extends TestCase
{
    private function identity(?string $type, ?string $front, ?string $back): OwnerIdentityVerification
    {
        return new OwnerIdentityVerification([
            'id_type' => $type,
            'id_document_front_path' => $front,
            'id_document_back_path' => $back,
        ]);
    }

    public function test_only_a_passport_goes_without_a_back(): void
    {
        $this->assertFalse(OwnerIdentityVerification::backRequired('Passport'));

        foreach (['Philippine National ID', 'Drivers License', 'UMID', 'Other', null] as $type) {
            $this->assertTrue(OwnerIdentityVerification::backRequired($type), (string) $type);
        }
    }

    public function test_a_passport_is_complete_with_the_front_alone(): void
    {
        $this->assertTrue($this->identity('Passport', 'f', null)->hasIdDocuments());
        $this->assertTrue($this->identity('Passport', 'f', 'b')->hasIdDocuments());
        $this->assertFalse($this->identity('Passport', null, null)->hasIdDocuments());
    }

    public function test_any_other_id_needs_both_sides(): void
    {
        $this->assertFalse($this->identity('UMID', 'f', null)->hasIdDocuments());
        $this->assertFalse($this->identity('UMID', null, 'b')->hasIdDocuments());
        $this->assertTrue($this->identity('UMID', 'f', 'b')->hasIdDocuments());
        // Nothing chosen yet: asked for like any other.
        $this->assertFalse($this->identity(null, 'f', null)->hasIdDocuments());
    }
}
