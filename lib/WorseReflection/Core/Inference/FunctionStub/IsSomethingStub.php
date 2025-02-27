<?php

namespace Phpactor\WorseReflection\Core\Inference\FunctionStub;

use Phpactor\WorseReflection\Core\Inference\FunctionArguments;
use Phpactor\WorseReflection\Core\Inference\FunctionStub;
use Phpactor\WorseReflection\Core\Inference\NodeContext;
use Phpactor\WorseReflection\Core\Inference\Symbol;
use Phpactor\WorseReflection\Core\Inference\TypeAssertion;
use Phpactor\WorseReflection\Core\Inference\TypeCombinator;
use Phpactor\WorseReflection\Core\Type;
use Phpactor\WorseReflection\Core\Type\BooleanLiteralType;

class IsSomethingStub implements FunctionStub
{
    private Type $isType;

    public function __construct(Type $isType)
    {
        $this->isType = $isType;
    }

    public function resolve(
        NodeContext $context,
        FunctionArguments $args
    ): NodeContext {
        $arg0 = $args->at(0);

        $symbol = $arg0->symbol();
        if ($symbol->symbolType() === Symbol::VARIABLE) {
            $context = $context->withTypeAssertion(TypeAssertion::variable(
                $symbol->name(),
                $symbol->position()->start(),
                fn (Type $type) => $this->isType,
                fn (Type $type) => TypeCombinator::subtract($this->isType, $type),
            ));
        }

        $argType = $arg0->type();

        // extract to a variabe as it will not otherwise work with PHP 7.4
        $type = $this->isType;
        return $context->withType(new BooleanLiteralType($argType instanceof $type));
    }
}
