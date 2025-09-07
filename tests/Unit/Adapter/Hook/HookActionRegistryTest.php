<?php
/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/OSL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://devdocs.prestashop.com/ for more information.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

namespace Tests\Unit\Adapter\Hook;

use ArrayIterator;
use Module;
use PHPUnit\Framework\TestCase;
use PrestaShop\PrestaShop\Adapter\Debug\DebugMode;
use PrestaShop\PrestaShop\Adapter\Hook\HookActionRegistry;
use PrestaShop\PrestaShop\Core\Hook\Attribute\HookAction;
use PrestaShop\PrestaShop\Core\Hook\Attribute\HookActionStrategy;
use PrestaShop\PrestaShop\Core\Hook\HookActionInterface;
use PrestaShop\PrestaShop\Core\Hook\HookActionStrategyInterface;
use PrestaShop\PrestaShop\Core\Module\Legacy\ModuleInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

#[HookAction(hook: 'displayHeader', action: DummyAction::class)]
class DummyModule extends Module implements ModuleInterface
{
    public function __construct()
    {
    }
}

class DummyAction implements HookActionInterface
{
    public function __invoke(array $hookArgs): string
    {
        return 'called-' . ($hookArgs['suffix'] ?? '');
    }
}

#[HookAction(hook: 'displayHeader', action: DummyActionA::class)]
#[HookAction(hook: 'displayHeader', action: DummyActionB::class)]
#[HookActionStrategy(hook: 'displayHeader', strategy: DummyStrategy::class)]
class DummyModuleWithStrategy extends Module implements ModuleInterface
{
    public function __construct()
    {
    }
}

class DummyActionA implements HookActionInterface
{
    public function __invoke(array $hookArgs): string
    {
        return 'A';
    }
}

class DummyActionB implements HookActionInterface
{
    public function __invoke(array $hookArgs): string
    {
        return 'B';
    }
}

class DummyStrategy implements HookActionStrategyInterface
{
    public function resolve(array $actions, array $hookArgs): HookActionInterface
    {
        return ($hookArgs['use_b'] ?? false) ? $actions[1] : $actions[0];
    }
}

class HookActionRegistryTest extends TestCase
{
    private HookActionRegistry $registry;

    private DummyModule $module;

    private DebugMode $debugMode;

    protected function setUp(): void
    {
        $this->debugMode = $this->createMock(DebugMode::class);
        $this->debugMode->method('isDebugModeEnabled')->willReturn(false);

        $this->registry = new HookActionRegistry(
            new ArrayIterator([new DummyAction()]),
            new ArrayIterator([]),
            new ArrayAdapter(),
            $this->debugMode
        );
        $this->module = new DummyModule();
    }

    public function testHasActionHookDetectsAttribute(): void
    {
        $this->assertTrue($this->registry->hasActionHook($this->module, 'displayHeader'));
    }

    public function testGetActionHookReturnsActionInstance(): void
    {
        $action = $this->registry->findActionHook($this->module, 'displayHeader');
        $this->assertInstanceOf(DummyAction::class, $action);
    }

    public function testCallActionHookInvokesAction(): void
    {
        $result = $this->registry->callActionHook($this->module, 'displayHeader', ['suffix' => 'test']);
        $this->assertSame('called-test', $result);
    }

    public function testStrategySelectsProperAction(): void
    {
        $registry = new HookActionRegistry(
            new ArrayIterator([new DummyActionA(), new DummyActionB()]),
            new ArrayIterator([new DummyStrategy()]),
            new ArrayAdapter(),
            $this->debugMode
        );
        $module = new DummyModuleWithStrategy();

        $resultA = $registry->callActionHook($module, 'displayHeader', ['use_b' => false]);
        $this->assertSame('A', $resultA);

        $resultB = $registry->callActionHook($module, 'displayHeader', ['use_b' => true]);
        $this->assertSame('B', $resultB);
    }
}
