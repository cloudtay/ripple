<?php declare(strict_types=1);

namespace Tests\File;

use PHPUnit\Framework\TestCase;
use Ripple\File\Monitor;
use Ripple\Kernel;
use InvalidArgumentException;
use RuntimeException;
use Exception;

use function file_exists;
use function file_put_contents;
use function function_exists;
use function is_dir;
use function mkdir;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const DIRECTORY_SEPARATOR;

/**
 * Monitor unit tests
 *
 */
class MonitorTest extends TestCase
{
    /**
     * @var string 临时测试目录
     */
    private string $tempDir;

    /**
     * @var string 测试PHP文件
     */
    private string $phpFile;

    /**
     * @var Monitor|null
     */
    private ?Monitor $monitor = null;

    /**
     * Setup test environment
     */
    protected function setUp(): void
    {
        parent::setUp();


        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ripple_monitor_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);


        $this->phpFile = $this->tempDir . DIRECTORY_SEPARATOR . 'test.php';
        file_put_contents($this->phpFile, '<?php echo "Test file"; ?>');


        $this->monitor = new Monitor(1);
    }

    /**
     * 清理测试资源
     */
    protected function tearDown(): void
    {
        if ($this->monitor !== null) {
            $this->monitor->stop();
            $this->monitor = null;
        }


        if (isset($this->phpFile) && file_exists($this->phpFile)) {
            @unlink($this->phpFile);
        }


        if (isset($this->tempDir) && is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }

        parent::tearDown();
    }

    /**
     * 递归删除目录
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }

    /**
     * 测试构造函数和间隔设置
     */
    public function testConstructorAndInterval(): void
    {
        $monitor = new Monitor();
        $this->assertInstanceOf(Monitor::class, $monitor);

        $monitor = new Monitor(5);
        $this->assertInstanceOf(Monitor::class, $monitor);

        $monitor->setInterval(10);
        $this->assertInstanceOf(Monitor::class, $monitor);
    }

    /**
     * 测试添加文件到监控
     */
    public function testAddFile(): void
    {

        $this->expectException(InvalidArgumentException::class);
        $this->monitor->add($this->tempDir . DIRECTORY_SEPARATOR . 'non-existent.php');
    }

    /**
     * 测试添加有效文件
     */
    public function testAddValidFile(): void
    {
        $this->monitor->add($this->phpFile);
        $this->assertTrue(true);
    }

    /**
     * 测试添加目录
     */
    public function testAddDirectory(): void
    {
        $this->monitor->add($this->tempDir);
        $this->assertTrue(true);
    }

    /**
     * 测试添加带扩展名过滤的目录
     */
    public function testAddDirectoryWithExtensions(): void
    {
        $this->monitor->add($this->tempDir, ['php', 'txt']);
        $this->assertTrue(true);
    }

    /**
     * 测试清除监控
     */
    public function testClear(): void
    {
        $this->monitor->add($this->phpFile);
        $this->monitor->clear();

        $this->assertInstanceOf(Monitor::class, $this->monitor);
    }

    /**
     * 测试运行状态检查
     */
    public function testIsRunning(): void
    {
        $this->assertFalse($this->monitor->isRunning());

        $this->monitor->onModify = function () {};

        $this->monitor->add($this->phpFile);
        $this->monitor->start();

        $this->assertTrue($this->monitor->isRunning());

        $this->monitor->stop();
        $this->assertFalse($this->monitor->isRunning());
    }

    /**
     * 测试事件处理函数设置
     */
    public function testEventHandlers(): void
    {
        $this->monitor->onTouch = function (string $path) {

        };

        $this->monitor->onModify = function (string $path) {

        };

        $this->monitor->onRemove = function (string $path) {

        };

        $this->monitor->add($this->phpFile);
        $this->monitor->start();

        $this->assertTrue($this->monitor->isRunning());

        $this->monitor->stop();
    }

    /**
     * 测试没有设置回调时的异常
     */
    public function testNoEventHandlerException(): void
    {
        $monitor = new Monitor();
        $monitor->add($this->phpFile);

        $this->expectException(RuntimeException::class);
        $monitor->start();
    }

    /**
     * 测试在子进程中的行为
     */
    public function testBehaviorInChildProcess(): void
    {

        $kernel = Kernel::getInstance();
        if (!$kernel->supportProcessControl() || !function_exists('Co\\process')) {
            $this->markTestSkipped('This test requires process control support');
            return;
        }

        $this->monitor->onModify = function () {};

        $this->monitor->add($this->phpFile);
        $this->monitor->start();

        $this->assertTrue($this->monitor->isRunning());

        try {
            $task = \Co\process(function () {
                $running = $this->monitor->isRunning();
                return $running ? 1 : 0;
            });

            $runtime = $task->run();

            if ($runtime) {
                \Co\sleep(1);
                $this->assertTrue($this->monitor->isRunning(), '父进程中监控应该保持运行');
            }
        } catch (Exception $e) {
            $this->fail('子进程测试失败: ' . $e->getMessage());
        }
    }
}
