<?php

namespace Tests\Unit;

use App\Services\YouTube\ReferenceParser;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReferenceParserTest extends TestCase
{
    public static function urls(): array
    {
        return [['demoVideo01'], ['https://youtu.be/demoVideo01?t=40'], ['https://www.youtube.com/watch?v=demoVideo01&list=abc'], ['https://m.youtube.com/shorts/demoVideo01'], ['https://www.youtube.com/live/demoVideo01']];
    }

    #[DataProvider('urls')]
    public function test_allowed_references(string $input): void
    {
        $this->assertSame(['kind' => 'video', 'id' => 'demoVideo01'], (new ReferenceParser)->parse($input));
    }

    public static function unsafe(): array
    {
        return [['http://localhost/watch?v=demoVideo01'], ['https://www.youtube.com.evil.test/watch?v=demoVideo01'], ['https://user@www.youtube.com/watch?v=demoVideo01'], ['https://www.youtube.com:444/watch?v=demoVideo01'], ['file:///tmp/key'], ['https://www.youtube.com/watch?v[]=demoVideo01'], ['https://youtu.be/demoVideo01/extra'], ['@anyhandle']];
    }

    #[DataProvider('unsafe')]
    public function test_unsafe_references(string $input): void
    {
        $this->expectException(ValidationException::class);
        (new ReferenceParser)->parse($input);
    }
}
