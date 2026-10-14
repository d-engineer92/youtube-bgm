<?php

namespace App\Services\Discovery;

use Illuminate\Validation\ValidationException;

class Catalog
{
    private function keys(mixed $value, array $expected): void
    {
        if (! is_array($value) || array_diff(array_keys($value), $expected) || array_diff($expected, array_keys($value))) {
            throw new \RuntimeException;
        }
    }

    public function load(): array
    {
        $raw = file_get_contents(config('discovery.catalog'));
        try {
            $data = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            $this->keys($data, ['schema_version', 'catalog_id', 'version', 'status', 'default_preset', 'presets', 'themes']);
            $this->keys($data['presets'], ['en-US', 'ja-JP']);
            KeywordImport::text($data['catalog_id'], 80);
            KeywordImport::text($data['version'], 30);
            if (! is_array($data['themes']) || ! array_is_list($data['themes'])) {
                throw new \RuntimeException;
            }
            if ($data['schema_version'] !== '1.0.0' || $data['status'] !== 'unverified_search_hypotheses' || $data['default_preset'] !== 'en-US' || ! preg_match('/^\d+\.\d+\.\d+$/D', $data['version']) || count($data['themes']) !== 8) {
                throw new \RuntimeException;
            }
            foreach (['en-US' => ['US', 'en'], 'ja-JP' => ['JP', 'ja']] as $id => [$region, $lang]) {
                $this->keys($data['presets'][$id], ['label_ja', 'region_code', 'relevance_language']);
                KeywordImport::text($data['presets'][$id]['label_ja'], 120);
                if ($data['presets'][$id]['region_code'] !== $region || $data['presets'][$id]['relevance_language'] !== $lang) {
                    throw new \RuntimeException;
                }
            }
            $ids = [];
            foreach ($data['themes'] as $theme) {
                $this->keys($theme, ['id', 'axis', 'label_ja', 'purpose_ja', 'queries']);
                $this->keys($theme['queries'], ['en-US', 'ja-JP']);
                if (! in_array($theme['axis'], ['broad', 'use', 'scene', 'style'], true)) {
                    throw new \RuntimeException;
                }
                if (! is_string($theme['id']) || ! preg_match('/^[a-z][a-z0-9_-]{0,40}$/D', $theme['id']) || in_array($theme['id'], $ids, true)) {
                    throw new \RuntimeException;
                }
                $ids[] = $theme['id'];
                KeywordImport::text($theme['label_ja'], 80);
                KeywordImport::text($theme['purpose_ja'], 500);
                foreach (['en-US', 'ja-JP'] as $preset) {
                    $q = $theme['queries'][$preset];
                    $this->keys($q, ['primary', 'meaning_ja', 'related']);
                    KeywordImport::text($q['primary'], 120);
                    KeywordImport::text($q['meaning_ja'], 500);
                    if (! is_array($q['related']) || ! array_is_list($q['related']) || count($q['related']) > 8) {
                        throw new \RuntimeException;
                    }
                    foreach ($q['related'] as $related) {
                        $this->keys($related, ['query', 'meaning_ja']);
                        KeywordImport::text($related['query'], 120);
                        KeywordImport::text($related['meaning_ja'], 500);
                    }
                }
            }
        } catch (\Throwable) {
            throw ValidationException::withMessages(['catalog' => '探索カタログの形式が不正です。設定ファイルを確認してください。']);
        }

        return $data + ['hash' => hash('sha256', $raw)];
    }

    public function theme(string $id): array
    {
        $theme = collect($this->load()['themes'])->firstWhere('id', $id);
        if (! $theme) {
            throw ValidationException::withMessages(['theme' => 'カタログにないテーマです。']);
        }

        return $theme;
    }
}
