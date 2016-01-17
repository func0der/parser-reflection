<?php
/**
 * Parser Reflection API
 *
 * @copyright Copyright 2015, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Go\ParserReflection;

use Go\ParserReflection\Traits\InternalPropertiesEmulationTrait;
use Go\ParserReflection\ValueResolver\NodeExpressionResolver;
use PhpParser\Node\Name;
use PhpParser\Node\Param;
use ReflectionParameter as BaseReflectionParameter;

/**
 * AST-based reflection for method/function parameter
 */
class ReflectionParameter extends BaseReflectionParameter
{
    use InternalPropertiesEmulationTrait;

    /**
     * Reflection function or method
     *
     * @var \ReflectionFunctionAbstract
     */
    private $declaringFunction;

    /**
     * Stores the default value for node (if present)
     *
     * @var mixed
     */
    private $defaultValue = null;

    /**
     * Whether or not default value is constant
     *
     * @var bool
     */
    private $isDefaultValueConstant = false;

    /**
     * Name of the constant of default value
     *
     * @var string
     */
    private $defaultValueConstantName;

    /**
     * Index of parameter in the list
     *
     * @var int
     */
    private $parameterIndex = 0;

    /**
     * Concrete parameter node
     *
     * @var Param
     */
    private $parameterNode;

    /**
     * Initializes a reflection for the property
     *
     * @param string|array $unusedFunctionName Name of the function/method
     * @param string $parameterName Name of the parameter to reflect
     * @param Param $parameterNode Parameter definition node
     * @param int $parameterIndex Index of parameter
     * @param \ReflectionFunctionAbstract $declaringFunction
     */
    public function __construct(
        $unusedFunctionName,
        $parameterName,
        Param $parameterNode = null,
        $parameterIndex = 0,
        \ReflectionFunctionAbstract $declaringFunction = null
    ) {
        // Let's unset original read-only property to have a control over it via __get
        unset($this->name);

        $this->parameterNode     = $parameterNode;
        $this->parameterIndex    = $parameterIndex;
        $this->declaringFunction = $declaringFunction;

        if ($this->isDefaultValueAvailable()) {
            if ($declaringFunction instanceof \ReflectionMethod) {
                $context = $declaringFunction->getDeclaringClass();
            } else {
                $context = $declaringFunction;
            };

            $expressionSolver = new NodeExpressionResolver($context);
            $expressionSolver->process($this->parameterNode->default);
            $this->defaultValue             = $expressionSolver->getValue();
            $this->isDefaultValueConstant   = $expressionSolver->isConstant();
            $this->defaultValueConstantName = $expressionSolver->getConstantName();
        }
    }

    /**
     * Emulating original behaviour of reflection
     */
    public function ___debugInfo()
    {
        return array(
            'name' => $this->parameterNode->name,
        );
    }

    /**
     * Returns string representation of this parameter.
     *
     * @return string
     */
    public function __toString()
    {
        $parameterType = $this->parameterNode->type;
        if (is_object($parameterType)) {
            $parameterType = $parameterType->toString();
        }
        $isNullableParam = !empty($parameterType) && $this->allowsNull();
        $isOptional      = $this->isOptional();
        $defaultValue    = '';
        if ($isOptional) {
            $defaultValue = $this->getDefaultValue();
            if (is_string($defaultValue) && strlen($defaultValue) > 15) {
                $defaultValue = substr($defaultValue, 0, 15) . '...';
            }
            /* @see https://3v4l.org/DJOEb for behaviour changes */
            if (is_double($defaultValue) && fmod($defaultValue, 1.0) === 0.0) {
                $defaultValue = (int) $defaultValue;
            }
            $defaultValue = str_replace('\\\\', '\\', var_export($defaultValue, true));
        }

        return sprintf(
            'Parameter #%d [ %s %s%s%s%s$%s%s ]',
            $this->parameterIndex,
            ($this->isVariadic() || $isOptional) ? '<optional>' : '<required>',
            $parameterType ? ltrim($parameterType, '\\') . ' ' : '',
            $isNullableParam ? 'or NULL ' : '',
            $this->isVariadic() ? '...' : '',
            $this->isPassedByReference() ? '&' : '',
            $this->getName(),
            $isOptional ? (' = ' . $defaultValue) : ''
        );
    }

    /**
     * {@inheritDoc}
     */
    public function allowsNull()
    {
        $hasDefaultNull = $this->isDefaultValueAvailable() && $this->getDefaultValue() === null;
        if ($hasDefaultNull) {
            return true;
        }

        return !isset($this->parameterNode->type);
    }

    /**
     * {@inheritDoc}
     */
    public function canBePassedByValue()
    {
        return !$this->isPassedByReference();
    }

    /**
     * @inheritDoc
     */
    public function getClass()
    {
        $parameterType = $this->parameterNode->type;
        if ($parameterType instanceof Name) {
            if (!$parameterType instanceof Name\FullyQualified) {
                throw new ReflectionException("Can not resolve a class name for parameter");
            }
            $className   = $parameterType->toString();
            $classExists = class_exists($className, false);

            return $classExists ? new \ReflectionClass($className) : new ReflectionClass($className);
        }

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function getDeclaringClass()
    {
        if ($this->declaringFunction instanceof \ReflectionMethod) {
            return $this->declaringFunction->getDeclaringClass();
        };

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function getDeclaringFunction()
    {
        return $this->declaringFunction;
    }

    /**
     * {@inheritDoc}
     */
    public function getDefaultValue()
    {
        if (!$this->isDefaultValueAvailable()) {
            throw new ReflectionException('Internal error: Failed to retrieve the default value');
        }

        return $this->defaultValue;
    }

    /**
     * {@inheritDoc}
     */
    public function getDefaultValueConstantName()
    {
        if (!$this->isDefaultValueAvailable()) {
            throw new ReflectionException('Internal error: Failed to retrieve the default value');
        }

        return $this->defaultValueConstantName;
    }

    /**
     * @inheritDoc
     */
    public function getName()
    {
        return $this->parameterNode->name;
    }

    /**
     * {@inheritDoc}
     */
    public function getPosition()
    {
        return $this->parameterIndex;
    }

    /**
     * @inheritDoc
     */
    public function getType()
    {
        $isBuiltin     = false;
        $allowsNull    = $this->allowsNull();
        $parameterType = $this->parameterNode->type;
        if (is_object($parameterType)) {
            $parameterType = $parameterType->toString();
        } elseif (is_string($parameterType)) {
            $isBuiltin = true;
        }

        return new ReflectionType($parameterType, $allowsNull, $isBuiltin);
    }

    /**
     * @inheritDoc
     */
    public function hasType()
    {
        $hasType = isset($this->parameterNode->type);

        return $hasType;
    }

    /**
     * @inheritDoc
     */
    public function isArray()
    {
        return 'array' === $this->parameterNode->type;
    }

    /**
     * @inheritDoc
     */
    public function isCallable()
    {
        return 'callable' === $this->parameterNode->type;
    }

    /**
     * @inheritDoc
     */
    public function isDefaultValueAvailable()
    {
        return isset($this->parameterNode->default);
    }

    /**
     * {@inheritDoc}
     */
    public function isDefaultValueConstant()
    {
        return $this->isDefaultValueConstant;
    }

    /**
     * {@inheritDoc}
     */
    public function isOptional()
    {
        return $this->isDefaultValueAvailable() && $this->haveSiblingsDefalutValues();
    }

    /**
     * @inheritDoc
     */
    public function isPassedByReference()
    {
        return (bool) $this->parameterNode->byRef;
    }

    /**
     * @inheritDoc
     */
    public function isVariadic()
    {
        return (bool) $this->parameterNode->variadic;
    }

    /**
     * Returns if all following parameters have a default value definition.
     *
     * @return bool
     * @throws ReflectionException If could not fetch declaring function reflection
     */
    protected function haveSiblingsDefalutValues()
    {
        $function = $this->getDeclaringFunction();
        if (null === $function) {
            throw new ReflectionException('Could not get the declaring function reflection.');
        }

        /** @var \ReflectionParameter[] $remainingParameters */
        $remainingParameters = array_slice($function->getParameters(), $this->parameterIndex + 1);
        foreach ($remainingParameters as $reflectionParameter) {
            if (!$reflectionParameter->isDefaultValueAvailable()) {
                return false;
            }
        }

        return true;
    }
}
