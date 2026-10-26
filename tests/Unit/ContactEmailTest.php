<?php

namespace Tests\Unit;

use App\Support\ContactEmail;
use PHPUnit\Framework\TestCase;

// The email put in Settings for the business or a branch is confirmed by a
// button in an email. Addresses are compared without regard to case or spaces,
// so the owner's own sign-up address is recognised however it is typed.
class ContactEmailTest extends TestCase
{
    public function test_addresses_are_compared_without_case_or_spaces(): void
    {
        $this->assertSame('jake@gmail.com', ContactEmail::normalize('  Jake@Gmail.COM '));
        $this->assertSame('', ContactEmail::normalize(null));
        $this->assertSame(ContactEmail::normalize('A@b.co'), ContactEmail::normalize(' a@B.CO'));
    }

    public function test_the_link_is_for_one_address_only(): void
    {
        // The hash in the link is of the normalised address, so a link sent to
        // one address does not confirm another.
        $this->assertSame(sha1('front@example.test'), sha1(ContactEmail::normalize('Front@Example.test')));
        $this->assertNotSame(sha1('front@example.test'), sha1(ContactEmail::normalize('second@example.test')));
    }
}
