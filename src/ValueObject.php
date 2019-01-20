<?php

namespace SharpSpring\RestApi;

use InvalidArgumentException;

/**
 * Base class for Sharpspring value objects (Leads, etc).
 *
 * A value object represents e.g. a lead with all its properties predefined.
 * This is especially useful over using arrays because the Sharpspring API
 * objects' property names are case sensitive, heightening the chance of
 * exceptions thrown because of misspelled property names.
 *
 * Objects are converted to an array before e.g. JSON-encoding them for REST API
 * communication; this should be done using the toArray() method rather than
 * casting to an array (so all unwanted properties get cleared).
 *
 * A subclass can add custom properties that are not necessarily equal to the
 * system names of custom Sharpspring fields; see $_customProperties.
 *
 * Setting most properties to null will cause them to be excluded from the
 * toArray() return value, which means they won't be sent in REST API create /
 * update calls. (These usually cause the REST API to return an object-level
 * error 205 "Invalid parameters", when trying to update them to null.)
 * There are however 'nullable' properties; see $_nullableProperties.
 *
 * There are also non-nullable properties which still have null as the initial
 * value (upon retrieving an object from the REST API after having created it).
 * This probably is the case for every non-custom string field. This seems like
 * a design flaw in the REST API; we have not explicitly marked these and just
 * recommend any callers to treat null and empty string as equal for these
 * fields.
 */
class ValueObject
{
    /**
     * Here's some 'data type' related issue(s) that we might care about
     * (only one, so far):
     *
     * - Sharpspring won't accept empty strings as values in integer ID columns,
     *   which is annoying because Sharpspring itself returns empty values for
     *   these fields in their JSON notation as empty strings. So we will need
     *   to accept empty strings in these properties, and do conversion of
     *   Sharpspring's own return values to make them valid; only for integers
     *   (so far?). Note that sending numeric strings does not seem to generate
     *   errors; only empty strings do.
     *
     * So any non-char/text/string value that could be empty in Sharpspring,
     * probably needs to be registered. (These are non-required int / bigint /
     * tinyint fields. Not sure yet if smallint/double/timestamp fields exists
     * that are non-required; not sure yet if date fields have the same issue.)
     */
    // protected $_schemaTypes = [];
    // NOPE. We won't do this yet. Reason: we have a 'nullable' property for
    // other reasons (see below), and as long as none of our nullable properties
    // are non-string fields... (in other words: as long as we don't see a field
    // which we should be able to update to both '' and null...) AND as long as
    // all our non-string fields which would cause errors, are actually nullable
    // ...we can use $_nullableProperties to prevent sending empty strings.

    /**
     * All property names in the object that are nullable.
     *
     * Most defined properties in a new object start out as unset === null. We
     * don't want to send null for all those property values in update/create
     * calls, so toArray() by default does not return any properties with a null
     * value. (See comments above.) The issue with that is: there are properties
     * which we have to be able to set explicitly to null in e.g. REST API
     * update calls.
     *
     * The properties specified by name here will start out as "\0" (when a
     * class instance is constructed), and if they are set to null explicitly
     * they will be present in the return value of toArray().
     *
     * @var array
     */
    protected static $_nullableProperties = [];

    /**
     * All custom property names used by a subclass. (Not required to be set.)
     *
     * Array keys are the custom property names and values are the actual
     * Sharpspring custom field system names.
     *
     * Custom fields can just be set in an object (like e.g.
     *   $lead->custom_id_nr_56dff55bed3f4 = 1;
     * ), but since Sharpspring custom field system names are tedious and may
     * change when code is used on different Sharpspring accounts/environments,
     * it may be better to define a subclass of ValueObject that has specific
     * property names, and and map those properties to the actual custom field
     * system names. (Defining class properties provides IDE autocompletion.)
     *
     * The mapping can be handled in different ways, depending on the use case:
     * - define $_customProperties statically in the subclass;
     * - set/derive it in the ValueObject constructor code;
     * - pass the mapping as an argument to every ValueObject constructor;
     * - or call setCustomProperties() on a Connection object; this will make
     *   values be converted automatically if a ValueObject is used in
     *   update/create/... calls to the Connection object, but does not have
     *   any effect if you want to feed data returned _from_ the API (which
     *   includes field system names) into a ValueObject.
     * The former two ways are more straightforward, but not suitable in general
     * code that is not tied to specific Sharpspring accounts, because in that
     * case we don't know beforehand how to derive the field system names. The
     * latter two have their own challenges (as per above).
     *
     * Beware that all code in this library makes the following implicit
     * assumptions, which you are expected to follow:
     * - No two property names are mapped to the same custom field system name.
     * - No property name ever doubles as a field system name, or vice versa; in
     *   other words, the two 'namespaces' of properties and custom field names
     *   never overlap. This should be easy enough to adhere to, since field
     *   system names always end with an underscore followed by a 13 digit
     *   hexadecimal number.
     */
    protected $_customProperties = [];

    /**
     * Constructs an object, converting custom field system names to properties.
     *
     * @param array $values
     *   Values to initialize in the object. Custom fields can be provided with
     *   a Sharpspring 'field system name' key; the corresponding property will
     *   be set in this object. Fields/properties starting with an underscore
     *   will be ignored.
     * @param array $custom_properties
     *   The custom property name to Sharpspring field system name mapping,
     *   which should be used in this object (and remembered for toArray()
     *   return values). If the class definition already specifies a mapping,
     *   any properties not present in this argument will be retained.
     */
    public function __construct(array $values = [], array $custom_properties = [])
    {
        // Inject the properties in this object. Keep any properties that are in
        // the class definition, and are not overridden.
        $this->_customProperties = $custom_properties + $this->_customProperties;

        // Initialize nullable properties first; they can be overwritten by
        // null values if these are provided in $values.
        foreach (static::$_nullableProperties as $name) {
            $this->$name = "\0";
        }

        // Set property values, assuming values are keyed by system name (but
        // values keyed by property names will also work). We blindly assume no
        // duplicate properties are mapped to the same field system name. If so,
        // it is unclear which property will be set.
        $custom_fields = array_flip($this->_customProperties);
        foreach ($values as $name => $value) {
            if (isset($custom_fields[$name])) {
                $name = $custom_fields[$name];
            }
            // Silently skip property names that start with an underscore, to
            // prevent obscure bugs that happen when internal class properties
            // are overwritten. (This is not symmetric with toArray() which can
            // read properties starting with an underscore if explicitly told
            // to. There are valid use cases for setting properties like these,
            // e.g. keeping info that is only used by the application and not
            // passed to the REST API, or including values in the toArray()
            // return value only if a custom mapping is provided; these
            // properties just cannot be set through the constructor.)
            if (strpos($name, '_') !== 0) {
                $this->$name = $value;
            }
        }
    }

    /**
     * Converts our object to an array, converting custom properties.
     *
     * This is (/ should be) always used for converting our class instances into
     * 'object representations' (arrays) that can be sent into Sharpspring with
     * e.g. create/update calls. Any class properties that start with an
     * underscore are skipped, unless these are mentioned explicitly as custom
     * properties.
     *
     * @param array $custom_properties
     *   The custom property name to Sharpspring field system name mapping,
     *   which should be used. Properties which are not specified in this
     *   argument but are defined in $this->_customProperties will also be
     *   mapped.
     *
     * @return array
     *   The array value for this object.
     */
    public function toArray(array $custom_properties = [])
    {
        $array = [];
        $custom_properties += $this->_customProperties;

        // All defined properties should be set in the returned array except
        // - our class variables that are defined above (obviously); we will
        //   skip any properties starting with '_' unless they are defined in
        //   custom properties;
        // - null properties (because the REST service will often return
        //   "invalid parameters" errors for null values), except for properties
        //   marked as nullable (in which case we will skip value "\0");
        // - nullable properties with value ''. (This is not because they are
        //   nullable, but because they are assumed to be non-string values and
        //   the REST API will return "invalid parameters" errors for those;
        //   see comments near the class variables.)
        $nullable = array_flip(static::$_nullableProperties);
        foreach ($this as $name => $value) {
            if ((strpos($name, '_') !== 0 || isset($custom_properties[$name]))
                && (isset($nullable[$name]) ? $value !== "\0" && $value !== '' : $value !==  null)) {
                // Set the value. If this is a custom property name, set it in
                // the field system name. (We are assuming that no property
                // named after the field system name is ever set in the object,
                // and that no duplicate properties are mapped to the same field
                // system name. If that happens, values can get lost.)
                if (isset($custom_properties[$name])) {
                    $name = $custom_properties[$name];
                }
                $array[$name] = $value;
            }
        }

        return $array;
    }

    /**
     * Returns schema information for the current object.
     *
     * The reason for this method existing and being static is:
     * - Connection::toArray() needs to have 'schema information' for the basic
     *   types of objects, to fix values before they are sent to the REST API;
     *   (see the issues above $_nullableProperties)
     * - We want to define this schema information in the classes themselves
     *   (next to the property names), not in Connection.
     *
     * The first layer of array keys are (hardcoded) strings representing types
     * of 'schema information'. This way, the return value is extensible (and no
     * other static methods need to be introduced) if we need more information
     * in the future. Only one type is supported so far:
     * - 'nullable': the value is a (numerically indexed) array of names of the
     *   properties which are nullable. (This serves two purposes; see comments
     *   at/near the variable definition.)
     *
     * @param string $type
     *   The schema 'type'. If empty string, all of the schema information is
     *   returned, keyed by type. So far, only 'nullable' is supported.
     *
     * @return array
     *   The requested schema info. (Either all, or only of a certain type.)
     *
     * @throws \InvalidArgumentException
     *   For unrecognized schema types.
     */
    public static function getSchemaInfo($type = '')
    {
        $schema = ['nullable' => static::$_nullableProperties];

        if ($type && !isset($schema[$type])) {
            throw new InvalidArgumentException("Invalid schema type '$type'.", 99);
        }

        return $type ? $schema[$type] : $schema;
    }
}
