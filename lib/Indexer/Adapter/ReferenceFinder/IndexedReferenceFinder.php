<?php

namespace Phpactor\Indexer\Adapter\ReferenceFinder;

use Generator;
use Phpactor\Indexer\Adapter\ReferenceFinder\Util\ContainerTypeResolver;
use Phpactor\Indexer\Model\QueryClient;
use Phpactor\Indexer\Model\LocationConfidence;
use Phpactor\ReferenceFinder\PotentialLocation;
use Phpactor\ReferenceFinder\ReferenceFinder;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\TextDocument;
use Phpactor\WorseReflection\Core\Exception\NotFound;
use Phpactor\WorseReflection\Core\Inference\Symbol;
use Phpactor\WorseReflection\Core\Inference\NodeContext;
use Phpactor\WorseReflection\Core\Reflection\ReflectionMember;
use Phpactor\WorseReflection\Reflector;
use RuntimeException;

class IndexedReferenceFinder implements ReferenceFinder
{
    private Reflector $reflector;

    private QueryClient $query;

    private ContainerTypeResolver $containerTypeResolver;

    private bool $deepReferences;

    public function __construct(QueryClient $query, Reflector $reflector, ?ContainerTypeResolver $containerTypeResolver = null, bool $deepReferences = true)
    {
        $this->reflector = $reflector;
        $this->query = $query;
        $this->containerTypeResolver = $containerTypeResolver ?: new ContainerTypeResolver($reflector);
        $this->deepReferences = $deepReferences;
    }

    /**
     * @return Generator<PotentialLocation>
     */
    public function findReferences(TextDocument $document, ByteOffset $byteOffset): Generator
    {
        try {
            $symbolContext = $this->reflector->reflectOffset(
                $document->__toString(),
                $byteOffset->toInt()
            )->symbolContext();
        } catch (NotFound $notFound) {
            return;
        }

        foreach ($this->resolveReferences($symbolContext) as $locationConfidence) {
            if ($locationConfidence->isSurely()) {
                yield PotentialLocation::surely($locationConfidence->location());
                continue;
            }

            if ($locationConfidence->isMaybe()) {
                yield PotentialLocation::maybe($locationConfidence->location());
                continue;
            }

            yield PotentialLocation::not($locationConfidence->location());
        }
    }

    /**
     * @return Generator<LocationConfidence>
     */
    private function resolveReferences(NodeContext $symbolContext): Generator
    {
        $symbolType = $symbolContext->symbol()->symbolType();
        if ($symbolType === Symbol::CLASS_) {
            foreach ($this->implementationsOf($symbolContext->type()->__toString()) as $implementationFqn) {
                yield from $this->query->class()->referencesTo($implementationFqn);
            }
            return;
        }

        if ($symbolType === Symbol::FUNCTION) {
            yield from $this->query->function()->referencesTo($symbolContext->symbol()->name());
            return;
        }

        $memberType = $symbolContext->symbol()->symbolType();
        if (in_array($memberType, [
            Symbol::METHOD,
            Symbol::CONSTANT,
            Symbol::PROPERTY,
            Symbol::CASE,
        ])) {
            $containerType = $this->containerTypeResolver->resolveDeclaringContainerType(
                $this->symbolTypeToMemberType($symbolContext),
                $symbolContext->symbol()->name(),
                $symbolContext->containerType()
            );

            if (null === $containerType) {
                yield from $this->query->member()->referencesTo(
                    $symbolContext->symbol()->symbolType(),
                    $symbolContext->symbol()->name(),
                    null
                );
                return;
            }

            // note that we check the all implementations: this will multiply
            // the number of NOT and MAYBE matches
            foreach ($this->implementationsOf($containerType) as $containerType) {
                yield from $this->query->member()->referencesTo(
                    $symbolContext->symbol()->symbolType(),
                    $symbolContext->symbol()->name(),
                    $containerType
                );
            }
            return;
        }
    }

    /**
     * @return Generator<string>
     */
    private function implementationsOf(string $fqn): Generator
    {
        yield $fqn;

        if (false === $this->deepReferences) {
            return;
        }

        foreach ($this->query->class()->implementing($fqn) as $implementation) {
            yield from $this->implementationsOf($implementation->__toString());
        }
    }

    /**
     * @return ReflectionMember::TYPE_*
     */
    private function symbolTypeToMemberType(NodeContext $symbolContext): string
    {
        $symbolType = $symbolContext->symbol()->symbolType();

        if ($symbolType === Symbol::CASE) {
            return ReflectionMember::TYPE_ENUM;
        }
        if ($symbolType === Symbol::METHOD) {
            return ReflectionMember::TYPE_METHOD;
        }
        if ($symbolType === Symbol::PROPERTY) {
            return ReflectionMember::TYPE_PROPERTY;
        }
        if ($symbolType === Symbol::CONSTANT) {
            return ReflectionMember::TYPE_CONSTANT;
        }

        throw new RuntimeException(sprintf(
            'Could not convert symbol type "%s" to member type',
            $symbolType
        ));
    }
}
