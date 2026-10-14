<?php

namespace App\Console\Commands;

use App\Services\Discovery\KeywordImport;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class ImportDiscoveryKeywords extends Command
{
    protected $signature = 'discovery:import-keywords {path} {--dry-run : DB無変更でプレビュー（既定）} {--apply : 検証後、未承認で原子的に保存}';

    protected $description = '候補語JSONの検証・プレビュー（既定はdry-run、検索は実行しない）';

    public function handle(KeywordImport $import): int
    {
        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('--dry-runと--applyは同時に指定できません。');

            return self::FAILURE;
        }
        $path = $this->argument('path');
        if (! is_file($path) || ! is_readable($path) || filesize($path) > 65536) {
            $this->error('読み取り可能な64 KiB以下のJSONファイルを指定してください。');

            return self::FAILURE;
        }
        try {
            $rows = $import->import(file_get_contents($path), (bool) $this->option('apply'));
            $this->table(['UUID', '検索語', '結果'], $rows);
            $this->info($this->option('apply') ? '未承認で取込済み。画面で承認してから明示的に検索してください。' : 'dry-run: DB変更なし・検索なし');

            return self::SUCCESS;
        } catch (ValidationException $e) {
            $this->error(collect($e->errors())->flatten()->implode(' '));

            return self::FAILURE;
        }
    }
}
