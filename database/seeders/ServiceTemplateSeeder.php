<?php

namespace Database\Seeders;

use App\Models\Service;
use App\Models\User;
use App\Service\System\ServiceTemplateService;
use Illuminate\Database\Seeder;

// The platform's starter catalog of service templates (System → Service
// Templates): what owners pick from when adding a service. Prices are
// suggestions in ₱; loyalty_points are the suggested bonus points a client
// earns on top of the spa's points per ₱. Created through the admin's own
// ServiceTemplateService, and skipped by name when a template already
// exists, so re-running only adds what's missing.
class ServiceTemplateSeeder extends Seeder
{
    public const TEMPLATES = [
        // ── Massage ─────────────────────────────────────────────────────
        ['name' => 'Swedish Massage', 'code' => 'SWM', 'category' => 'Massage',
            'description' => 'Gentle full-body massage with long, flowing strokes to ease tension and help you relax.',
            'variants' => [[60, 800, 10], [90, 1100, 15], [120, 1400, 20]]],
        ['name' => 'Deep Tissue Massage', 'code' => 'DTM', 'category' => 'Massage',
            'description' => 'Firm, focused pressure that works into deeper muscle layers to release chronic knots.',
            'variants' => [[60, 950, 10], [90, 1300, 15]]],
        ['name' => 'Hilot', 'code' => 'HLT', 'category' => 'Massage',
            'description' => 'Traditional Filipino healing massage using warm banana leaves and coconut oil.',
            'variants' => [[60, 850, 10], [90, 1150, 15]]],
        ['name' => 'Shiatsu Massage', 'code' => 'SHI', 'category' => 'Massage',
            'description' => 'Japanese finger-pressure massage along the body\'s energy lines, done over light clothing.',
            'variants' => [[60, 900, 10], [90, 1250, 15]]],
        ['name' => 'Thai Massage', 'code' => 'THM', 'category' => 'Massage',
            'description' => 'Assisted stretching and rhythmic pressure to improve flexibility and energy.',
            'variants' => [[60, 900, 10], [90, 1250, 15]]],
        ['name' => 'Hot Stone Massage', 'code' => 'HSM', 'category' => 'Massage',
            'description' => 'Heated basalt stones and massage to melt tension and improve circulation.',
            'variants' => [[75, 1300, 20], [90, 1500, 25]]],
        ['name' => 'Foot Reflexology', 'code' => 'FRF', 'category' => 'Massage',
            'description' => 'Pressure-point foot massage that relaxes the whole body.',
            'variants' => [[30, 400, 5], [60, 650, 8]]],
        ['name' => 'Back and Shoulder Massage', 'code' => 'BSM', 'category' => 'Massage',
            'description' => 'A focused massage for the neck, shoulders and back — great for desk workers.',
            'variants' => [[30, 450, 5], [45, 600, 8]]],

        // ── Facial ──────────────────────────────────────────────────────
        ['name' => 'Classic Facial', 'code' => 'CFC', 'category' => 'Facial',
            'description' => 'Cleansing, exfoliation, extraction and hydration for a fresh, even glow.',
            'variants' => [[60, 900, 10]]],
        ['name' => 'Anti-Aging Facial', 'code' => 'AAF', 'category' => 'Facial',
            'description' => 'Collagen-boosting treatment that softens fine lines and evens skin tone.',
            'variants' => [[75, 1500, 20]]],
        ['name' => 'Acne Clearing Facial', 'code' => 'ACF', 'category' => 'Facial',
            'description' => 'Deep-pore cleansing and calming mask for oily and acne-prone skin.',
            'variants' => [[60, 1100, 12]]],
        ['name' => 'Diamond Peel', 'code' => 'DPL', 'category' => 'Facial',
            'description' => 'Microdermabrasion that buffs away dead skin for a smoother, brighter face.',
            'variants' => [[45, 1200, 12]]],

        // ── Body Treatment ──────────────────────────────────────────────
        ['name' => 'Body Scrub', 'code' => 'BSC', 'category' => 'Body Treatment',
            'description' => 'Full-body exfoliation with salt or sugar scrub for soft, renewed skin.',
            'variants' => [[45, 900, 10], [60, 1100, 12]]],
        ['name' => 'Body Wrap', 'code' => 'BWR', 'category' => 'Body Treatment',
            'description' => 'Detoxifying seaweed or clay wrap that hydrates and firms the skin.',
            'variants' => [[60, 1300, 15]]],

        // ── Hair ────────────────────────────────────────────────────────
        ['name' => 'Hair Spa Treatment', 'code' => 'HST', 'category' => 'Hair',
            'description' => 'Deep conditioning with a scalp massage to restore shine and strength.',
            'variants' => [[60, 700, 8]]],
        ['name' => 'Keratin Treatment', 'code' => 'KRT', 'category' => 'Hair',
            'description' => 'Smoothing treatment that tames frizz and adds lasting shine.',
            'variants' => [[120, 2500, 30]]],
        ['name' => 'Haircut and Blow-dry', 'code' => 'HCB', 'category' => 'Hair',
            'description' => 'Wash, cut and style by a stylist.',
            'variants' => [[45, 450, 5]]],

        // ── Nails ───────────────────────────────────────────────────────
        ['name' => 'Classic Manicure', 'code' => 'CMN', 'category' => 'Nails',
            'description' => 'Nail shaping, cuticle care and polish for neat, healthy-looking hands.',
            'variants' => [[30, 250, 3]]],
        ['name' => 'Classic Pedicure', 'code' => 'CPD', 'category' => 'Nails',
            'description' => 'A foot soak with nail shaping, cuticle care and polish.',
            'variants' => [[45, 350, 4]]],
        ['name' => 'Gel Manicure', 'code' => 'GMN', 'category' => 'Nails',
            'description' => 'Long-lasting, chip-resistant gel polish over a classic manicure.',
            'variants' => [[60, 600, 6]]],
        ['name' => 'Foot Spa', 'code' => 'FSP', 'category' => 'Nails',
            'description' => 'Warm soak, scrub, callus care and a foot massage.',
            'variants' => [[45, 450, 5]]],

        // ── Waxing ──────────────────────────────────────────────────────
        ['name' => 'Underarm Waxing', 'code' => 'UAW', 'category' => 'Waxing',
            'description' => 'Quick, clean hair removal for smooth underarms.',
            'variants' => [[15, 300, 3]]],
        ['name' => 'Full Leg Waxing', 'code' => 'FLW', 'category' => 'Waxing',
            'description' => 'Hair removal from ankle to upper thigh.',
            'variants' => [[45, 900, 10]]],
        ['name' => 'Brazilian Waxing', 'code' => 'BRW', 'category' => 'Waxing',
            'description' => 'Complete bikini-area hair removal by a trained specialist.',
            'variants' => [[30, 1000, 10]]],

        // ── Lash & Brow ─────────────────────────────────────────────────
        ['name' => 'Lash Lift and Tint', 'code' => 'LLT', 'category' => 'Lash & Brow',
            'description' => 'Curls and darkens natural lashes for a wide-eyed look without extensions.',
            'variants' => [[60, 1200, 12]]],
        ['name' => 'Classic Lash Extensions', 'code' => 'CLE', 'category' => 'Lash & Brow',
            'description' => 'One extension per natural lash for fuller, longer lashes.',
            'variants' => [[90, 1500, 15]]],
        ['name' => 'Brow Shaping', 'code' => 'BRS', 'category' => 'Lash & Brow',
            'description' => 'Threading or waxing to shape and define the brows.',
            'variants' => [[20, 300, 3]]],

        // ── Makeup ──────────────────────────────────────────────────────
        ['name' => 'Event Makeup', 'code' => 'EVM', 'category' => 'Makeup',
            'description' => 'Full-face makeup for parties, photoshoots and special occasions.',
            'variants' => [[60, 1500, 15]]],
        ['name' => 'Bridal Makeup', 'code' => 'BRM', 'category' => 'Makeup',
            'description' => 'Long-wear bridal look with a trial consultation.',
            'variants' => [[120, 4500, 50]]],

        // ── Couples ─────────────────────────────────────────────────────
        ['name' => 'Couples Massage', 'code' => 'CPM', 'category' => 'Couples',
            'description' => 'Side-by-side massage for two in a private couples suite.',
            'variants' => [[60, 1700, 20], [90, 2300, 30]]],

        // ── VIP ─────────────────────────────────────────────────────────
        ['name' => 'VIP Royal Ritual', 'code' => 'VIP', 'category' => 'VIP',
            'description' => 'Body scrub, hot stone massage and facial in a private VIP suite, with tea service.',
            'variants' => [[180, 4500, 60]]],

        // ── Wellness ────────────────────────────────────────────────────
        ['name' => 'Ventosa Cupping', 'code' => 'VEN', 'category' => 'Wellness',
            'description' => 'Traditional cupping therapy to ease muscle pain and improve blood flow.',
            'variants' => [[45, 700, 8], [60, 900, 10]]],
        ['name' => 'Aromatherapy Session', 'code' => 'ARO', 'category' => 'Wellness',
            'description' => 'Relaxation massage with essential oils chosen for your mood.',
            'variants' => [[60, 950, 10], [90, 1300, 15]]],
        ['name' => 'Ear Candling', 'code' => 'EAR', 'category' => 'Wellness',
            'description' => 'Gentle, calming ear candling with a light head massage.',
            'variants' => [[30, 450, 5]]],
    ];

    public function run(): void
    {
        $admin = User::where('role', 'system_administrator')->orderBy('id')->first();
        if (! $admin) {
            $this->command->warn('No system administrator yet — run DatabaseSeeder first.');

            return;
        }

        $templates = app(ServiceTemplateService::class);
        $created = 0;

        foreach (self::TEMPLATES as $t) {
            $exists = Service::where('is_template', true)->whereNull('spa_business_id')
                ->where(fn ($q) => $q->where('name', $t['name'])->orWhere('code', $t['code']))
                ->exists();
            if ($exists) {
                continue;
            }

            $templates->createTemplate($admin, [
                'name' => $t['name'],
                'code' => $t['code'],
                'category' => $t['category'],
                'description' => $t['description'],
                'is_active' => true,
                'variants' => array_map(fn ($v) => [
                    'duration_minutes' => $v[0],
                    'price' => $v[1],
                    'loyalty_points' => $v[2],
                ], $t['variants']),
            ]);
            $created++;
        }

        $this->command->info("Service templates: {$created} created, " . (count(self::TEMPLATES) - $created) . ' already there.');
    }
}
