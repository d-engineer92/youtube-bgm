<?php

namespace App\Services\YouTube;

interface Gateway
{
    public function fetch(string $method, array $parameters): array;
}
