<?php

namespace SharpSpring\RestApi;

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
    protected $_nullableProperties = [];

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
     * - set it in the constructor;
     * - or call setCustomProperties() on a Connection object.
     * The former two ways are more straightforward, but not suitable in general
     * code that is not tied to specific Sharpspring accounts, because in that
     * case we don't know beforehand how to derive the field system names. This
     * means all toArray() calls need to pass the mapping (because
     * $_customProperties is never set in the class); most create/update methods
     * on the Connection object do this transparently after
     * setCustomProperties() is called.
     *
     * Beware that all code in this library makes the following implicit
     * assumptions, which you are expected to follow:
     * - No two property names are mapped to the sane custom field system name.
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
     *   Values to initialize in the object. We assume custom field values are
     *   set with a Sharpspring 'field system name' key; the corresponding
     *   property will be set to this value.
     * @param array $custom_properties
     *   The custom property name to Sharpspring field system name mapping,
     *   which should be used in this object (and remembered for toArray()
     *   return values). If the class definition already specifies a mapping,
     *   any properties not present in this argument will be retained.
     */
    public function __construct(array $values = [], array $custom_properties = [])
    {
        // Inject the properties in this object. Keep any unspecified properties
        // that are in the class definition.
        $this->_customProperties = $custom_properties + $this->_customProperties;

        // Initialize nullable properties first; they can be overwritten by
        // null values if these are provided in $values.
        foreach ($this->_nullableProperties as $name) {
            $this->$name = "\0";
        }

        // Set property values, assuming values are keyed by system name.
        // (Custom property names will also work, however.) We blindly assume no
        // duplicate properties are mapped to the same field system name. If so,
        // it is unclear which property will be set.
        $custom_fields = array_flip($this->_customProperties);
        foreach ($values as $name => $value) {
            if (isset($custom_fields[$name])) {
                $name = $custom_fields[$name];
            }
            $this->$name = $value;
        }
    }

    /**
     * Converts our object to an array, converting custom properties.
     *
     * This is (/ should be) always used for converting our class instances into
     * 'object representations' (arrays) that can be sent into Sharpspring with
     * e.g. create/update calls.
     *
     * @param array $custom_properties
     *   The custom property name to Sharpspring field system name mapping,
     *   which should be used. Any fields not specified here will be taken from
     *   $this->_customProperties if they are defined there.
     *
     * @return array
     *   The array value for this object.
     */
    public function toArray(array $custom_properties = [])
    {
        $array = [];
        $custom_properties += $this->_customProperties;

        // All defined properties should be set in the array except null
        // properties (because otherwise the REST service will return "invalid
        // value" errors). The exception to these are 'nullable' properties; in
        // their case the 'skip' value is "\0".
        $nullable = array_flip($this->_nullableProperties);
        foreach ($this as $name => $value) {
            if (strpos($name, '_') !== 0 && $value !== (isset($nullable[$name]) ? "\0" : null)) {
                // Set the value. But where? If this is a custom property name,
                // translate it to the field system name. (We are assuming that
                // no property named after the field system name is ever set in
                // the object, and that no duplicate properties are mapped to
                // the same field system name. If that happens, values can get
                // lost in the array.)
                if (isset($custom_properties[$name])) {
                    $name = $custom_properties[$name];
                }
                $array[$name] = $value;
            }
        }

        return $array;
    }
}
