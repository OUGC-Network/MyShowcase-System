<?php

declare(strict_types=1);

namespace MyShowcase;

use MyShowcase\Collection\Collection;
use MyShowcase\Event\EventDispatcher;
use MyShowcase\Repository\AccessLogsRepository;
use MyShowcase\Repository\AttachmentsRepository;
use MyShowcase\Repository\CommentsRepository;
use MyShowcase\Repository\EntriesRepository;
use MyShowcase\Repository\ModeratorsRepository;
use MyShowcase\Repository\PermissionsRepository;
use MyShowcase\Repository\ShowcasesRepository;
use MyShowcase\Repository\SubscriptionsRepository;
use MyShowcase\Routing\Routing;
use MyShowcase\Service\AttachmentsManager;
use MyShowcase\Service\CacheManager;
use MyShowcase\Service\CommentsManager;
use MyShowcase\Service\EntriesManager;
use MyShowcase\Service\RenderManager;
use MyShowcase\Service\UsersManager;
use MyShowcase\Entity\ShowcaseEntity;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class Container
{
    public ContainerBuilder $container;

    public function __construct(?int $showcase_id = null, ?string $route_identifier = null)
    {
        global $db, $mybb, $lang;

        $this->container = new ContainerBuilder();

        $this->container->set('database', $db);

        $this->container->set('mybb', $mybb);

        $this->container->set('mybb.cache', $mybb->cache);

        $this->container->set('language', $lang);

        $this->container->register('showcase.context', ShowcaseContext::class)
            ->setArguments([
                new Reference('database'),
                new Reference('mybb'),
                new Reference('mybb.cache'),
                new Reference('language'),
                new Reference('routing'),
                new Reference('event.dispatcher'),
                new Reference('showcases.repository'),
                new Reference('access.logs.repository'),
                new Reference('moderators.repository'),
                new Reference('entries.repository'),
                new Reference('subscriptions.repository'),
                new Reference('comments.repository'),
                new Reference('attachments.repository'),
                new Reference('permissions.repository'),
                new Reference('cache.manager'),
                new Reference('users.manager'),
                new Reference('entries.manager'),
                new Reference('comments.manager'),
                new Reference('attachments.manager'),
                new Reference('render.manager'),
                new Reference('showcase.entity'),
                new Reference('fields.collection'),
                '%collection_fields%',
            ])
            ->setPublic(true)
            ->setLazy(true);

        $this->container->register('routing', Routing::class)
            ->setArguments([new Reference('showcase.context')])
            ->setLazy(true);

        $this->container->register('event.dispatcher', EventDispatcher::class)
            ->setLazy(true);

        $this->container->register('showcases.repository', ShowcasesRepository::class)
            ->setArguments(
                [new Reference('database'), new Reference('event.dispatcher'), new Reference('showcase.entity'),]
            )
            ->setLazy(true);

        $this->container->register('access.logs.repository', AccessLogsRepository::class)
            ->setArguments(
                [new Reference('database'), new Reference('event.dispatcher'), new Reference('showcase.entity'),]
            )
            ->setLazy(true);

        $this->container->register('moderators.repository', ModeratorsRepository::class)
            ->setArguments(
                [new Reference('database'), new Reference('event.dispatcher'), new Reference('showcase.entity'),]
            )
            ->setLazy(true);

        $this->container->register('entries.repository', EntriesRepository::class)
            ->setArguments([new Reference('database'), new Reference('mybb'), new Reference('showcase.entity'),])
            ->setLazy(true);

        $this->container->register('subscriptions.repository', SubscriptionsRepository::class)
            ->setArguments([new Reference('database'), new Reference('mybb'), new Reference('showcase.entity'),])
            ->setLazy(true);

        $this->container->register('comments.repository', CommentsRepository::class)
            ->setArguments([new Reference('database'), new Reference('showcase.entity'),])
            ->setLazy(true);

        $this->container->register('attachments.repository', AttachmentsRepository::class)
            ->setArguments([new Reference('database'), new Reference('showcase.entity'),])
            ->setLazy(true);

        $this->container->register('permissions.repository', PermissionsRepository::class)
            ->setArguments([new Reference('database'), new Reference('showcase.entity'),])
            ->setLazy(true);

        $this->container->register('cache.manager', CacheManager::class)
            ->setArguments([new Reference('database'), new Reference('mybb.cache')])
            ->setLazy(true);

        $this->container->register('users.manager', UsersManager::class)
            ->setArguments([new Reference('showcase.context')])
            ->setLazy(true);

        $this->container->register('entries.manager', EntriesManager::class)
            ->setArguments([new Reference('showcase.context')])
            ->setLazy(true);

        $this->container->register('comments.manager', CommentsManager::class)
            ->setArguments([new Reference('showcase.context')])
            ->setLazy(true);

        $this->container->register('attachments.manager', AttachmentsManager::class)
            ->setArguments([new Reference('showcase.context')])
            ->setLazy(true);

        $this->container->register('render.manager', RenderManager::class)
            ->setArguments([new Reference('showcase.context')])
            ->setLazy(true);

        $this->container->register('showcase.entity', ShowcaseEntity::class)
            ->setLazy(true);

        $this->container->register('fields.collection', Collection::class)
            ->setLazy(true);

        $this->container->setParameter('collection_fields', []);

        $this->container->register('showcase', Showcase::class)
            ->setArguments([new Reference('showcase.context'), $showcase_id, $route_identifier])
            ->setPublic(true)
            ->setLazy(true);

        $this->container->compile();
    }
}