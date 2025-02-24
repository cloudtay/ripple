<?php declare(strict_types=1);
/**
 * 文件监控示例
 *
 * 本示例展示如何使用 Ripple\File\Monitor 类监控文件和目录变化
 */

include __DIR__.'/../vendor/autoload.php';

use Ripple\File\Monitor;
use Ripple\Utils\Output;
use Ripple\Kernel;

use function Co\wait;
use function Co\sleep;
use function Co\process;

// 打印标题
Output::info("\n".\str_repeat("=", 80));
Output::info(" 文件监控示例演示 ");
Output::info(\str_repeat("=", 80));

// 创建测试目录和文件
$tempDir = \sys_get_temp_dir().\DIRECTORY_SEPARATOR.'ripple_monitor_example';
if (!\is_dir($tempDir)) {
    if (!@\mkdir($tempDir, 0755, true)) {
        Output::error("无法创建临时目录: {$tempDir}");
        exit(1);
    }
}

$phpFile = $tempDir.\DIRECTORY_SEPARATOR.'test.php';
\file_put_contents($phpFile, '<?php echo "Hello World";');

// 打印环境信息
Output::info("\n".\str_repeat("-", 80));
Output::info(" 环境信息 ");
Output::info(\str_repeat("-", 80));
Output::info("• 操作系统: ".\PHP_OS);
Output::info("• PHP版本: ".\PHP_VERSION);
Output::info("• 当前进程ID: ".\getmypid());
Output::info("• 临时目录: {$tempDir}");
Output::info("• 测试文件: {$phpFile}");

if (!Kernel::getInstance()->supportProcessControl()) {
    Output::warning("\n\033[43m WARNING \033[0m 当前环境不支持进程控制，子进程测试将被跳过。");
}

// 创建监控器
$monitor = new Monitor(2); // 设置2秒检查间隔

// 注册事件处理
Output::info("\n".\str_repeat("-", 80));
Output::info(" 事件注册 ");
Output::info(\str_repeat("-", 80));

$monitor->onTouch = function (string $path) {
    Output::info("文件被创建: ".\basename($path));
    Output::info("   路径: {$path}");
};

$monitor->onModify = function (string $path) {
    Output::info("文件被修改: ".\basename($path));
    Output::info("   路径: {$path}");
};

$monitor->onRemove = function (string $path) {
    Output::warning("文件被删除: ".\basename($path));
    Output::warning("   路径: {$path}");
};

Output::info("• 创建事件处理器已注册");
Output::info("• 修改事件处理器已注册");
Output::info("• 删除事件处理器已注册");

try {
    // 添加监控
    Output::info("\n".\str_repeat("-", 80));
    Output::info(" 配置监控 ");
    Output::info(\str_repeat("-", 80));

    // 1. 监控单个文件
    $monitor->add($phpFile);
    Output::info("• 已添加文件监控: ".\basename($phpFile));

    // 2. 只监控目录中特定扩展名的文件
    $monitor->add($tempDir, ['php', 'txt']);
    Output::info("• 已添加目录监控: {$tempDir}");
    Output::info("  - 仅监控扩展名: php, txt");

    // 开始监控
    $monitor->start();
    Output::info("• 监控已启动");
    Output::info("• 运行状态: ".($monitor->isRunning() ? "\033[32m运行中\033[0m" : "\033[31m未运行\033[0m"));

    // 测试文件操作
    Output::info("\n".\str_repeat("-", 80));
    Output::info(" 文件操作测试 ");
    Output::info(\str_repeat("-", 80));

    // 1. 修改文件
    sleep(2);
    Output::info("\n• 操作: 修改文件 ".\basename($phpFile));
    \file_put_contents($phpFile, '<?php echo "Updated content";');

    // 2. 创建新文件
    sleep(2);
    $newFile = $tempDir.\DIRECTORY_SEPARATOR.'new.txt';
    Output::info("\n• 操作: 创建新文件 ".\basename($newFile));
    \file_put_contents($newFile, 'New file content');

    // 3. 创建非监控扩展名的文件（不应触发事件）
    sleep(2);
    $jsonFile = $tempDir.\DIRECTORY_SEPARATOR.'data.json';
    Output::info("\n• 操作: 创建JSON文件 ".\basename($jsonFile)."（不应触发事件）");
    \file_put_contents($jsonFile, '{"status": "ok"}');

    // 4. 删除文件
    sleep(2);
    Output::info("\n• 操作: 删除文件 ".\basename($newFile));
    \unlink($newFile);

    // 测试子进程中的监控行为
    if (Kernel::getInstance()->supportProcessControl()) {
        sleep(1);
        Output::info("\n".\str_repeat("-", 80));
        Output::info(" 子进程测试 ");
        Output::info(\str_repeat("-", 80));

        // 使用 Ripple Process 模块创建子进程
        $task = process(function () use ($monitor, $tempDir) {
            // 子进程代码
            Output::info("• 子进程ID: ".\getmypid());
            Output::info("• 子进程中监控状态: ".($monitor->isRunning() ? "\033[32m运行中\033[0m" : "\033[31m已停止\033[0m"));

            // 尝试在子进程中再次启动
            try {
                $childFile = $tempDir.\DIRECTORY_SEPARATOR.'child.txt';
                \file_put_contents($childFile, 'Child process file');
                $monitor->add($childFile);
                Output::info("• 子进程添加监控文件: ".\basename($childFile));

                sleep(1);
                return 0; // 相当于 exit(0)
            } catch (\Exception $e) {
                Output::error("子进程异常: ".$e->getMessage());
                return 1; // 相当于 exit(1)
            }
        });

        // 运行任务
        $runtime = $task->run();

        if ($runtime) {
            sleep(2);
            Output::info("• 父进程中监控状态: ".($monitor->isRunning() ? "\033[32m运行中\033[0m" : "\033[31m已停止\033[0m"));
        } else {
            Output::error("• 子进程创建失败");
        }
    }

    // 等待一段时间以便观察效果
    sleep(3);
    Output::info("\n".\str_repeat("-", 80));
    Output::info(" 测试完成 ");
    Output::info(\str_repeat("-", 80));

} catch (\Exception $e) {
    Output::error("\n发生错误: ".$e->getMessage());
} finally {
    // 停止监控
    $monitor->stop();
    Output::info("• 已停止监控");
    Output::info("• 运行状态: ".($monitor->isRunning() ? "\033[32m运行中\033[0m" : "\033[31m已停止\033[0m"));

    // 清理测试文件
    Output::info("\n".\str_repeat("-", 80));
    Output::info(" 清理资源 ");
    Output::info(\str_repeat("-", 80));

    $cleaned = [];

    if (\file_exists($phpFile)) {
        \unlink($phpFile);
        $cleaned[] = \basename($phpFile);
    }

    if (isset($jsonFile) && \file_exists($jsonFile)) {
        \unlink($jsonFile);
        $cleaned[] = \basename($jsonFile);
    }

    if (isset($newFile) && \file_exists($newFile)) {
        \unlink($newFile);
        $cleaned[] = \basename($newFile);
    }

    // 清理子进程可能创建的文件
    $childFile = $tempDir.\DIRECTORY_SEPARATOR.'child.txt';
    if (\file_exists($childFile)) {
        \unlink($childFile);
        $cleaned[] = \basename($childFile);
    }

    if (!empty($cleaned)) {
        Output::info("• 已删除文件: ".\implode(", ", $cleaned));
    }

    if (\is_dir($tempDir)) {
        @\rmdir($tempDir);
        Output::info("• 已删除目录: {$tempDir}");
    }
}

// 等待所有任务完成
wait(function () {
    Output::info("\n".\str_repeat("=", 80));
    Output::info(" 示例运行结束 ");
    Output::info(\str_repeat("=", 80)."\n");
});
