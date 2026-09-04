<?php

namespace App\Modules\AI\Services;

use App\Modules\AI\Models\AiKbDocument;
use App\Services\StorageManager;
use Illuminate\Support\Facades\Http;
use League\HTMLToMarkdown\HtmlConverter;
use Smalot\PdfParser\Parser;
use ZipArchive;

class KnowledgeSourceExtractor
{
    public function __construct(
        private readonly StorageManager $storage,
        private readonly KnowledgeUrlGuard $urls,
    ) {}

    public function extract(AiKbDocument $document): string
    {
        $text = match ($document->source_type) {
            'text', 'video' => (string) $document->source_ref,
            'faq' => $this->formatFaq((string) $document->source_ref),
            'url' => $this->fetchUrl((string) $document->source_ref),
            'file' => $this->readFile((string) $document->source_ref),
            default => (string) $document->source_ref,
        };

        return $this->normalize($text);
    }

    public function fetchUrl(string $url): string
    {
        $url = $this->urls->assertSafe($url);
        $redirects = 0;
        while (true) {
            $connectedIp = null;
            $response = Http::withOptions([
                'allow_redirects' => false,
                'on_stats' => function ($stats) use (&$connectedIp): void {
                    $connectedIp = $stats->getHandlerStats()['primary_ip'] ?? null;
                },
            ])
                ->withHeaders([
                    'User-Agent' => 'WisperBotKnowledgeIndexer/2.0 (+https://wisperbot.com)',
                    'Accept' => 'text/html,application/xhtml+xml,text/plain;q=0.9,*/*;q=0.5',
                ])->timeout(20)->get($url);
            if ($connectedIp !== null) {
                $this->urls->assertPublicIp($connectedIp);
            }
            if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                if (++$redirects > 3) {
                    throw new \RuntimeException('URL indexing failed: too many redirects.');
                }
                $location = $response->header('Location');
                if (! is_string($location) || ! str_starts_with($location, 'https://')) {
                    throw new \RuntimeException('URL indexing failed: unsafe redirect.');
                }
                $url = $this->urls->assertSafe($location);

                continue;
            }
            if (! $response->successful()) {
                if (in_array($response->status(), [401, 403], true)) {
                    throw new \RuntimeException('URL indexing failed: the website blocked automated access. Allow WisperBotKnowledgeIndexer or upload a reviewed file instead.');
                }
                if ($response->status() === 429) {
                    throw new \RuntimeException('URL indexing failed: the website temporarily rate-limited indexing. Wait a few minutes and retry.');
                }
                throw new \RuntimeException('URL indexing failed with HTTP '.$response->status().'.');
            }
            $contentType = strtolower((string) $response->header('Content-Type'));
            if ($contentType !== '' && ! str_contains($contentType, 'text/html') && ! str_contains($contentType, 'text/plain')) {
                throw new \RuntimeException('URL indexing failed: the page is not readable HTML or text.');
            }
            if (strlen($response->body()) > 5_000_000) {
                throw new \RuntimeException('URL indexing failed: the page is too large.');
            }

            $mediaLinks = $this->mediaLinksFromHtml($response->body());
            $html = preg_replace('/<(script|style|noscript|svg|canvas|iframe|nav|footer|form)\b[^>]*>.*?<\/\1>/is', ' ', $response->body()) ?? $response->body();
            $html = preg_replace('/<!--.*?-->/s', ' ', $html) ?? $html;
            $converter = new HtmlConverter(['strip_tags' => true]);

            return trim($converter->convert($html).($mediaLinks === [] ? '' : "\n\nEmbedded media:\n".implode("\n", $mediaLinks)));
        }
    }

    private function readFile(string $path): string
    {
        $disk = $this->storage->disk();
        if ($path === '' || ! $disk->exists($path)) {
            throw new \RuntimeException('The uploaded knowledge source is no longer available.');
        }
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $contents = (string) $disk->get($path);

        return match ($extension) {
            'pdf' => $this->readPdf($contents),
            'docx' => $this->readDocx($contents),
            'xlsx' => $this->readXlsx($contents),
            'csv' => $this->readCsv($contents),
            'json' => $this->readJson($contents),
            'txt', 'md' => $contents,
            default => throw new \RuntimeException('This file format cannot be safely extracted. Use PDF, DOCX, TXT, or Markdown.'),
        };
    }

    private function readPdf(string $contents): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'kbpdf_');
        try {
            file_put_contents($temporary, $contents);

            return (new Parser)->parseFile($temporary)->getText();
        } finally {
            @unlink($temporary);
        }
    }

    private function readDocx(string $contents): string
    {
        return $this->withZip($contents, function (ZipArchive $zip): string {
            $xml = $zip->getFromName('word/document.xml');
            if (! is_string($xml)) {
                throw new \RuntimeException('The DOCX document is malformed.');
            }
            $xml = str_replace(['</w:p>', '</w:tr>', '<w:tab/>'], ["\n", "\n", "\t"], $xml);

            return trim(strip_tags($xml).$this->relationshipLinks($zip, ['word/_rels/document.xml.rels']));
        });
    }

    private function readXlsx(string $contents): string
    {
        return $this->withZip($contents, function (ZipArchive $zip): string {
            $shared = [];
            $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
            if (is_string($sharedXml)) {
                $xml = @simplexml_load_string($sharedXml);
                foreach ($xml?->si ?? [] as $item) {
                    $shared[] = trim(implode(' ', array_map('strval', iterator_to_array($item->t ?? []))));
                }
            }
            $rows = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);
                if (! preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
                    continue;
                }
                $sheet = @simplexml_load_string((string) $zip->getFromIndex($index));
                foreach ($sheet?->sheetData?->row ?? [] as $row) {
                    $cells = [];
                    foreach ($row->c ?? [] as $cell) {
                        $value = (string) ($cell->v ?? '');
                        $cells[] = (string) ($cell['t'] ?? '') === 's' ? ($shared[(int) $value] ?? '') : $value;
                    }
                    $rows[] = implode(' | ', $cells);
                }
            }

            $relationshipFiles = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);
                if (preg_match('#^xl/worksheets/_rels/sheet\d+\.xml\.rels$#', $name)) {
                    $relationshipFiles[] = $name;
                }
            }

            return trim(implode("\n", $rows).$this->relationshipLinks($zip, $relationshipFiles));
        });
    }

    private function withZip(string $contents, callable $callback): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new \RuntimeException('ZIP support is required to read Office documents.');
        }
        $temporary = tempnam(sys_get_temp_dir(), 'kboffice_');
        $zip = new ZipArchive;
        $opened = false;
        try {
            file_put_contents($temporary, $contents);
            if ($zip->open($temporary) !== true) {
                throw new \RuntimeException('The Office document is corrupted or encrypted.');
            }
            $opened = true;

            return (string) $callback($zip);
        } finally {
            if ($opened) {
                $zip->close();
            }
            @unlink($temporary);
        }
    }

    private function readCsv(string $contents): string
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream)) !== false && count($rows) < 20_000) {
            $rows[] = implode(' | ', array_map(fn ($value) => trim((string) $value), $row));
        }
        fclose($stream);

        return implode("\n", $rows);
    }

    private function readJson(string $contents): string
    {
        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        return (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function formatFaq(string $raw): string
    {
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return $raw;
        }

        return collect($decoded)->filter(fn ($pair) => is_array($pair))
            ->map(fn ($pair) => 'Q: '.trim((string) ($pair['question'] ?? ''))."\nA: ".trim((string) ($pair['answer'] ?? '')))
            ->filter(fn ($pair) => trim($pair) !== "Q:\nA:")->implode("\n\n");
    }

    /** @return array<int, string> */
    private function mediaLinksFromHtml(string $html): array
    {
        preg_match_all('/<(?:iframe|video|source)\b[^>]*\bsrc\s*=\s*(["\'])(https:\/\/[^"\']+)\1/iu', $html, $matches);

        return array_values(array_unique(array_map(
            fn ($url) => html_entity_decode((string) $url, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $matches[2] ?? [],
        )));
    }

    /** @param array<int, string> $relationshipFiles */
    private function relationshipLinks(ZipArchive $zip, array $relationshipFiles): string
    {
        $links = [];
        foreach ($relationshipFiles as $relationshipFile) {
            $xml = $zip->getFromName($relationshipFile);
            if (! is_string($xml)) {
                continue;
            }
            preg_match_all('/\bTarget\s*=\s*(["\'])(https:\/\/[^"\']+)\1/iu', $xml, $matches);
            foreach ($matches[2] ?? [] as $url) {
                $links[] = html_entity_decode((string) $url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        return $links === [] ? '' : "\n\nLinked resources:\n".implode("\n", array_values(array_unique($links)));
    }

    private function normalize(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\R{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
