<?php

return [
    'catalog' => __DIR__.'/discovery-catalog-v1.json',
    'search_limit' => 20,
    'read_limit' => 100,
    // worker timeout 120 < retry_after 150 < claim/collection lock 180 seconds.
    'lease_seconds' => 180,
];
