<?php

return [
    'interval_version' => 'interval-v1.1',
    'interval_hours' => 24,
    'reason_codes' => ['comparable', 'first_observation', 'interval_outside_window', 'no_valid_observation', 'stale_latest', 'unfulfilled_due', 'missing_slot', 'latest_value_missing', 'latest_attempt_failed', 'user_paused'],
    'minimum_hours' => 22,
    'maximum_hours' => 26,
    'freshness_hours' => 26,
    'schedule_grace_minutes' => 15,
    'heartbeat_seconds' => 900,
    'ai_enabled' => false,
    'image_evidence_enabled' => false,
];
