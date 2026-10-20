<?php

namespace Tests\Unit;

use App\Models\SpaBranch;
use App\Models\SpaBusiness;
use App\Support\SocialLinks;
use PHPUnit\Framework\TestCase;

// Turning what an owner typed into links people can open, and which link a
// branch shows (its own, else the company's). No database.
class SocialLinksTest extends TestCase
{
    public function test_facebook(): void
    {
        $this->assertSame('https://facebook.com/lotushaven', SocialLinks::url('facebook', 'lotushaven'));
        $this->assertSame('https://facebook.com/lotushaven', SocialLinks::url('facebook', 'facebook.com/lotushaven'));
        $this->assertSame('https://www.facebook.com/lotushaven', SocialLinks::url('facebook', 'https://www.facebook.com/lotushaven'));
        $this->assertSame('https://fb.me/lotus', SocialLinks::url('facebook', 'fb.me/lotus'));
        $this->assertNull(SocialLinks::url('facebook', 'https://example.com/lotushaven'));
        $this->assertNull(SocialLinks::url('facebook', 'https://evilfacebook.com/x'));
        $this->assertNull(SocialLinks::url('facebook', 'javascript:alert(1)'));
        $this->assertNull(SocialLinks::url('facebook', 'lotus haven'));
    }

    public function test_instagram(): void
    {
        $this->assertSame('https://instagram.com/lotus.haven', SocialLinks::url('instagram', '@lotus.haven'));
        $this->assertSame('https://instagram.com/lotus.haven', SocialLinks::url('instagram', 'lotus.haven'));
        $this->assertSame('https://instagram.com/lotus.haven', SocialLinks::url('instagram', 'instagram.com/lotus.haven'));
        $this->assertSame('https://www.instagram.com/lotus_haven/', SocialLinks::url('instagram', 'https://www.instagram.com/lotus_haven/'));
        $this->assertNull(SocialLinks::url('instagram', 'https://example.com/lotus'));
        $this->assertNull(SocialLinks::url('instagram', '@' . str_repeat('a', 31)));
        $this->assertNull(SocialLinks::url('instagram', '@lotus haven'));
    }

    public function test_website(): void
    {
        $this->assertSame('https://lotushaven.ph', SocialLinks::url('website', 'lotushaven.ph'));
        $this->assertSame('http://lotushaven.ph/spa?x=1', SocialLinks::url('website', 'http://lotushaven.ph/spa?x=1'));
        $this->assertNull(SocialLinks::url('website', 'javascript:alert(1)'));
        $this->assertNull(SocialLinks::url('website', 'ftp://lotushaven.ph'));
        $this->assertNull(SocialLinks::url('website', 'notaurl'));
        $this->assertNull(SocialLinks::url('website', 'https://user:pass@lotushaven.ph'));
        $this->assertNull(SocialLinks::url('website', 'lotus haven.ph'));
    }

    public function test_empty_is_valid_and_has_no_link(): void
    {
        $this->assertNull(SocialLinks::url('website', '  '));
        $this->assertNull(SocialLinks::url('website', null));
        $this->assertTrue(SocialLinks::valid('website', ''));
        $this->assertTrue(SocialLinks::valid('facebook', null));
        $this->assertTrue(SocialLinks::valid('website', 'lotushaven.ph'));
        $this->assertFalse(SocialLinks::valid('website', 'javascript:alert(1)'));
    }

    public function test_labels(): void
    {
        $this->assertSame('Facebook', SocialLinks::label('facebook', 'https://facebook.com/lotushaven'));
        $this->assertSame('@lotus.haven', SocialLinks::label('instagram', 'https://instagram.com/lotus.haven'));
        $this->assertSame('lotushaven.ph', SocialLinks::label('website', 'https://www.lotushaven.ph/spa'));
    }

    public function test_a_branch_shows_its_own_links_then_the_companys(): void
    {
        $branch = new SpaBranch(['facebook_url' => 'facebook.com/lotus-davao', 'instagram_handle' => null, 'website_url' => 'not a link']);
        $branch->setRelation('business', new SpaBusiness([
            'facebook_url' => 'facebook.com/lotus', 'instagram_handle' => '@lotus', 'website_url' => 'lotus.ph',
        ]));

        $links = SocialLinks::forBranch($branch);

        $this->assertSame(['facebook', 'instagram', 'website'], array_keys($links));
        $this->assertSame(['url' => 'https://facebook.com/lotus-davao', 'label' => 'Facebook', 'source' => 'branch'], $links['facebook']);
        $this->assertSame('company', $links['instagram']['source']);
        $this->assertSame('https://instagram.com/lotus', $links['instagram']['url']);
        $this->assertSame('company', $links['website']['source']);
    }

    public function test_a_kind_nobody_set_is_left_out(): void
    {
        $branch = new SpaBranch(['website_url' => 'lotus.ph']);
        $branch->setRelation('business', new SpaBusiness([]));

        $this->assertSame(['website'], array_keys(SocialLinks::forBranch($branch)));
        $this->assertSame([], SocialLinks::forBranch((new SpaBranch())->setRelation('business', null)));
    }
}
