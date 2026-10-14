<?php

namespace Tests\Support;

use App\Services\YouTube\DemoGateway;
use App\Services\YouTube\Gateway;

class RecordingGateway implements Gateway
{
    public array $calls = [];

    public ?\Closure $intercept = null;

    public function fetch(string $method, array $parameters): array
    {
        $this->calls[] = [$method, $parameters];

        return $this->intercept ? ($this->intercept)($method, $parameters, count($this->calls)) : (new DemoGateway)->fetch($method, $parameters);
    }
}
