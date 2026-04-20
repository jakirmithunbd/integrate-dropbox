<?php

namespace CodeConfig\IDB\Dropbox\Models;

class BaseModel implements ModelInterface
{
    /**
     * Model Data
     *
     * @var array
     */
    protected $data;

    /**
     * Create a new Model instance
     *
     * @param array $data
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * Get the Model data
     *
     * @return array
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * Get Data Property
     *
     * @param string $property
     *
     * @return mixed
     */
    public function getDataProperty($property, $default = null)
    {
        return $this->data[$property] ?? $default;
    }

    /**
     * Initialize a class property from the data array.
     *
     * @param string $property The property name to initialize.
     * @param mixed $default The default value if not found in the data array.
     *
     * @return void
     */
    public function setPropertyFromData($property, $default = null)
    {
        if (property_exists($this, $property)) {
            $this->{$property} = $this->data[$property] ?? $default;
        }
    }

    /**
     * Handle calls to undefined properties.
     * Check whether an item with the key,
     * same as the property, is available
     * on the data property.
     *
     * @param string $property
     *
     * @return mixed|null
     */
    public function __get($property)
    {
        if (array_key_exists($property, $this->getData())) {
            return $this->getData()[$property];
        }

        return null;
    }

    /**
     * Handle calls to undefined properties.
     * Sets an item with the defined value
     * on the data property.
     *
     * @param string $property
     * @param mixed $value
     *
     * @return mixed|null
     */
    public function __set($property, $value)
    {
        $this->data[$property] = $value;
    }
}
