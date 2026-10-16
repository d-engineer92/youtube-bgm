<?php

use App\Models\Target;
use App\Services\Tracking\Review;
use App\Support\DatabasePolicy;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
DatabasePolicy::assertTestConnection($app['db']->connection(), $app->environment());
config(['youtube.mode' => 'demo']);
$targets = Target::inMode()->where('kind', 'video')->limit(2)->with(['video', 'observations', 'tracking', 'slots'])->get();
echo json_encode(app(Review::class)->pack($targets), JSON_THROW_ON_ERROR);
