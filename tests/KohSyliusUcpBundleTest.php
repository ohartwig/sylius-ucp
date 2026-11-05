<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusUcp\Tests;

use Doctrine\ORM\EntityRepository;
use Doctrine\Persistence\ObjectManager;
use Koh\SyliusAgentContracts\Cart\CartHandoff;
use Koh\SyliusAgentContracts\Context\AgentContextFactoryInterface;
use Koh\SyliusAgentContracts\Http\AgentJsonResponder;
use Koh\SyliusUcp\Catalog\AgeRestriction;
use Koh\SyliusUcp\Controller\CatalogLookupController;
use Koh\SyliusUcp\Controller\CatalogSearchController;
use Koh\SyliusUcp\Controller\PermalinkController;
use Koh\SyliusUcp\Controller\UcpProfileController;
use Koh\SyliusUcp\KohSyliusUcpBundle;
use Koh\SyliusUcp\Negotiation\Negotiator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sylius\Bundle\OrderBundle\Factory\AddToCartCommandFactoryInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Product\Repository\ProductVariantRepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The bundle's configuration and wiring: every service it defines compiles
 * against the Sylius and framework services it names.
 */
#[CoversClass(KohSyliusUcpBundle::class)]
final class KohSyliusUcpBundleTest extends TestCase
{
    public function testDefaults(): void
    {
        $container = $this->container([]);

        self::assertSame('2026-08-25', $container->getParameter('koh_sylius_ucp.version'));
        self::assertSame('/ucp/v1', $container->getParameter('koh_sylius_ucp.endpoint_base'));
        self::assertNull($container->getParameter('koh_sylius_ucp.age_restriction'));
        self::assertTrue($container->getParameter('koh_sylius_ucp.permalink.enabled'));
        self::assertSame('sylius_shop_cart_summary', $container->getParameter('koh_sylius_ucp.permalink.cart_route'));
        self::assertSame('sylius_shop_homepage', $container->getParameter('koh_sylius_ucp.permalink.fallback_route'));
        self::assertSame(20, $container->getParameter('koh_sylius_ucp.profile_fetch_limit'));
        self::assertSame('1 minute', $container->getParameter('koh_sylius_ucp.profile_fetch_interval'));
    }

    public function testConfigurationAndPredefinedParameters(): void
    {
        $container = $this->container(
            ['age_restriction' => 18, 'permalink' => ['enabled' => false, 'cart_route' => 'app_cart', 'fallback_route' => 'app_home']],
            ['koh_sylius_ucp.endpoint_base' => '/agents/ucp'],
        );

        self::assertSame(18, $container->getParameter('koh_sylius_ucp.age_restriction'));
        self::assertFalse($container->getParameter('koh_sylius_ucp.permalink.enabled'));
        self::assertSame('app_cart', $container->getParameter('koh_sylius_ucp.permalink.cart_route'));
        self::assertSame('app_home', $container->getParameter('koh_sylius_ucp.permalink.fallback_route'));
        self::assertSame('/agents/ucp', $container->getParameter('koh_sylius_ucp.endpoint_base'));
    }

    public function testEveryServiceIsBuilt(): void
    {
        $container = $this->container(['age_restriction' => 18]);
        foreach ([CatalogSearchController::class, CatalogLookupController::class, PermalinkController::class, UcpProfileController::class, Negotiator::class, AgeRestriction::class] as $id) {
            $container->getDefinition($id)->setPublic(true);
        }
        $container->compile();
        $this->provideSynthetics($container);

        self::assertInstanceOf(CatalogSearchController::class, $container->get(CatalogSearchController::class));
        self::assertInstanceOf(CatalogLookupController::class, $container->get(CatalogLookupController::class));
        self::assertInstanceOf(PermalinkController::class, $container->get(PermalinkController::class));
        self::assertInstanceOf(UcpProfileController::class, $container->get(UcpProfileController::class));
        self::assertInstanceOf(Negotiator::class, $container->get(Negotiator::class));
        $age = $container->get(AgeRestriction::class);
        self::assertInstanceOf(AgeRestriction::class, $age);
        self::assertSame(18, $age->minimumAge);
    }

    private function provideSynthetics(ContainerBuilder $container): void
    {
        foreach ([
            'cache.app' => new ArrayAdapter(),
            'sylius.repository.product' => $this->createStub(EntityRepository::class),
            'sylius.repository.product_variant' => $this->createStub(ProductVariantRepositoryInterface::class),
            'sylius.context.cart' => $this->createStub(CartContextInterface::class),
            'sylius.factory.order_item' => $this->createStub(FactoryInterface::class),
            'sylius.modifier.order_item_quantity' => $this->createStub(OrderItemQuantityModifierInterface::class),
            'sylius.factory.add_to_cart_command' => $this->createStub(AddToCartCommandFactoryInterface::class),
            'event_dispatcher' => new EventDispatcher(),
            'sylius.manager.order' => $this->createStub(ObjectManager::class),
            'router' => $this->createStub(UrlGeneratorInterface::class),
            AgentContextFactoryInterface::class => $this->createStub(AgentContextFactoryInterface::class),
            AgentJsonResponder::class => new AgentJsonResponder(),
            CartHandoff::class => new CartHandoff(
                $this->createStub(CartContextInterface::class),
                $this->createStub(FactoryInterface::class),
                $this->createStub(OrderItemQuantityModifierInterface::class),
                $this->createStub(AddToCartCommandFactoryInterface::class),
                new EventDispatcher(),
                $this->createStub(ObjectManager::class),
            ),
        ] as $id => $service) {
            $container->set($id, $service);
        }

    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $parameters
     */
    private function container(array $config, array $parameters = []): ContainerBuilder
    {
        $container = new ContainerBuilder();
        foreach ($parameters as $name => $value) {
            $container->setParameter($name, $value);
        }
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());

        foreach ([
            'cache.app' => ArrayAdapter::class,
            'sylius.repository.product' => EntityRepository::class,
            'sylius.repository.product_variant' => ProductVariantRepositoryInterface::class,
            'sylius.context.cart' => CartContextInterface::class,
            'sylius.factory.order_item' => FactoryInterface::class,
            'sylius.modifier.order_item_quantity' => OrderItemQuantityModifierInterface::class,
            'sylius.factory.add_to_cart_command' => AddToCartCommandFactoryInterface::class,
            'event_dispatcher' => EventDispatcher::class,
            'sylius.manager.order' => ObjectManager::class,
            'router' => UrlGeneratorInterface::class,
            AgentContextFactoryInterface::class => AgentContextFactoryInterface::class,
            AgentJsonResponder::class => AgentJsonResponder::class,
            CartHandoff::class => CartHandoff::class,
        ] as $id => $class) {
            $container->register($id, $class)->setSynthetic(true);
        }

        $bundle = new KohSyliusUcpBundle();
        $extension = $bundle->getContainerExtension();
        self::assertNotNull($extension);
        $container->registerExtension($extension);
        $container->loadFromExtension($extension->getAlias(), $config);
        $bundle->build($container);
        $extension->load($container->getExtensionConfig($extension->getAlias()), $container);

        return $container;
    }
}
