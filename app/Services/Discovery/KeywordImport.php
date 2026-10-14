<?php

namespace App\Services\Discovery;

use App\Models\ExplorationKeyword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class KeywordImport
{
    public static function text(mixed $value, int $max): void
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > $max || preg_match('/\p{C}/u', $value)) {
            throw new \InvalidArgumentException('文字列の型・長さ・制御文字が不正です。');
        }
    }

    public static function normalize(string $query): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', \Normalizer::normalize($query, \Normalizer::FORM_KC))));
    }

    private function keys(array $value, array $expected): void
    {
        if (array_diff(array_keys($value), $expected) || array_diff($expected, array_keys($value))) {
            throw new \InvalidArgumentException('未知キーまたは必須キーの欠落があります。');
        }
    }

    public function import(string $json, bool $apply = false): array
    {
        try {
            if (strlen($json) > 65536) {
                throw new \InvalidArgumentException('64 KiBを超えています。');
            }
            $d = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($d)) {
                throw new \InvalidArgumentException('JSONオブジェクトが必要です。');
            }
            $this->keys($d, ['schema_version', 'prompt_id', 'source_kind', 'preset_id', 'demand_status', 'candidates']);
            if ($d['schema_version'] !== '1.0.0' || $d['prompt_id'] !== 'keyword-proposal-v1' || $d['demand_status'] !== 'unverified' || ! in_array($d['source_kind'], ['codex_proposal', 'manual'], true) || ! in_array($d['preset_id'], ['en-US', 'ja-JP'], true) || ! is_array($d['candidates']) || ! array_is_list($d['candidates']) || count($d['candidates']) < 1 || count($d['candidates']) > 8) {
                throw new \InvalidArgumentException('版・列挙・件数が不正です。');
            }
            $catalog = app(Catalog::class)->load();
            $ids = $queries = [];
            $rows = [];
            foreach ($d['candidates'] as $c) {
                if (! is_array($c)) {
                    throw new \InvalidArgumentException('候補はオブジェクトにしてください。');
                }
                $this->keys($c, ['id', 'parent_theme_id', 'label_ja', 'query', 'meaning_ja', 'reason_ja']);
                if (! is_string($c['id']) || ! Str::isUuid($c['id']) || ($c['parent_theme_id'] !== null && ! in_array($c['parent_theme_id'], array_column($catalog['themes'], 'id'), true))) {
                    throw new \InvalidArgumentException('UUIDまたは親テーマが不正です。');
                }
                $c['id'] = strtolower($c['id']);
                ksort($c);
                foreach (['label_ja' => 80, 'query' => 120, 'meaning_ja' => 500, 'reason_ja' => 500] as $key => $max) {
                    self::text($c[$key], $max);
                }
                $normalized = self::normalize($c['query']);
                if (isset($ids[$c['id']]) || isset($queries[$normalized])) {
                    throw new \InvalidArgumentException('入力内のUUIDまたは検索語が重複しています。');
                }
                $ids[$c['id']] = $queries[$normalized] = true;
                $rows[] = $c + ['source' => config('youtube.mode'), 'preset_id' => $d['preset_id'], 'normalized_query' => $normalized, 'source_kind' => $d['source_kind'], 'prompt_id' => $d['prompt_id'], 'schema_version' => $d['schema_version'], 'fingerprint' => hash('sha256', json_encode([$d['preset_id'], $d['source_kind'], $c])), 'input_context' => ['catalog_version' => $catalog['version'], 'catalog_hash' => $catalog['hash']]];
            }
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['json' => $e instanceof \InvalidArgumentException ? $e->getMessage() : 'JSONの構造が不正です。']);
        }

        return DB::transaction(function () use ($rows, $catalog, $apply) {
            // Serialize imports, so conflict checks and all inserts remain atomic.
            if ($apply) {
                DB::select('select pg_advisory_xact_lock(4781102)');
            }
            $report = [];
            foreach ($rows as $row) {
                $idMatch = ExplorationKeyword::find($row['id']);
                if ($idMatch && ($idMatch->fingerprint !== $row['fingerprint'] || $idMatch->source !== $row['source'])) {
                    throw ValidationException::withMessages(['json' => '同じUUIDの内容が競合しています。']);
                }
                $duplicate = ExplorationKeyword::where('source', $row['source'])->where('preset_id', $row['preset_id'])->where('normalized_query', $row['normalized_query'])->exists();
                foreach ($catalog['themes'] as $theme) {
                    $q = $theme['queries'][$row['preset_id']];
                    foreach (array_merge([$q['primary']], array_column($q['related'], 'query')) as $query) {
                        $duplicate = $duplicate || self::normalize($query) === $row['normalized_query'];
                    }
                }
                $report[] = ['id' => $row['id'], 'query' => $row['query'], 'result' => $duplicate || $idMatch ? '重複（状態を維持）' : '追加候補（未承認）'];
                if ($apply && ! $duplicate && ! $idMatch) {
                    ExplorationKeyword::create($row);
                }
            }

            return $report;
        });
    }
}
