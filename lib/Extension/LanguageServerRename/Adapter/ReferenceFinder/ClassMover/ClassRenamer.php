<?php

namespace Phpactor\Extension\LanguageServerRename\Adapter\ReferenceFinder\ClassMover;

use Generator;
use Microsoft\PhpParser\NamespacedNameInterface;
use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\QualifiedName as MicrosoftQualifiedName;
use Microsoft\PhpParser\Node\Statement\ClassDeclaration;
use Microsoft\PhpParser\Node\Statement\InterfaceDeclaration;
use Microsoft\PhpParser\Node\Statement\TraitDeclaration;
use Microsoft\PhpParser\Parser;
use Microsoft\PhpParser\ResolvedName;
use Phpactor\ClassMover\ClassMover;
use Phpactor\ClassMover\Domain\Name\QualifiedName;
use Phpactor\Extension\LanguageServerRename\Adapter\Tolerant\TokenUtil;
use Phpactor\Extension\LanguageServerRename\Model\Exception\CouldNotConvertClassToUri;
use Phpactor\Extension\LanguageServerRename\Model\Exception\CouldNotRename;
use Phpactor\Extension\LanguageServerRename\Model\LocatedTextEdit;
use Phpactor\Extension\LanguageServerRename\Model\NameToUriConverter;
use Phpactor\Extension\LanguageServerRename\Model\RenameResult;
use Phpactor\Extension\LanguageServerRename\Model\Renamer;
use Phpactor\ReferenceFinder\ReferenceFinder;
use Phpactor\TextDocument\ByteOffset;
use Phpactor\TextDocument\ByteOffsetRange;
use Phpactor\TextDocument\TextDocument;
use Phpactor\TextDocument\TextDocumentLocator;
use RuntimeException;

final class ClassRenamer implements Renamer
{
    private NameToUriConverter $newNameToUriConverter;

    private NameToUriConverter $oldNameToUriConverter;

    private ReferenceFinder $referenceFinder;

    private TextDocumentLocator $locator;

    private Parser $parser;

    private ClassMover $classMover;

    public function __construct(
        NameToUriConverter $oldNameToUriConverter,
        NameToUriConverter $newNameToUriConverter,
        ReferenceFinder $referenceFinder,
        TextDocumentLocator $locator,
        Parser $parser,
        ClassMover $classMover
    ) {
        $this->oldNameToUriConverter = $oldNameToUriConverter;
        $this->newNameToUriConverter = $newNameToUriConverter;
        $this->referenceFinder = $referenceFinder;
        $this->locator = $locator;
        $this->parser = $parser;
        $this->classMover = $classMover;
    }

    public function getRenameRange(TextDocument $textDocument, ByteOffset $offset): ?ByteOffsetRange
    {
        $node = $this->parser->parseSourceFile($textDocument->__toString())->getDescendantNodeAtPosition($offset->toInt());

        if ($node instanceof ClassDeclaration) {
            return TokenUtil::offsetRangeFromToken($node->name, false);
        }

        if ($node instanceof InterfaceDeclaration) {
            return TokenUtil::offsetRangeFromToken($node->name, false);
        }

        if ($node instanceof TraitDeclaration) {
            return TokenUtil::offsetRangeFromToken($node->name, false);
        }

        if ($node instanceof MicrosoftQualifiedName) {
            return TokenUtil::offsetRangeFromToken($node, false);
        }

        return null;
    }

    public function rename(TextDocument $textDocument, ByteOffset $offset, string $newName): Generator
    {
        $node = $this->parser->parseSourceFile($textDocument->__toString())->getDescendantNodeAtPosition($offset->toInt());

        $originalName = $this->getFullName($node);
        $newName = $this->createNewName($originalName, $newName);

        try {
            $oldUri = $this->oldNameToUriConverter->convert($originalName->getFullyQualifiedNameText());
            $newUri = $this->newNameToUriConverter->convert($newName);
        } catch (CouldNotConvertClassToUri $error) {
            throw new CouldNotRename($error->getMessage(), 0, $error);
        }

        if ($newName === $originalName->getFullyQualifiedNameText()) {
            return;
        }

        $seen = [];
        foreach ($this->referenceFinder->findReferences($textDocument, $offset) as $reference) {
            if (isset($seen[$reference->location()->uri()->__toString()])) {
                continue;
            }
            $seen[$reference->location()->uri()->__toString()] = true;

            if (!$reference->isSurely()) {
                continue;
            }

            $referenceDocument = $this->locator->get($reference->location()->uri());

            $edits = $this->classMover->replaceReferences(
                $this->classMover->findReferences($referenceDocument->__toString(), $originalName->__toString()),
                QualifiedName::fromString($newName)
            );

            foreach ($edits as $edit) {
                yield new LocatedTextEdit(
                    $reference->location()->uri(),
                    $edit,
                );
            }
        }

        return new RenameResult($oldUri, $newUri);
    }

    private function rangeText(TextDocument $textDocument, ByteOffsetRange $range): string
    {
        return substr(
            $textDocument->__toString(),
            $range->start()->toInt(),
            $range->end()->toInt() - $range->start()->toInt()
        );
    }

    private function getFullName(Node $node): ResolvedName
    {
        if ($node instanceof MicrosoftQualifiedName) {
            $name = $node->getResolvedName();
            if (!$name instanceof ResolvedName) {
                throw new RuntimeException(sprintf(
                    'Could not get resolved name for node "%s"',
                    get_class($node)
                ));
            }

            return $name;
        }

        if ($node instanceof NamespacedNameInterface) {
            return $node->getNamespacedName();
        }

        throw new RuntimeException(sprintf(
            'Could not resolve full name for node "%s"',
            get_class($node)
        ));
    }

    private function createNewName(ResolvedName $originalName, string $newName): string
    {
        $parts = $originalName->getNameParts();

        if (count($parts) === 1) {
            return $newName;
        }

        array_pop($parts);
        $newName = implode('\\', $parts) . '\\' . $newName;
        return $newName;
    }
}
