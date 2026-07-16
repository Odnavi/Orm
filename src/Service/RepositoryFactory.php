<?php

namespace Odnavi\Orm\Service;

use Soffio\Core\Service\ReflectionFactory;
use Odnavi\Orm\Repository\EntityRepository;

class RepositoryFactory
{
    public static function get(string $entityClass): EntityRepository
    {
        /** @var EntityRepository[] $cache */
        static $cache = [];

        if (isset($cache[$entityClass])) {
            return $cache[$entityClass];
        }

        $repoClass = self::resolveRepositoryClass($entityClass);

        // Выделенные репозитории знают свой entityClass через дефолт свойства.
        // Обобщённому EntityRepository класс сущности нужно передать в конструктор.
        $repository = $repoClass === EntityRepository::class
            ? new $repoClass($entityClass)
            : new $repoClass();

        return $cache[$entityClass] = $repository;
    }

    protected static function resolveRepositoryClass(string $entityClass): string
    {
        $reflection = ReflectionFactory::getClass($entityClass);

        $namespace = str_replace('\\Entity\\', '\\Repository\\', $reflection->getNamespaceName());
        $className = str_replace('Entity', 'Repository', $reflection->getShortName());

        $repoClass = "$namespace\\$className";
        return class_exists($repoClass)
            ? $repoClass
            : EntityRepository::class;
    }
}