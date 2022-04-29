<?php

namespace Phpactor\WorseReferenceFinder;

use Phpactor\ReferenceFinder\DefinitionLocator;
use Phpactor\ReferenceFinder\Exception\CouldNotLocateDefinition;
use Phpactor\ReferenceFinder\TypeLocation;
use Phpactor\ReferenceFinder\TypeLocations;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\Location;
use Phpactor\TextDocument\TextDocument;
use Phpactor\TextDocument\TextDocumentUri;
use Phpactor\WorseReflection\Core\Cache;
use Phpactor\WorseReflection\Core\Exception\NotFound;
use Phpactor\WorseReflection\Core\Inference\Symbol;
use Phpactor\WorseReflection\Core\Inference\NodeContext;
use Phpactor\WorseReflection\Core\Reflection\ReflectionClass;
use Phpactor\WorseReflection\Core\Reflection\ReflectionEnum;
use Phpactor\WorseReflection\Core\Reflection\ReflectionInterface;
use Phpactor\WorseReflection\Core\Reflection\ReflectionOffset;
use Phpactor\WorseReflection\Core\Reflection\ReflectionTrait;
use Phpactor\WorseReflection\Core\SourceCode;
use Phpactor\WorseReflection\Reflector;

class WorseReflectionDefinitionLocator implements DefinitionLocator
{
    private Reflector $reflector;

    private Cache $cache;

    public function __construct(Reflector $reflector, Cache $cache)
    {
        $this->reflector = $reflector;
        $this->cache = $cache;
    }

    
    public function locateDefinition(TextDocument $document, ByteOffset $byteOffset): TypeLocations
    {
        if (false === $document->language()->isPhp()) {
            throw new CouldNotLocateDefinition('I only work with PHP files');
        }

        $this->cache->purge();

        if ($uri = $document->uri()) {
            $sourceCode = SourceCode::fromPathAndString($uri->__toString(), $document->__toString());
        } else {
            $sourceCode = SourceCode::fromString($document->__toString());
        }

        try {
            $offset = $this->reflector->reflectOffset(
                $sourceCode,
                $byteOffset->toInt()
            );
        } catch (NotFound $notFound) {
            throw new CouldNotLocateDefinition($notFound->getMessage(), 0, $notFound);
        }

        $typeLocations = [];
        foreach ($offset->symbolContext()->type()->toTypes() as $namedClassType) {
            $location = $this->gotoDefinition($document, $offset);
            $typeLocations[] = new TypeLocation($namedClassType, $location);
        }

        if (empty($typeLocations)) {
            throw new CouldNotLocateDefinition('No definition(s) found');
        }

        return new TypeLocations($typeLocations);
    }

    private function gotoDefinition(TextDocument $document, ReflectionOffset $offset): Location
    {
        $symbolContext = $offset->symbolContext();
        switch ($symbolContext->symbol()->symbolType()) {
            case Symbol::METHOD:
            case Symbol::PROPERTY:
            case Symbol::CONSTANT:
            case Symbol::CASE:
                return $this->gotoMember($symbolContext);
            case Symbol::CLASS_:
                return $this->gotoClass($symbolContext);
            case Symbol::FUNCTION:
                return $this->gotoFunction($symbolContext);
        }

        throw new CouldNotLocateDefinition(sprintf(
            'Do not know how to goto definition of symbol type "%s"',
            $symbolContext->symbol()->symbolType()
        ));
    }

    private function gotoClass(NodeContext $symbolContext): Location
    {
        $className = $symbolContext->type();

        try {
            $class = $this->reflector->reflectClassLike(
                (string) $className
            );
        } catch (NotFound $e) {
            throw new CouldNotLocateDefinition($e->getMessage(), 0, $e);
        }

        $path = $class->sourceCode()->path();

        if (null === $path) {
            throw new CouldNotLocateDefinition(sprintf(
                'The source code for class "%s" has no path associated with it.',
                $class->name()
            ));
        }

        return new Location(
            TextDocumentUri::fromString($path),
            ByteOffset::fromInt($class->position()->start())
        );
    }

    private function gotoFunction(NodeContext $symbolContext): Location
    {
        $functionName = $symbolContext->symbol()->name();

        try {
            $function = $this->reflector->reflectFunction($functionName);
        } catch (NotFound $e) {
            throw new CouldNotLocateDefinition($e->getMessage(), 0, $e);
        }

        $path = $function->sourceCode()->path();

        if (null === $path) {
            throw new CouldNotLocateDefinition(sprintf(
                'The source code for function "%s" has no path associated with it.',
                $function->name()
            ));
        }

        return new Location(
            TextDocumentUri::fromString($path),
            ByteOffset::fromInt($function->position()->start())
        );
    }

    private function gotoMember(NodeContext $symbolContext): Location
    {
        $symbolName = $symbolContext->symbol()->name();
        $symbolType = $symbolContext->symbol()->symbolType();

        if (false === ($symbolContext->containerType()->isDefined())) {
            throw new CouldNotLocateDefinition(sprintf('Containing class for member "%s" could not be determined', $symbolName));
        }

        try {
            $containingClass = $this->reflector->reflectClassLike((string) $symbolContext->containerType());
        } catch (NotFound $e) {
            throw new CouldNotLocateDefinition($e->getMessage());
        }

        if ($symbolType === Symbol::PROPERTY && $containingClass instanceof ReflectionInterface) {
            throw new CouldNotLocateDefinition(sprintf('Symbol is a property and class "%s" is an interface', (string) $containingClass->name()));
        }

        $path = $containingClass->sourceCode()->path();

        if (null === $path) {
            throw new CouldNotLocateDefinition(sprintf(
                'The source code for class "%s" has no path associated with it.',
                (string) $containingClass->name()
            ));
        }

        switch ($symbolType) {
            case Symbol::METHOD:
                $members = $containingClass->methods();
                break;
            case Symbol::CONSTANT:
                if ($containingClass instanceof ReflectionEnum) {
                    $members = $containingClass->cases();
                    break;
                }
                assert($containingClass instanceof ReflectionClass || $containingClass instanceof ReflectionInterface);
                $members = $containingClass->constants();
                break;
            case Symbol::PROPERTY:
                assert($containingClass instanceof ReflectionClass || $containingClass instanceof ReflectionTrait);
                $members = $containingClass->properties();
                break;
            default:
                throw new CouldNotLocateDefinition(sprintf(
                    'Unhandled symbol type "%s"',
                    $symbolType
                ));
        }

        if (false === $members->has($symbolName)) {
            throw new CouldNotLocateDefinition(sprintf(
                'Class "%s" has no %s named "%s", has: "%s"',
                $containingClass->name(),
                $symbolType,
                $symbolName,
                implode('", "', $members->keys())
            ));
        }

        $member = $members->get($symbolName);

        return new Location(
            TextDocumentUri::fromString($path),
            ByteOffset::fromInt($member->position()->start())
        );
    }
}
