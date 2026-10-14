<?php

namespace App\Services\YouTube;

use Carbon\Carbon;

// All IDs, titles and counts are fictional. This adapter never opens a network connection.
class DemoGateway implements Gateway
{
    public const CHANNEL_A = 'UCDEMO000000000000000001';

    public const CHANNEL_B = 'UCDEMO000000000000000002';

    public function fetch(string $method, array $parameters): array
    {
        $videos = $this->videos();
        if ($method === 'videos') {
            $videos = array_merge($videos, $this->olderVideos());
        } elseif ($method === 'search' && isset($parameters['publishedBefore']) && $parameters['publishedBefore'] < now()->subDays(179)->toIso8601ZuluString()) {
            $videos = $this->olderVideos();
        }
        $channels = $this->channels();
        if ($method === 'videos' || $method === 'channels') {
            $ids = explode(',', $parameters['id']);
            $items = array_values(array_filter($method === 'videos' ? $videos : $channels, fn ($v) => in_array($v['id'], $ids, true)));

            return ['items' => $items];
        }
        if ($method === 'playlistItems') {
            $channel = $parameters['playlistId'] === 'UUDEMO000000000000000001' ? self::CHANNEL_A : self::CHANNEL_B;
            $videos = array_values(array_filter($videos, fn ($v) => $v['snippet']['channelId'] === $channel));
        } else {
            $videos = array_values(array_filter($videos, function ($v) use ($parameters) {
                $date = $v['snippet']['publishedAt'];
                if (isset($parameters['publishedAfter']) && $date < $parameters['publishedAfter']) {
                    return false;
                }
                if (isset($parameters['publishedBefore']) && $date > $parameters['publishedBefore']) {
                    return false;
                }
                $minutes = (new \DateInterval($v['contentDetails']['duration']))->i;

                return match ($parameters['videoDuration'] ?? 'any') {
                    'short' => $minutes < 4, 'medium' => $minutes >= 4 && $minutes <= 20, 'long' => $minutes > 20, default => true,
                };
            }));
            if (($parameters['order'] ?? '') === 'viewCount') {
                usort($videos, fn ($a, $b) => ($b['statistics']['viewCount'] ?? -1) <=> ($a['statistics']['viewCount'] ?? -1));
            } elseif (($parameters['order'] ?? '') === 'date') {
                usort($videos, fn ($a, $b) => strcmp($b['snippet']['publishedAt'], $a['snippet']['publishedAt']));
            }
        }
        $offset = (int) ($parameters['pageToken'] ?? 0);
        $size = $parameters['maxResults'] ?? 20;
        $slice = array_slice($videos, $offset, $size);
        $items = array_map(fn ($v) => $method === 'search' ? ['id' => ['videoId' => $v['id']]] : ['contentDetails' => ['videoId' => $v['id']]], $slice);

        return ['items' => $items, 'nextPageToken' => $offset + $size < count($videos) ? (string) ($offset + $size) : null];
    }

    private function channels(): array
    {
        return array_map(fn ($id, $title, $subs) => [
            'id' => $id,
            'snippet' => ['title' => $title, 'publishedAt' => '2024-01-01T00:00:00Z'],
            'statistics' => ['viewCount' => '300000', 'subscriberCount' => $subs, 'videoCount' => '2', 'hiddenSubscriberCount' => $subs === null],
            'contentDetails' => ['relatedPlaylists' => ['uploads' => 'UU'.substr($id, 2)]],
        ], [self::CHANNEL_A, self::CHANNEL_B], ['架空：雨の日の机', '架空：夜の読書室'], ['1200', null]);
    }

    private function videos(): array
    {
        $shift = (int) now()->startOfDay()->diffInDays(Carbon::parse('2026-01-01'), true);
        $out = [];
        foreach ([['demoVideo01', self::CHANNEL_A, '架空：雨音とピアノ・読書用', 'PT45M', 45000], ['demoVideo02', self::CHANNEL_A, '架空：雨音とピアノ・静かな机', 'PT43M', 180], ['demoVideo03', self::CHANNEL_B, '架空：深夜の読書・ライブ配信', 'PT50M', 3200], ['demoVideo04', self::CHANNEL_B, '架空：公開直後・数値欠損の例', 'PT2M', null]] as $i => [$id, $channel, $title, $duration, $views]) {
            $out[] = ['id' => $id, 'snippet' => ['title' => $title, 'channelId' => $channel, 'publishedAt' => now()->subHours($i === 3 ? 1 : 240)->toIso8601ZuluString(), 'liveBroadcastContent' => $i === 2 ? 'live' : 'none'], 'contentDetails' => ['duration' => $duration], 'statistics' => $views === null ? [] : ['viewCount' => (string) ($views + $shift), 'likeCount' => '12', 'commentCount' => '3'], ...($i === 2 ? ['liveStreamingDetails' => ['actualStartTime' => now()->subHours(10)->toIso8601ZuluString()]] : [])];
        }

        return $out;
    }

    private function olderVideos(): array
    {
        $base = $this->videos()[0];
        $base['snippet']['publishedAt'] = now()->subYear()->toIso8601ZuluString();

        return array_map(function ($id, $views) use ($base) {
            $data = $base;
            $data['id'] = $id;
            $data['snippet']['title'] = '架空：古い作品の参考例 '.$id;
            $data['statistics']['viewCount'] = (string) $views;

            return $data;
        }, ['demoOlder01', 'demoOlder02'], [80000, 80]);
    }
}
