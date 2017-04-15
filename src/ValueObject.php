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
     * don't want to send null for all those property values, so toArray()
     * unsets all null properties. The problem with that is, some properties
     * have to be able to be set explicitly to null in e.g. updateLead calls.
     *
     * The properties specified by name here should be kept if they are null -
     * and are unset only if they contain "\0" instead. These properties
     * typically are defined with: public $propertyName = "\0"; They should
     * only be null / undefined by default if you intend for toArray() to keep
     * them by default.
     *
     * @var array
     */
    protected $_nullableProperties = [];

    /**
     * All custom property names used by a subclass. (Not required here.)
     *
     * Array keys are the custom property names and values are the actual
     * Sharpspring custom field names.
     *
     * Custom fields can just be set in an object (like e.g.
     *   $lead->custom_id_nr_56dff55bed3f4 = 1;
     * ), but since Sharpspring custom field system names are tedious and may
     * change when code is used on different Sharpspring accounts /
     * environments, it may be better to define your own custom property names
     * in your own subclass of a value object, and map those to the actual
     * custom field system names. (Defining your own custom property names gets
     * you IDE autocompletion.)
     *
     * The mapping can be handled in different ways, depending on your use case:
     * define this variable statically in your subclass, or set it in the
     * constructor, or call setCustomProperties() on a Connection object. (The
     * latter is more suitable in general code where field names are not always
     * the same - but then all toArray() calls will have to pass the mapping.)
     */
    protected $_customProperties = [];

    /**
     * Constructs an object, converting custom system fields.
     *
     * @param array $values
     *   Values to initialize in the object. We assume custom field values are
     *   set with a Sharpspring 'field system name' key; the corresponding
     *   property will be set to this value.
     * @param array $custom_properties
     *   The custom property name to Sharpspring field system name mapping,
     *   which should be used. Any fields not specified here will be taken from
     *   $this->_customProperties if they are defined there.
     */
    public function __construct(array $values = [], array $custom_properties = [])
    {
        $custom_properties += $this->_customProperties;
        // We assume no duplicate properties are set to the same field system
        // name. If so, it is unclear which property will be filled.
        $custom_fields = array_flip($custom_properties);
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
