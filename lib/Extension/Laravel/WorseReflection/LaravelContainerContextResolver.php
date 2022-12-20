<?php

namespace Phpactor\Extension\Laravel\WorseReflection;

use Phpactor\Extension\Laravel\Adapter\Laravel\LaravelContainerInspector;
use Phpactor\WorseReflection\Core\ClassName;
use Phpactor\WorseReflection\Core\Inference\FunctionArguments;
use Phpactor\WorseReflection\Core\Inference\Resolver\MemberAccess\MemberContextResolver;
use Phpactor\WorseReflection\Core\Reflection\ReflectionMember;
use Phpactor\WorseReflection\Core\Type;
use Phpactor\WorseReflection\Core\TypeFactory;
use Phpactor\WorseReflection\Core\Type\ClassStringType;
use Phpactor\WorseReflection\Core\Type\ClassType;
use Phpactor\WorseReflection\Core\Type\StringLiteralType;
use Phpactor\WorseReflection\Reflector;

class LaravelContainerContextResolver implements MemberContextResolver
{
    const CONTAINER_CLASSES = [
        'Illuminate\\Contracts\\Foundation\\Application',
        'Illuminate\\Support\\Facades\\App'
    ];

    public function __construct(private LaravelContainerInspector $inspector)
    {
    }

    public function resolveMemberContext(Reflector $reflector, ReflectionMember $member, ?FunctionArguments $arguments): ?Type
    {
        if ($member->memberType() !== ReflectionMember::TYPE_METHOD) {
            return null;
        }

        if ($member->name() !== 'get') {
            return null;
        }

        if (count($arguments) === 0) {
            return null;
        }

        $isMatch = false;

        foreach (self::CONTAINER_CLASSES as $class) {
            if ($member->class()->isInstanceOf(ClassName::fromString($class))) {
                $isMatch = true;
                break;
            }
        }

        if (!$isMatch) {
            return null;
        }

        $argument = $arguments->at(0)->type();

        if ($argument instanceof StringLiteralType) {
            $service = $this->inspector->service($argument->value());
            if (null === $service) {
                return TypeFactory::union(TypeFactory::object(), TypeFactory::null());
            }
            return $service->asReflectedClasssType($reflector);
        }
        if ($argument instanceof ClassStringType && $argument->className()) {
            $service = $this->inspector->service($argument->className()->__toString());
            if ($service instanceof ClassType) {
                return $service->asReflectedClasssType($reflector);
            }
            return TypeFactory::fromString($argument->className()->__toString());
        }

        return TypeFactory::undefined();
    }
}
