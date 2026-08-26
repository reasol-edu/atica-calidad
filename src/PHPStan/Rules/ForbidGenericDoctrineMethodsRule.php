<?php

declare(strict_types=1);

namespace App\PHPStan\Rules;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

/**
 * Prohibits calling generic Doctrine retrieval methods (find, getRepository) on
 * EntityManagerInterface or ManagerRegistry outside of App\Repository\ classes.
 *
 * Enforce injecting concrete, named-method repositories instead of ad-hoc queries.
 *
 * @implements Rule<MethodCall>
 */
final class ForbidGenericDoctrineMethodsRule implements Rule
{
    private const EM_BANNED = ['find', 'getRepository'];
    private const REGISTRY_BANNED = ['getRepository'];

    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Node\Identifier) {
            return [];
        }

        $methodName   = $node->name->name;
        $callerType   = $scope->getType($node->var);
        $emType       = new ObjectType(EntityManagerInterface::class);
        $registryType = new ObjectType(ManagerRegistry::class);

        $isBanned = ($emType->isSuperTypeOf($callerType)->yes() && in_array($methodName, self::EM_BANNED, true))
            || ($registryType->isSuperTypeOf($callerType)->yes() && in_array($methodName, self::REGISTRY_BANNED, true));

        if (!$isBanned) {
            return [];
        }

        $class = $scope->getClassReflection();
        if ($class !== null && str_starts_with($class->getName(), 'App\\Repository\\')) {
            return [];
        }

        return [
            RuleErrorBuilder::message(
                sprintf(
                    'Calling %s() on EntityManager/Registry is forbidden outside App\\Repository\\. Inject the concrete repository and use a named method instead.',
                    $methodName,
                )
            )
            ->identifier('gestconvplus.doctrineGenericMethod')
            ->build(),
        ];
    }
}
