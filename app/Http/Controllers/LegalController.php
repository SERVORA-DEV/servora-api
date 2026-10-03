<?php

namespace App\Http\Controllers;

// GET /api/legal — the Terms of Service and Privacy Policy, for the web app's
// /terms and /privacy pages and the mobile app's Terms screen. Public: people
// read these before they have an account. The text lives in config/legal.php.
class LegalController extends Controller
{
    public function show()
    {
        $operator = config('legal.operator');
        $replace = [
            '{operator}' => $operator['name'],
            '{address}' => $operator['address'],
            '{email}' => $operator['email'],
        ];
        $fill = fn (string $text) => strtr($text, $replace);

        $documents = [];
        foreach (config('legal.documents') as $key => $document) {
            $documents[$key] = [
                'title' => $document['title'],
                'intro' => $fill($document['intro']),
                'sections' => array_map(fn (array $section) => [
                    'heading' => $section['heading'],
                    // A string is a paragraph; an array is a bulleted list.
                    'body' => array_map(
                        fn ($block) => is_array($block) ? array_map($fill, $block) : $fill($block),
                        $section['body'],
                    ),
                ], $document['sections']),
            ];
        }

        return response()->json([
            'data' => [
                'version' => config('legal.version'),
                'effective_date' => config('legal.effective_date'),
                'operator' => $operator,
                'documents' => $documents,
            ],
        ]);
    }
}
