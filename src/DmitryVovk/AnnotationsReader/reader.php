<?php
namespace DmitryVovk\AnnotationsReader;

use ReflectionClass;

/**
 * Class to handle entity class annotations
 * Syntax:
 *   Class annotations:
 *     entity -- Tells the class is an entity
 *     table <table_name> -- Defines primary table/collection of the entity
 *   Property annotations:
 *     id -- Tells this property contains primary key
 *     column <column_name> -- Specifies property's column name (from the primary table)
 *     join(table: <table_name>, field: <field_name>) -- Property is stored in <table_name>, joined by <field_name> to primary table
 *     var <type> -- Array types get packed/unpacked when stored/retrieved
 *
 * @author Dmitry Vovk <dmitry.vovk@gmail.com>
 */
class Reader {

    const IDENTIFIER = '[_a-zA-Z\x7F-\xFF][_a-zA-Z0-9\x7F-\xFF-\\\]*';
    const STRING = '\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*"';

    /**
     * Read annotations from class with caching
     *
     * @param string|\Object $class
     *
     * @return array
     */
    public static function read($class) {
        if (is_object($class)) {
            $class = get_class($class);
        }
        assert(is_string($class));
        $r = new ReflectionClass($class);
        $annotations['class'] = static::get_class_annotations($class, $r);
        foreach ($r->getProperties() as $property) {
            $doc = $property->getDocComment();
            if ($doc) {
                $propertyAnnotation = static::list_annotations($doc);
                if (static::is_valid_annotation($propertyAnnotation)) {
                    $annotations['property'][$property->getName()] = $propertyAnnotation;
                    if (isset($propertyAnnotation['id'])) {
                        $annotations['id'] = $property->getName();
                    }
                }
            }
        }
        return $annotations;
    }

    /**
     * Recursively read class annotations with inheritdoc support
     *
     * @param string $class
     *
     * @return array
     */
    protected static function get_class_annotations($class, ReflectionClass $r) {
        $classAnnotations = static::list_annotations($r->getDocComment());
        $classAnnotations['class_name'] = $class;
        $classAnnotations['extends'] = get_parent_class($class);
        $parentClassAnnotations = [];
        if (array_key_exists('inherit', $classAnnotations) && $classAnnotations['extends']) {
            $parentClassAnnotations = static::get_class_annotations($classAnnotations['extends'], $r);
        }
        return array_replace_recursive($parentClassAnnotations, $classAnnotations);
    }

    /**
     * @param array $annotation
     *
     * @return bool
     */
    protected static function is_valid_annotation(array $annotation) {
        $hasVar = isset($annotation['var']);
        if (!$hasVar) {
            return false;
        }
        $varValid = in_array($annotation['var'], ['array', 'bool', 'int', 'integer', 'string', 'float', 'null'], true);
        if (!$varValid) {
            return false;
        }
        $hasColumn = isset($annotation['column']);
        $hasJoinEntity = isset($annotation['join']['entity']);
        if ($hasColumn || $hasJoinEntity) {
            return true;
        }
        return false;
    }

    /**
     * Extract annotations from doc string
     *
     * @param string $doc
     *
     * @return array
     */
    protected static function list_annotations($doc) {
        $doc = preg_replace('/^\s*\*\s*/ms', '', trim($doc, '/*'));
        $regexp =
            '~
                (?<=\s|^)@(' . self::IDENTIFIER . ')
                [ \t]*
                (
                    \(
                        (?>' . self::STRING . ' | [^\'")@]+)+
                    \)
                    |
                    [^(\r\n][^\r\n]*
                    |
                )
            ~xi';
        $result = [];
        if (preg_match_all($regexp, $doc, $m)) {
            while (count($m[1])) {
                $index = array_shift($m[1]);
                $value = static::parse(array_shift($m[2]));
                if (array_key_exists($index, $result)) {
                    if (is_array($result[$index])) {
                        $result[$index][] = $value;
                    } else {
                        $oldVal = $result[$index];
                        $result[$index] = [];
                        $result[$index][] = $oldVal;
                        $result[$index][] = $value;
                    }
                } else {
                    $result[$index] = is_string($value)
                        ? trim($value, '"')
                        : $value;
                }
            }
        }
        return $result;
    }

    /**
     * Parse annotation
     *
     * @param string $annotation
     *
     * @return array|bool|string
     */
    protected static function parse($annotation) {
        $result = [];
        if (!empty($annotation)) {
            if ($annotation[0] === '(') {
                $annotation[0] = ',';
                preg_match_all('#\s*,\s*(?>(' . self::IDENTIFIER . ')\s*[=:]\s*)?(' . self::STRING . '|[^\'"),\s][^\'"),]*)#A', $annotation, $m);
                while (count($m[1])) {
                    $key = array_shift($m[1]);
                    $val = array_shift($m[2]);
                    $parsedVal = json_decode($val, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $val = $parsedVal;
                    }
                    if ($val === 'false') {
                        $val = false;
                    }
                    if ($val === 'true') {
                        $val = true;
                    }
                    if ($key) {
                        $result[$key] = $val;
                    } else {
                        $result[] = $val;
                    }
                }
            } else {
                if ($annotation === 'false') {
                    return false;
                }
                if ($annotation === 'true') {
                    return true;
                }
                return $annotation;
            }
        } else {
            return true;
        }
        return $result;
    }
}
