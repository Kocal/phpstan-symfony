<?php declare(strict_types = 1);

namespace PHPStan\Rules\Symfony;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\ShouldNotHappenException;
use PHPStan\Type\ArrayType;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\Constant\ConstantIntegerType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\TypeUtils;
use PHPStan\Type\UnionType;
use PHPStan\Type\VerbosityLevel;
use function sprintf;

final class InvalidOptionDefaultValueRule implements Rule
{

	public function getNodeType(): string
	{
		return MethodCall::class;
	}

	/**
	 * @param \PhpParser\Node $node
	 * @param \PHPStan\Analyser\Scope $scope
	 * @return (string|\PHPStan\Rules\RuleError)[] errors
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		if (!$node instanceof MethodCall) {
			throw new ShouldNotHappenException();
		};

		if (!(new ObjectType('Symfony\Component\Console\Command\Command'))->isSuperTypeOf($scope->getType($node->var))->yes()) {
			return [];
		}
		if (!$node->name instanceof Node\Identifier || $node->name->name !== 'addOption') {
			return [];
		}
		if (!isset($node->args[4])) {
			return [];
		}

		$modeType = isset($node->args[2]) ? $scope->getType($node->args[2]->value) : new NullType();
		if ($modeType instanceof NullType) {
			$modeType = new ConstantIntegerType(1); // InputOption::VALUE_NONE
		}
		$modeTypes = TypeUtils::getConstantScalars($modeType);
		if (count($modeTypes) !== 1) {
			return [];
		}
		if (!$modeTypes[0] instanceof ConstantIntegerType) {
			return [];
		}
		$mode = $modeTypes[0]->getValue();

		$defaultType = $scope->getType($node->args[4]->value);

		// not an array
		if (($mode & 8) !== 8) {
			$checkType = new UnionType([new StringType(), new IntegerType(), new NullType()]);
			if (($mode & 4) === 4) { // https://symfony.com/doc/current/console/input.html#options-with-optional-arguments
				$checkType = TypeCombinator::union($checkType, new ConstantBooleanType(false));
			}
			if (!$checkType->isSuperTypeOf($defaultType)->yes()) {
				return [sprintf('Parameter #5 $default of method Symfony\Component\Console\Command\Command::addOption() expects %s, %s given.', $checkType->describe(VerbosityLevel::typeOnly()), $defaultType->describe(VerbosityLevel::typeOnly()))];
			}
		}

		// is array
		if (($mode & 8) === 8 && !(new UnionType([new ArrayType(new IntegerType(), new StringType()), new NullType()]))->isSuperTypeOf($defaultType)->yes()) {
			return [sprintf('Parameter #5 $default of method Symfony\Component\Console\Command\Command::addOption() expects array<int, string>|null, %s given.', $defaultType->describe(VerbosityLevel::typeOnly()))];
		}

		return [];
	}

}
