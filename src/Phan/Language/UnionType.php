<?php declare(strict_types=1);
namespace Phan\Language;

use \Phan\AST\ContextNode;
use \Phan\AST\UnionTypeVisitor;
use \Phan\AST\Visitor\KindVisitorImplementation;
use \Phan\CodeBase;
use \Phan\Config;
use \Phan\Debug;
use \Phan\Exception\IssueException;
use \Phan\Exception\CodeBaseException;
use \Phan\Language\Context;
use \Phan\Language\FQSEN\FullyQualifiedFunctionName;
use \Phan\Language\FQSEN\FullyQualifiedMethodName;
use \Phan\Language\Type;
use \Phan\Language\Type\ArrayType;
use \Phan\Language\Type\BoolType;
use \Phan\Language\Type\CallableType;
use \Phan\Language\Type\FloatType;
use \Phan\Language\Type\GenericArrayType;
use \Phan\Language\Type\IntType;
use \Phan\Language\Type\MixedType;
use \Phan\Language\Type\NativeType;
use \Phan\Language\Type\NullType;
use \Phan\Language\Type\ObjectType;
use \Phan\Language\Type\ResourceType;
use \Phan\Language\Type\ScalarType;
use \Phan\Language\Type\StringType;
use \Phan\Language\Type\VoidType;
use \Phan\Log;
use \ast\Node;

class UnionType {
    use \Phan\Memoize;

    /**
     * @var Type[]
     */
    private $type_list = [];

    /**
     * @param Type[] $type_list
     * An optional list of types represented by this union
     */
    public function __construct(array $type_list = []) {
        foreach ($type_list as $type) {
            $this->addType($type);
        }
    }

    /**
     * After a clone is called on this object, clone our
     * deep objects.
     *
     * @return null
     */
    public function __clone() {
        $type_list = [];

        foreach ($this->type_list as $key => $type) {
            $type_list[$key] = $type ? clone($type) : $type;
        }

        $this->type_list = $type_list;
    }

    /**
     * @param string $fully_qualified_string
     * A '|' delimited string representing a type in the form
     * 'int|string|null|ClassName'.
     *
     * @param Context $context
     * The context in which the type string was
     * found
     *
     * @return UnionType
     */
    public static function fromFullyQualifiedString(
        string $fully_qualified_string
    ) : UnionType {
        if (empty($fully_qualified_string)) {
            return new UnionType();
        }

        return new UnionType(
            array_map(function(string $type_name) {
                return Type::fromFullyQualifiedString($type_name);
            }, explode('|', $fully_qualified_string))
        );

    }

    /**
     * @param string $type_string
     * A '|' delimited string representing a type in the form
     * 'int|string|null|ClassName'.
     *
     * @param Context $context
     * The context in which the type string was
     * found
     *
     * @return UnionType
     */
    public static function fromStringInContext(
        string $type_string,
        Context $context
    ) : UnionType {
        if (empty($type_string)) {
            return new UnionType();
        }

        return new UnionType(
            array_map(function(string $type_name) use ($context, $type_string) {
                assert($type_name !== '',
                    "Type cannot be empty. Type '$type_name' given as part of the union type '$type_string' in $context.");
                return Type::fromStringInContext(
                    $type_name,
                    $context
                );
            }, array_filter(array_map(function (string $type_name) {
                return trim($type_name);
            }, explode('|', $type_string))))
        );
    }

    /**
     * @param Context $context
     * The context of the parser at the node for which we'd
     * like to determine a type
     *
     * @param CodeBase $code_base
     * The code base within which we're operating
     *
     * @param Node|string|null $node
     * The node for which we'd like to determine its type
     *
     * @param bool $should_catch_issue_exception
     * Set to true to cause loggable issues to be thrown
     * instead of emitted as issues to the log.
     *
     * @return UnionType
     *
     * @throws IssueException
     * If $should_catch_issue_exception is false an IssueException may
     * be thrown for optional issues.
     */
    public static function fromNode(
        Context $context,
        CodeBase $code_base,
        $node,
        bool $should_catch_issue_exception = true
    ) : UnionType {
        return UnionTypeVisitor::unionTypeFromNode(
            $code_base,
            $context,
            $node,
            $should_catch_issue_exception
        );
	}

    public static function internalClassSignatureMapForName(
        string $class_name,
        string $property_name
    ) : UnionType {
        $map = self::internalClassSignatureMap();

        $class_property_type_map =
            $map[strtolower($class_name)]['properties'];

        $property_type_name =
            $class_property_type_map[$property_name];

        return new UnionType([$property_type_name]);
    }

    /**
     * A list of types for parameters associated with the
     * given builtin function with the given name
     *
     * @param FullyQualifiedMethodName|FullyQualifiedFunctionName $function_fqsen
     *
     * @see internal_varargs_check
     * Formerly `function internal_varargs_check`
     */
    public static function internalFunctionSignatureMapForFQSEN(
        $function_fqsen
    ) : array {
        $context = new Context;

        $map = self::internalFunctionSignatureMap();

        if ($function_fqsen instanceof FullyQualifiedMethodName) {
            $class_fqsen =
                $function_fqsen->getFullyQualifiedClassName();
            $class_name = $class_fqsen->getName();
            $function_name =
                $class_name . '::' . $function_fqsen->getName();
        } else {
            $function_name = $function_fqsen->getName();
        }

        $function_name_original = $function_name;
        $alternate_id = 0;

        $configurations = [];
        while (isset($map[$function_name])) {
            // Get some static data about the function
            $type_name_struct = $map[$function_name];
            if (empty($type_name_struct)) {
                continue;
            }

            // Figure out the return type
            $return_type_name = array_shift($type_name_struct);
            $return_type = $return_type_name
                ? UnionType::fromStringInContext($return_type_name, $context)
                : null;

            $name_type_name_map = $type_name_struct;
            $property_name_type_map = [];

            foreach ($name_type_name_map as $name => $type_name) {
                $property_name_type_map[$name] = empty($type_name)
                    ? new UnionType()
                    : UnionType::fromStringInContext($type_name, $context);
            }

            $configurations[] = [
                'return_type' => $return_type,
                'property_name_type_map' => $property_name_type_map,
            ];

            $function_name =
                $function_name_original . '\'' . (++$alternate_id);
        }

        return $configurations;
    }

    /**
     * @return bool
     * True if a builtin with the given FQSEN exists, else
     * flase.
     */
    public static function builtinExists(FQSEN $fqsen) : bool {
        return !empty(
            self::internalFunctionSignatureMap()[(string)$fqsen]
        );
    }

    /**
     * @return Type[]
     * The list of simple types associated with this
     * union type.
     */
    public function getTypeList() {
        return $this->type_list;
    }

    /**
     * @return Type
     * Get the first type in this set
     */
    public function head() : Type {

        if (empty($this->getTypeList())) {
            debug_print_backtrace(3);
        }

        assert(!empty($this->getTypeList()),
            'Cannot call head() on empty UnionType');

        return array_values($this->getTypeList())[0];
    }

    /**
     * Add a type name to the list of types
     *
     * @return void
     */
    public function addType(Type $type) {
        // Only allow unique elements
        if (!$this->hasType($type)) {
            $this->type_list[(string)$type] = $type;
        }
    }

    /**
     * Remove a type name to the list of types
     *
     * @return void
     */
    public function removeType(Type $type) {
        unset($this->type_list[(string)$type]);
    }


    /**
     * @return bool
     * True if this union type contains the given named
     * type.
     */
    public function hasType(Type $type) : bool {
        return array_key_exists((string)$type, $this->type_list);
    }

    /**
     * Add the given types to this type
     *
     * @return null
     */
    public function addUnionType(UnionType $union_type) {
        foreach ($union_type->getTypeList() as $i => $type) {
            $this->addType($type);
        }
    }

    /**
     * @return bool
     * True if this type has a type referencing the
     * class context in which it exists such as 'static'
     * or 'self'.
     */
    public function hasSelfType() : bool {
        return array_reduce($this->getTypeList(),
            function (bool $carry, Type $type) : bool {
                return ($carry || $type->isSelfType());
            }, false);
    }

    /**
     * @return bool
     * True if and only if this UnionType contains
     * the given type and no others.
     */
    public function isType(Type $type) : bool {
        if (count($this->getTypeList()) != 1) {
            return false;
        }

        return ((string)$this->head() == (string)$type);
    }

    /**
     * @return bool
     * True if this UnionType is exclusively native
     * types
     */
    public function isNativeType() : bool {
        if (empty($this->getTypeList())) {
            return false;
        }

        return array_reduce($this->getTypeList(),
            function (bool $carry, Type $type) : bool {
                return ($carry && $type->isNativeType());
            }, true);
    }

    /**
     * @return bool
     * True iff this union contains the exact set of types
     * represented in the given union type.
     */
    public function isEqualTo(UnionType $union_type) : bool {
        return ((string)$this === (string)$union_type);
    }

    /**
     * @param Type[] $type_list
     * A list of types
     *
     * @return bool
     * True if this union type contains any of the given
     * named types
     */
    public function hasAnyType(array $type_list) : bool {
        return array_reduce($type_list,
            function(bool $carry, Type $type)  {
                return ($carry || $this->hasType($type));
            }, false);
    }

    /**
     * @return int
     * The number of types in this union type
     */
    public function typeCount() : int {
        return count($this->getTypeList());
    }

    /**
     * @return bool
     * True if this Union has no types
     */
    public function isEmpty() : bool {
        return ($this->typeCount() < 1);
    }

    /**
     * @param UnionType $target
     * The type we'd like to see if this type can cast
     * to
     *
     * @param CodeBase $code_base
     * The code base used to expand types
     *
     * @return bool
     * Test to see if this type can be cast to the
     * given type after expanding both union types
     * to include all ancestor types
     */
    public function canCastToExpandedUnionType(
        UnionType $target,
        CodeBase $code_base
    ) : bool {

        $this_expanded =
            $this->asExpandedTypes($code_base);

        $target_expanded =
            $target->asExpandedTypes($code_base);

        return
            $this_expanded->canCastToUnionType(
                $target_expanded
            );
    }

    /**
     * @param UnionType $target
     * A type to check to see if this can cast to it
     *
     * @return bool
     * True if this type is allowed to cast to the given type
     * i.e. int->float is allowed  while float->int is not.
     *
     * @see \Phan\Deprecated\Pass2::type_check
     * Formerly 'function type_check'
     */
    public function canCastToUnionType(
        UnionType $target
    ) : bool {
        // Fast-track most common cases first

        // If either type is unknown, we can't call it
        // a success
        if($this->isEmpty() || $target->isEmpty()) {
            return true;
        }

        // T === T
        if ($this->isEqualTo($target)) {
            return true;
        }

        if (Config::get()->null_casts_as_any_type) {
            // null <-> null
            if ($this->isType(NullType::instance())
                || $target->isType(NullType::instance())
            ) {
                return true;
            }
        }

        // mixed <-> mixed
        if ($target->hasType(MixedType::instance())
            || $this->hasType(MixedType::instance())
        ) {
            return true;
        }

        // int -> float
        if ($this->isType(IntType::instance())
            && $target->isType(FloatType::instance())
        ) {
            return true;
        }

        // Check conversion on the cross product of all
        // type combinations and see if any can cast to
        // any.
        foreach($this->getTypeList() as $source_type) {
            if(empty($source_type)) {
                continue;
            }
            foreach($target->getTypeList() as $target_type) {
                if(empty($target_type)) {
                    continue;
                }

                if ($source_type->canCastToType($target_type)) {
                    return true;
                }
            }
        }

        // Only if no source types can be cast to any target
        // types do we say that we cannot perform the cast
        return false;
    }

    /**
     * @return bool
     * True if all types in this union are scalars
     *
     * @see \Phan\Deprecated\Util::type_scalar
     * Formerly `function type_scalar`
     */
    public function isScalar() : bool {
        if ($this->isEmpty() || count($this->getTypeList()) > 1) {
            return false;
        }

        return $this->head()->isScalar();
    }

    /**
     * @return UnionType
     * Get the subset of types which are not native
     */
    public function nonNativeTypes() : UnionType {
        return new UnionType(
            array_filter($this->getTypeList(), function(Type $type) {
                return !$type->isNativeType();
            })
        );
    }

    /**
     * @return Clazz[]
     * A list of classes representing the non-native types
     * associated with this UnionType
     *
     * @throws CodeBaseException
     * An exception is thrown if a non-native type does not have
     * an associated class
     */
    public function asClassList(
        CodeBase $code_base
    ) {
        // Iterate over each viable class type to see if any
        // have the constant we're looking for
        foreach ($this->nonNativeTypes()->getTypeList()
            as $class_type
        ) {
            // Get the class FQSEN
            $class_fqsen = $class_type->asFQSEN();

            // See if the class exists
            if (!$code_base->hasClassWithFQSEN($class_fqsen)) {
                throw new CodeBaseException(
                    $class_fqsen,
                    "Cannot find class $class_fqsen"
                );
            }

            yield $code_base->getClassByFQSEN($class_fqsen);
        }
    }

    /**
     * Takes "a|b[]|c|d[]|e" and returns "a|c|e"
     *
     * @return UnionType
     * A UnionType with generic types filtered out
     *
     * @see \Phan\Deprecated\Pass2::nongenerics
     * Formerly `function nongenerics`
     */
    public function nonGenericArrayTypes() : UnionType {
        return new UnionType(
            array_filter($this->getTypeList(), function(Type $type) {
                return !$type->isGenericArray();
            })
        );
    }

    /**
     * @return bool
     * True if this is exclusively generic types
     */
    public function isGenericArray() : bool {
        if ($this->isEmpty()) {
            return false;
        }

        return array_reduce($this->getTypeList(),
            function (bool $carry, Type $type) : bool {
                return ($carry && $type->isGenericArray());
            }, true);
    }

    /**
     * Takes "a|b[]|c|d[]|e" and returns "b|d"
     *
     * @return UnionType
     * The subset of types in this
     */
    public function genericArrayElementTypes() : UnionType {
        // If array is in there, then it can be any type
        // Same for mixed
        if ($this->hasType(ArrayType::instance())
            || $this->hasType(MixedType::instance())
        ) {
            return MixedType::instance()->asUnionType();
        }

        if ($this->hasType(ArrayType::instance())) {
            return NullType::instance()->asUnionType();
        }

        return new UnionType(array_filter(array_map(
            /**
             * @return Type|null
             */
            function(Type $type) {
                if (!$type->isGenericArray()) {
                    return null;
                }
                return $type->genericArrayElementType();
            }, $this->getTypeList()))
        );
    }

    /**
     * @return UnionType
     * Get a new type for each type in this union which is
     * the generic array version of this type. For instance,
     * 'int|float' will produce 'int[]|float[]'.
     */
    public function asGenericArrayTypes() : UnionType {
        return new UnionType(
            array_map(function (Type $type) : Type {
                return $type->asGenericArrayType();
            }, $this->getTypeList())
        );
    }

    /**
     * @return UnionType
     * A new UnionType that is a copy of this UnionType without
     * the given type.
     */
    public function withoutType(Type $type) : UnionType {
        return new UnionType(
            array_filter($this->getTypeList(),
                function (Type $type) : bool {
                    return ((string)$type  != (string)$type);
                }
            )
        );
    }


    /**
     * @param CodeBase
     * The code base to use in order to find super classes, etc.
     *
     * @param $recursion_depth
     * This thing has a tendency to run-away on me. This tracks
     * how bad I messed up by seeing how far the expanded types
     * go
     *
     * @return UnionType
     * Expands all class types to all inherited classes returning
     * a superset of this type.
     */
    public function asExpandedTypes(
        CodeBase $code_base,
        int $recursion_depth = 0
    ) : UnionType {
        assert($recursion_depth < 10,
            "Recursion has gotten out of hand for type $this");

        $union_type = clone($this);

        foreach ($this->getTypeList() as $type) {
            $union_type->addUnionType(
                $type->asExpandedTypes(
                    $code_base,
                    $recursion_depth + 1
                )
            );
        }

        return $union_type;
    }

    /**
     * As per the Serializable interface
     *
     * @return string
     * A serialized representation of this type
     *
     * @see \Serializable
     */
    public function serialize() : string {
        return (string)$this;
    }

    /**
     * As per the Serializable interface
     *
     * @param string $serialized
     * A serialized UnionType
     *
     * @return UnionType
     * A UnionType representing the given serialized form
     *
     * @see \Serializable
     */
    public function unserialize($serialized) {
        return self::fromFullyQualifiedString($serialized);
    }

    /**
     * @return string
     * A human-readable string representation of this union
     * type
     */
    public function __toString() : string {
        // Copy the list so that we don't sort the
        // actual list. We rely on order of the list to get
        // the 'primary' class from the type of a variable,
        // for instance.
        $type_list = array_merge([], $this->type_list);

        // Sort the types so that we get a stable
        // representation
        ksort($type_list);

        // Delimit by '|'
        return implode('|', array_map(function(Type $type) : string {
            return (string)$type;
        }, $type_list));
    }

    /**
     * @return array
     * A map from builtin function name to type information
     *
     * @see \Phan\Language\Internal\FunctionSignatureMap
     */
    public static function internalFunctionSignatureMap() {
        static $map = false;

        if (!$map) {
            $map = require(__DIR__.'/Internal/FunctionSignatureMap.php');
        }

        return $map;
    }

    /**
     * @return array
     * A map from builtin class names to type information
     *
     * @see \Phan\Language\Type\BuiltinFunctionArgumentTypes
     */
    private static function internalClassSignatureMap() {
        static $map = false;

        if (!$map) {
            $map = require(__DIR__.'/Internal/ClassSignatureMap.php');
        }

        return $map;
    }

}
