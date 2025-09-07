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

namespace PrestaShop\PrestaShop\Adapter\Hook;

use Exception;
use Hook;
use PrestaShop\PrestaShop\Adapter\Debug\DebugMode;
use PrestaShop\PrestaShop\Core\Domain\Hook\Exception\HookNotFoundException;
use PrestaShop\PrestaShop\Core\Hook\Attribute\HookAction;
use PrestaShop\PrestaShop\Core\Hook\Attribute\HookActionStrategy;
use PrestaShop\PrestaShop\Core\Hook\HookActionInterface;
use PrestaShop\PrestaShop\Core\Hook\HookActionStrategyInterface;
use PrestaShop\PrestaShop\Core\Module\Legacy\ModuleInterface;
use Psr\Cache\InvalidArgumentException;
use ReflectionClass;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Traversable;

class HookActionRegistry
{
    /**
     * Cache of hook-action mappings and strategies declared via attributes on module classes.
     *
     * @var array<string, array{actions: array<string, array<int, string>>, strategies: array<string, string>}>
     */
    protected array $attributeCache = [];

    /**
     * @var array
     */
    protected array $actionHooksInstances = [];

    /**
     * @var array<string, HookActionStrategyInterface>
     */
    protected array $actionHookStrategiesInstances = [];

    public function __construct(
        #[TaggedIterator('prestashop.hook.actions')]
        Traversable $actionHooksInstances,
        #[TaggedIterator('prestashop.hook.strategies')]
        Traversable $actionHookStrategiesInstances,
        #[Autowire('@cache.app')]
        protected CacheInterface $cache,
        #[Autowire('@prestashop.adapter.debug_mode')]
        protected DebugMode $debugMode,
    ) {
        $instancesAsArray = iterator_to_array($actionHooksInstances);
        $this->actionHooksInstances = array_combine(
            array_map(
                static fn (HookActionInterface $i) => $i::class,
                $instancesAsArray
            ),
            $instancesAsArray
        );

        $strategyInstancesAsArray = iterator_to_array($actionHookStrategiesInstances);
        $this->actionHookStrategiesInstances = array_combine(
            array_map(
                static fn (HookActionStrategyInterface $i) => $i::class,
                $strategyInstancesAsArray
            ),
            $strategyInstancesAsArray
        );
    }

    /**
     * Retrieves the attributes of a given module, utilizing cache for performance.
     *
     * If debug mode is enabled, attributes are parsed directly.
     * Otherwise, the attributes are retrieved from cache or calculated and stored in cache if absent.
     *
     * @param ModuleInterface $module the module for which attributes are to be retrieved
     *
     * @return array{actions:array<string, array<int, string>>, strategies: array<string, string>}
     */
    protected function getModuleAttributes(ModuleInterface $module): array
    {
        $class = $module::class;

        if ($this->debugMode->isDebugModeEnabled()) {
            return $this->parseModuleAttributes($module);
        }

        if (!isset($this->attributeCache[$class])) {
            $cacheKey = 'hook_actions_' . md5($class);

            try {
                $this->attributeCache[$class] = $this->cache->get(
                    $cacheKey,
                    fn (ItemInterface $item) => $this->parseModuleAttributes($module)
                );
            } catch (Exception|InvalidArgumentException) {
                $this->attributeCache[$class] = ['actions' => [], 'strategies' => []];
            }
        }

        return $this->attributeCache[$class];
    }

    /**
     * Retrieves the action hooks associated with the provided module.
     *
     * @param ModuleInterface $module the module for which to retrieve action hooks
     *
     * @return array<string, array<int, string>>
     */
    protected function getModuleActionHooks(ModuleInterface $module): array
    {
        return $this->getModuleAttributes($module)['actions'];
    }

    /**
     * Retrieves the hook strategies associated with the provided module.
     *
     * @param ModuleInterface $module
     *
     * @return array<string, string>
     */
    protected function getModuleHookStrategies(ModuleInterface $module): array
    {
        return $this->getModuleAttributes($module)['strategies'];
    }

    /**
     * Parses the module's attributes to collect hooks and associated actions and strategies.
     *
     * This method inspects the given module's class using reflection and extracts attributes
     * associated with hooks actions and strategies. It retrieves and normalizes the hook
     * names and maps them to the corresponding actions and strategies.
     *
     * @param ModuleInterface $module the module instance to parse
     *
     * @return array{actions:array<string, array<int, string>>, strategies: array<string, string>}
     */
    protected function parseModuleAttributes(ModuleInterface $module): array
    {
        $reflection = new ReflectionClass($module);
        $actions = [];
        $strategies = [];

        foreach ($reflection->getAttributes(HookAction::class) as $attribute) {
            /** @var HookAction $instance */
            $instance = $attribute->newInstance();
            if (class_exists($instance->action)) {
                $actions[$this->normalizeHookName($instance->hook)][] = $instance->action;
            }
        }

        foreach ($reflection->getAttributes(HookActionStrategy::class) as $attribute) {
            /** @var HookActionStrategy $instance */
            $instance = $attribute->newInstance();
            if (class_exists($instance->strategy)) {
                $strategies[$this->normalizeHookName($instance->hook)] = $instance->strategy;
            }
        }

        return ['actions' => $actions, 'strategies' => $strategies];
    }

    /**
     * Checks if a specific action hook exists for the provided module.
     *
     * @param ModuleInterface $module the module to check for the hook
     * @param string $hookName the name of the hook to check
     *
     * @return bool returns true if the hook exists, false otherwise
     */
    public function hasActionHook(ModuleInterface $module, string $hookName): bool
    {
        $moduleActionHooks = $this->getModuleActionHooks($module);

        return isset($moduleActionHooks[$this->normalizeHookName($hookName)]);
    }

    /**
     * Retrieves the action hook associated with a specific module and hook name.
     *
     * Determines the appropriate action hook(s) or strategy to handle the given hook
     * name and arguments. If no action hooks are found, or if multiple hooks exist
     * without a defined strategy, an exception is thrown.
     *
     * @param ModuleInterface $module the module from which to retrieve the hook
     * @param string $hookName the name of the hook to retrieve
     * @param array $hookArgs optional arguments to be passed to the hook strategy resolution
     *
     * @return HookActionInterface the resolved action hook instance for the specified module and hook name
     *
     * @throws HookNotFoundException if no hook, no strategy for multiple hooks, or other resolution issues occur
     */
    public function findActionHook(ModuleInterface $module, string $hookName, array $hookArgs = []): HookActionInterface
    {
        $hook = $this->normalizeHookName($hookName);
        $moduleActionHooksClasses = $this->getModuleActionHooks($module)[$hook] ?? null;

        if (empty($moduleActionHooksClasses)) {
            throw new HookNotFoundException(sprintf('No action hook found on module %s for hook %s.', get_class($module), $hookName));
        }

        // Single action, simply return it
        if (count($moduleActionHooksClasses) === 1) {
            return $this->getActionHookService($moduleActionHooksClasses[0]);
        }

        // Multiple actions for the same hook, use strategy if available
        $moduleStrategies = $this->getModuleHookStrategies($module);
        if (!isset($moduleStrategies[$hook])) {
            throw new HookNotFoundException(
                sprintf(
                    'Multiple action hooks found on module %s for hook %s but no strategy defined.',
                    $module::class,
                    $hookName
                )
            );
        }

        return $this->getActionHookStrategyService($moduleStrategies[$hook])->resolve(
            array_map(
                fn (string $hookActionClass) => $this->getActionHookService($hookActionClass),
                $moduleActionHooksClasses
            ),
            $hookArgs
        );
    }

    /**
     * Calls an action hook on a module.
     *
     * @param array<string, mixed> $hookArgs
     *
     * @throws HookNotFoundException
     */
    public function callActionHook(ModuleInterface $module, string $hookName, array $hookArgs): array|string
    {
        return $this->findActionHook($module, $hookName, $hookArgs)($hookArgs);
    }

    /**
     * Checks if an action hook is callable for the given hooks to check.
     *
     * @param array<int, string> $hooksToCheck
     *
     * @return bool
     */
    public function isActionHookCallableOn(ModuleInterface $module, array $hooksToCheck): bool
    {
        $moduleActionHooks = $this->getModuleActionHooks($module);
        if (!empty($moduleActionHooks)) {
            return !empty(array_intersect($hooksToCheck, array_keys($moduleActionHooks)));
        }

        return false;
    }

    /**
     * Returns the canonical name for a given hook.
     *
     * @return string
     */
    protected function normalizeHookName(string $hookName): string
    {
        // For now we'll assume we have access to the Hook class for canonical names
        // In a full refactor, this logic could be moved to this service as well
        return class_exists('Hook') ? Hook::normalizeHookName($hookName) : $hookName;
    }

    /**
     * @param $classes
     *
     * @return HookActionInterface
     *
     * @throws HookNotFoundException
     */
    public function getActionHookService(string $actionHookClass): HookActionInterface
    {
        if (!isset($this->actionHooksInstances[$actionHookClass]) || !$this->actionHooksInstances[$actionHookClass] instanceof HookActionInterface) {
            throw new HookNotFoundException(
                sprintf(
                    'Action hook %s not found in container (service must be tagged with "%s").',
                    $actionHookClass,
                    'prestashop.hook.actions'
                )
            );
        }

        return $this->actionHooksInstances[$actionHookClass];
    }

    /**
     * @throws HookNotFoundException
     */
    public function getActionHookStrategyService(string $strategyClass): HookActionStrategyInterface
    {
        if (!isset($this->actionHookStrategiesInstances[$strategyClass]) || !$this->actionHookStrategiesInstances[$strategyClass] instanceof HookActionStrategyInterface) {
            throw new HookNotFoundException(
                sprintf(
                    'Action hook strategy %s not found in container (service must be tagged with "%s").',
                    $strategyClass,
                    'prestashop.hook.strategies'
                )
            );
        }

        return $this->actionHookStrategiesInstances[$strategyClass];
    }
}
