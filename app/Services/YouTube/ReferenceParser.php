<?php

namespace App\Services\YouTube;

use Illuminate\Validation\ValidationException;

class ReferenceParser
{
    public function parse(string $input): array
    {
        $value = trim($input);
        if (preg_match('/^UC[A-Za-z0-9_-]{22}$/D', $value)) {
            return ['kind' => 'channel', 'id' => $value];
        }
        if (preg_match('/^[A-Za-z0-9_-]{11}$/D', $value)) {
            return ['kind' => 'video', 'id' => $value];
        }
        $url = parse_url($value);
        if ($url && ($url['scheme'] ?? '') === 'https' && ! isset($url['user']) && ! isset($url['pass']) && ! isset($url['port'])) {
            $host = strtolower($url['host'] ?? '');
            $path = $url['path'] ?? '';
            $id = null;
            if (in_array($host, ['youtu.be', 'www.youtu.be'], true) && preg_match('~^/([A-Za-z0-9_-]{11})/?$~D', $path, $m)) {
                $id = $m[1];
            } elseif (in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com'], true)) {
                if ($path === '/watch') {
                    parse_str($url['query'] ?? '', $query);
                    $id = $query['v'] ?? null;
                } elseif (preg_match('~^/(?:shorts|live|embed)/([A-Za-z0-9_-]{11})/?$~D', $path, $m)) {
                    $id = $m[1];
                } elseif (preg_match('~^/channel/(UC[A-Za-z0-9_-]{22})/?$~D', $path, $m)) {
                    return ['kind' => 'channel', 'id' => $m[1]];
                }
            }
            if (is_string($id) && preg_match('/^[A-Za-z0-9_-]{11}$/D', $id)) {
                return ['kind' => 'video', 'id' => $id];
            }
        }
        throw ValidationException::withMessages(['reference' => 'チャンネルID、動画ID、または https のYouTube動画URLを入力してください。ハンドルは未対応です。']);
    }
}
