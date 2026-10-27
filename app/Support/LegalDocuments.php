<?php

namespace App\Support;

// The Terms of Service and Privacy Policy as the apps receive them: the text
// in config/legal.php with the operator's details filled in. Used by
// GET /api/legal and by `php artisan legal:check`.
class LegalDocuments
{
    public static function content(): array
    {
        $operator = config('legal.operator');
        $replace = [
            '{operator}' => $operator['name'],
            '{address}' => $operator['address'],
            '{email}' => $operator['email'],
            '{dpo}' => $operator['dpo'] ?? '',
            '{venue}' => $operator['venue'] ?? '',
        ];
        $fill = fn (string $text) => strtr($text, $replace);

        $documents = [];
        foreach (config('legal.documents') as $key => $document) {
            $documents[$key] = [
                'title' => $document['title'],
                'intro' => $fill($document['intro']),
                // The few facts a reader most needs, before the full text.
                'summary' => array_map($fill, $document['summary'] ?? []),
                'sections' => array_map(fn (array $section) => [
                    'heading' => $section['heading'],
                    // Who the section is for: 'all', 'client' or 'owner'.
                    'audience' => $section['audience'] ?? 'all',
                    // A string is a paragraph; an array is a bulleted list.
                    'body' => array_map(
                        fn ($block) => is_array($block) ? array_map($fill, $block) : $fill($block),
                        $section['body'],
                    ),
                ], $document['sections']),
            ];
        }

        return [
            'version' => config('legal.version'),
            'effective_date' => config('legal.effective_date'),
            'operator' => $operator,
            'documents' => $documents,
        ];
    }

    // Every [bracketed] placeholder or unfilled {tag} still in the text that
    // readers would see, as "where: what".
    public static function unresolved(): array
    {
        $found = [];
        $scan = function (string $where, string $text) use (&$found) {
            if (preg_match_all('/\[[^\]]+\]|\{[a-z]+\}/', $text, $matches)) {
                foreach ($matches[0] as $match) {
                    $found[] = "{$where}: {$match}";
                }
            }
        };

        $content = self::content();
        foreach ($content['documents'] as $key => $document) {
            $scan("{$key} intro", $document['intro']);
            foreach ($document['summary'] as $line) {
                $scan("{$key} summary", $line);
            }
            foreach ($document['sections'] as $section) {
                foreach ($section['body'] as $block) {
                    foreach ((array) $block as $text) {
                        $scan("{$key} · {$section['heading']}", $text);
                    }
                }
            }
        }

        return array_values(array_unique($found));
    }
}
