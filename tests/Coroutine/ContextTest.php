<?php declare(strict_types=1);

namespace Tests\Coroutine;

use PHPUnit\Framework\TestCase;
use Ripple\Coroutine\Context;
use Ripple\Types\Undefined;
use Throwable;

use function Co\async;

class ContextTest extends TestCase
{
    /**
     * @return void
     * @throws Throwable
     */
    public function testContextIsolation(): void
    {
        $value1 = null;
        $value2 = null;

        async(function () use (&$value1) {

            Context::setValue('test_key', 'value1');
            $value1 = Context::getValue('test_key');
        })->await();

        async(function () use (&$value2) {

            Context::setValue('test_key', 'value2');
            $value2 = Context::getValue('test_key');
        })->await();

        $this->assertEquals('value1', $value1);
        $this->assertEquals('value2', $value2);
        $this->assertNotEquals($value1, $value2);
    }

    /**
     * @return void
     * @throws Throwable
     */
    public function testContextInheritance(): void
    {
        $parentValue = null;
        $childValue  = null;

        async(function () use (&$parentValue, &$childValue) {
            Context::setValue('inherited_key', 'parent_value');

            async(function () use (&$childValue) {
                $childValue = Context::getValue('inherited_key');
            })->await();

            $parentValue = Context::getValue('inherited_key');
        })->await();

        $this->assertEquals('parent_value', $parentValue);
        $this->assertEquals('parent_value', $childValue);
    }

    /**
     * @return void
     */
    public function testContextClear(): void
    {
        $value = null;

        try {
            async(function () use (&$value) {
                Context::setValue('clear_key', 'test_value');
                Context::clear();
                $value = Context::getValue('clear_key');
            })->await();
        } catch (Throwable $e) {

        }

        $this->assertTrue($value instanceof Undefined);
    }

    /**
     * @return void
     * @throws Throwable
     */
    public function testMultipleContextValues(): void
    {
        $values = [];

        async(function () use (&$values) {

            Context::setValue('key1', 'value1');
            Context::setValue('key2', 'value2');

            $values['key1'] = Context::getValue('key1');
            $values['key2'] = Context::getValue('key2');
        })->await();

        $this->assertEquals([
            'key1' => 'value1',
            'key2' => 'value2'
        ], $values);
    }

    /**
     * @return void
     * @throws Throwable
     */
    public function testContextOverwrite(): void
    {
        $value = null;

        async(function () use (&$value) {
            Context::setValue('overwrite_key', 'original');
            Context::setValue('overwrite_key', 'updated');
            $value = Context::getValue('overwrite_key');
        })->await();

        $this->assertEquals('updated', $value);
    }

    /**
     * @return void
     * @throws Throwable
     */
    public function testNonExistentContextKey(): void
    {
        $value = 'default';

        async(function () use (&$value) {
            $value = Context::getValue('non_existent_key');
        })->await();

        $this->assertTrue($value instanceof Undefined);
    }

    /**
     * @return void
     * @throws Throwable
     */
    public function testContextPersistence(): void
    {
        $value = null;

        async(function () use (&$value) {
            Context::setValue('persist_key', 'persist_value');
            \Co\sleep(0.1);
            $value = Context::getValue('persist_key');
        })->await();

        $this->assertEquals('persist_value', $value);
    }

    /**
     * @return void
     * @throws Throwable
     */
    public function testNestedContexts(): void
    {
        $values = [];

        async(function () use (&$values) {
            Context::setValue('level', 'outer');

            async(function () use (&$values) {
                Context::setValue('level', 'inner');

                $values['inner'] = Context::getValue('level');
            })->await();

            $values['outer'] = Context::getValue('level');
        })->await();

        $this->assertEquals([
            'inner' => 'inner',
            'outer' => 'outer'
        ], $values);
    }

    /**
     * @return void
     * @throws Throwable
     */
    public function testContextInheritanceChain(): void
    {
        $values = [];

        async(function () use (&$values) {
            Context::setValue('level1', 'value1');

            async(function () use (&$values) {
                Context::setValue('level2', 'value2');

                async(function () use (&$values) {
                    Context::setValue('level3', 'value3');

                    $values['level1'] = Context::getValue('level1');
                    $values['level2'] = Context::getValue('level2');
                    $values['level3'] = Context::getValue('level3');
                })->await();
            })->await();
        })->await();

        $this->assertEquals([
            'level1' => 'value1',
            'level2' => 'value2',
            'level3' => 'value3'
        ], $values);
    }

    /**
     * @return void
     * @throws Throwable
     */
    public function testContextIsolationBetweenBranches(): void
    {
        $values = [];

        async(function () use (&$values) {
            Context::setValue('shared', 'parent');

            $branch1 = async(function () {
                Context::setValue('branch', 'one');
                return Context::getValue('branch');
            });

            $branch2 = async(function () {
                Context::setValue('branch', 'two');
                return Context::getValue('branch');
            });

            $values['branch1'] = $branch1->await();
            $values['branch2'] = $branch2->await();
            $values['shared']  = Context::getValue('shared');
        })->await();

        $this->assertEquals([
            'branch1' => 'one',
            'branch2' => 'two',
            'shared'  => 'parent'
        ], $values);
    }
}
