<?php declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ripple\File\File;
use Throwable;

use function md5;
use function md5_file;

/**
 * @Author cclilshy
 * @Date   2024/8/15 14:49
 */
class FileStreamTest extends TestCase
{
    /**
     * @Author cclilshy
     * @Date   2024/8/15 14:49
     * @return void
     * @throws Throwable
     */
    #[Test]
    public function test_fileStream(): void
    {
        $hash    = md5_file(__FILE__);
        $content = File::getContents(__FILE__);
        $this->assertEquals($hash, md5($content));
    }
}
