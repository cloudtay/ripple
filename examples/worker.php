<?php declare(strict_types=1);

use Ripple\Utils\Output;
use Ripple\Worker\Command;
use Ripple\Worker\Manager;

use function Co\async;
use function Co\wait;

include __DIR__ . '/../vendor/autoload.php';

$manager = new Manager();
$worker  = new class () extends Ripple\Worker\Worker {
    /*** @var string */
    protected string $name = 'abc';

    /*** @var int */
    protected int $count = 4;

    /*** @return void */
    public function boot(): void
    {
        Output::info('Worker started');
    }

    /**
     * @param \Ripple\Worker\Manager $manager
     *
     * @return void
     */
    public function register(Manager $manager): void
    {
        // TODO: Implement register() method.
    }

    /**
     * @param \Ripple\Worker\Command $command
     *
     * @return void
     */
    protected function onCommand(Command $command): void
    {
    }
};

$worker2 = new class () extends Ripple\Worker\Worker {
    /*** @var string */
    protected string $name = 'def';

    /*** @var int */
    protected int $count = 4;

    /*** @return void */
    public function boot(): void
    {
        $this->forwardCommand(Command::make('test'), 'abc', 2);
        if ($this->getIndex() === 1) {
            async(function () {
                while (1) {
                    echo \json_encode($this->getManagerMateData()), \PHP_EOL;
                    \Co\sleep(1);
                }
            })->except(function () {
                \var_dump('error');
            });
        }
    }

    /**
     * @param \Ripple\Worker\Manager $manager
     *
     * @return void
     */
    public function register(Manager $manager): void
    {
        // TODO: Implement register() method.
    }
};

$manager->add($worker);
$manager->add($worker2);
$manager->run();
wait();
