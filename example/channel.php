<?php declare(strict_types=1);

include_once __DIR__ . '/../vendor/autoload.php';

$channel = \P\IO::Channel()->make('test');

/**
 * - 向一个已关闭的通道发送数据，会抛出异常 ChannelException: Unable to send data to a closed channel
 * - 进程间通讯是基于序列化实现的,因此只能发送可序列化的数据如 resource / closure / generator/ redis对象等是无法直接发送的,会抛出一个序列化异常
 */
$channel->send(new stdClass());
$channel->send([1,2,3,4,5,6,7,8,9]);
$channel->send('hello');

$task = \P\System::Process()->task(function () use ($channel) {
    $channel->setBlocking(false);
    while ($item = $channel->receive()) {
        \var_dump($item);

        \P\sleep(1);
    }

    $channel->close();
    exit(0);
});

$runtime = $task->run();
$runtime->await();

echo "done\n";
