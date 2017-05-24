<?php

/**
 * @copyright 2017 Vladimir Jimenez
 * @license   https://github.com/allejo/PhpPulse/blob/master/LICENSE.md MIT
 */

namespace allejo\DaPulse\Objects;

use allejo\DaPulse\Exceptions\InvalidObjectException;
use allejo\DaPulse\Utilities\HttpClient;

/**
 * The base class for all DaPulse API objects
 *
 * @internal
 * @package allejo\DaPulse\Objects
 * @since   0.1.0
 */
abstract class ApiObject implements \JsonSerializable
{
    /**
     * The namespace used for all main PhpPulse objects. This is value is prepended before PhpPulse objects when being
     * checked with `instanceof`.
     *
     * @internal
     */
    const OBJ_NAMESPACE = "\\allejo\\DaPulse\\";

    /**
     * The default API protocol used for URL calls
     *
     * @internal
     */
    const API_PROTOCOL = "https";

    /**
     * The API end point for URL calls
     *
     * @internal
     */
    const API_ENDPOINT = "api.dapulse.com";

    /**
     * The API version used for URL calls
     *
     * @internal
     */
    const API_VERSION = "v1";

    /**
     * The suffix that is appended to the URL to access functionality for certain objects
     *
     * @internal
     */
    const API_PREFIX = "";

    /**
     * The API key used to make the URL calls
     *
     * @deprecated 0.4.0 This value will either be removed or become private in the next release
     * @var string
     */
    protected static $apiKey;

    /**
     * When set to true, the object can only be constructed from an associative array of data. It will not attempt
     * to fetch the data with an API call; this is intended for objects are not directly accessible via the API.
     *
     * @var bool
     */
    protected $arrayConstructionOnly = false;

    /**
     * Set to true if the object has been deleted via an API call but the instance still exists. This variable will
     * prevent further API calls to a nonexistent object.
     *
     * @var bool
     */
    protected $deletedObject = false;

    /**
     * An associative array representing the original JSON response from DaPulse
     *
     * @var array
     */
    protected $jsonResponse;

    protected $urlEndPoint;

    /**
     * The ID for the object we're handling
     *
     * @var int
     */
    protected $id;

    /** @var HttpClient */
    private static $httpClient;

    /**
     * Create an object from an API call
     *
     * @param int|array $idOrArray Either the numerical ID of an object or an associative array representing a JSON
     *                             response from an API call
     * @param bool      $lazyLoad  When set to true, an initial API call will not be made. An API call will be made when
     *                             the information is requested
     *
     * @throws \InvalidArgumentException The specified object cannot be created directly from an API call but instead
     *                                   requires an associative array of information gathered from other API calls.
     *
     * @since 0.1.0
     */
    public function __construct ($idOrArray, $lazyLoad = false)
    {
        $staticClass = explode("\\", get_called_class());
        $staticClass = end($staticClass);

        if (is_null($idOrArray))
        {
            throw new \InvalidArgumentException("You may not initialize $staticClass with null.");
        }

        if (!is_array($idOrArray))
        {
            $this->urlEndPoint = static::API_PREFIX . '/' . $idOrArray . '.json';
        }

        if ($this->arrayConstructionOnly && !is_array($idOrArray))
        {
            throw new \InvalidArgumentException("A $staticClass cannot be fetched from an ID.");
        }

        $this->initializeValues();

        if (is_array($idOrArray))
        {
            $this->jsonResponse = $idOrArray;
            $this->assignResults();
        }
        else
        {
            if ($lazyLoad)
            {
                $this->id           = $idOrArray;
                $this->jsonResponse = [];
            }
            else
            {
                $this->lazyLoad();
            }
        }
    }

    /**
     * Access the JSON response from DaPulse used to create this wrapper object
     *
     * If a wrapper getter function isn't available for a certain value, use this function to access the value directly.
     *
     * @api
     * @since 0.2.0
     * @return mixed
     */
    public function jsonSerialize ()
    {
        return $this->jsonResponse;
    }

    protected function lazyLoad ()
    {
        if (empty($this->jsonResponse))
        {
            $this->jsonResponse = $this->sendGet($this->urlEndPoint);
            $this->assignResults();
        }
    }

    // ================================================================================================================
    //   Helper functions
    // ================================================================================================================

    /**
     * Assign an associative array from a JSON response and map them to instance variables
     *
     * @since 0.1.0
     */
    final protected function assignResults ()
    {
        foreach ($this->jsonResponse as $key => $val)
        {
            if (property_exists(get_called_class(), $key))
            {
                $this->$key = $val;
            }
        }
    }

    /**
     * Check if the current object has been marked as deleted from DaPulse. If so, throw an exception.
     *
     * @throws InvalidObjectException
     */
    final protected function checkInvalid ()
    {
        if ($this->deletedObject)
        {
            throw new InvalidObjectException("This object no longer exists on DaPulse", 2);
        }
    }

    /**
     * Mark an object as deleted
     *
     * @internal
     */
    final public function _markInvalid ()
    {
        $this->deletedObject = true;
    }

    /**
     * Store the value in an array if the value is not null. This function is a shortcut of setting values in an array
     * only if they are not null, if not leave them unset; used ideally for PUT requests.
     *
     * @param array  $array The array that will store all of the POST parameters
     * @param string $name  The name of the field
     * @param mixed  $value The value to be stored in a given field
     */
    final protected static function setIfNotNullOrEmpty (&$array, $name, $value)
    {
        if (!is_null($value) && !empty($value))
        {
            $array[$name] = $value;
        }
    }

    // ================================================================================================================
    //   Empty functions
    // ================================================================================================================

    /**
     * Overload this function if any class variables need to be initialized to a default value
     */
    protected function initializeValues ()
    {
    }

    // ================================================================================================================
    //   Lazy loading functions
    // ================================================================================================================

    /**
     * Inject data into the array that will be mapped into individual instance variables. This function must be called
     * **before** lazyCastAll() is called and maps the associative array to objects.
     *
     * @param array $target An array of associative arrays with data to be converted into objects
     * @param array $array  An associative array containing data to be merged with the key being the name of the
     *                      instance variable.
     *
     * @throws \InvalidArgumentException If either parameters are not arrays
     *
     * @since 0.1.0
     */
    final protected static function lazyInject (&$target, $array)
    {
        if (!is_array($target) || !is_array($array))
        {
            throw new \InvalidArgumentException("Both the target and array must be arrays");
        }

        // If the first element is an array, let's assume $target hasn't been lazily casted into objects
        if (is_array($target[0]))
        {
            foreach ($target as &$element)
            {
                $element = array_merge($element, $array);
            }
        }
    }

    /**
     * Convert the specified array into an array of object types if needed
     *
     * @param  string $objectType The class name of the Objects the items should be
     * @param  array  $array      The array to check
     *
     * @since  0.2.0
     */
    final protected static function lazyCastAll (&$array, $objectType)
    {
        if (self::lazyCastNeededOnArray($objectType, $array))
        {
            $array = self::castArrayToObjectArray($objectType, $array);
        }
    }

    /**
     * Convert the specified item into the specified object if needed
     *
     * @param mixed  $target     The item to check
     * @param string $objectType The class name of the Objects the items should be
     *
     * @since 0.2.0
     */
    final protected static function lazyCast (&$target, $objectType)
    {
        if (!($target instanceof $objectType))
        {
            $target = new $objectType($target);
        }
    }

    /**
     * Check whether it is required for an array of JSON data to be converted into an array of the specified objects
     *
     * @param  string $objectType The class name of the Objects the items should be
     * @param  array  $array      The array to check
     *
     * @since  0.2.0
     *
     * @return bool True if the array needs to converted into an array of objects
     */
    final protected static function lazyCastNeededOnArray ($objectType, array $array)
    {
        if (is_array($array) && count($array) == 0)
        {
            return false;
        }

        return ($array[0] instanceof $objectType);
    }

    /**
     * Check if an individual item needs to be lazily converted into an object
     *
     * @deprecated 0.4.0 To be removed in 0.5.0; use a regular instanceof
     *
     * @param  mixed  $target     The item to check
     * @param  string $objectType The class name of the Objects the items should be
     *
     * @since  0.2.0
     *
     * @return bool
     */
    final protected static function lazyCastNeeded ($target, $objectType)
    {
        $objectDefinition = ($objectType[0] === "\\") ? $objectType : self::OBJ_NAMESPACE . $objectType;

        return !($target instanceof $objectDefinition);
    }

    /**
     * Sends a GET request for a JSON array and casts the response into an array of objects
     *
     * @param  string $endPoint  The API endpoint to call to get the JSON response from
     * @param  string $className The class name of the Object type to cast to
     * @param  array  $params    An associative array of URL parameters that will be passed to the specific call. For
     *                           example, limiting the number of results or the pagination of results. **Warning** The
     *                           API key does NOT need to be passed here
     *
     * @since  0.2.0
     *
     * @return array
     */
    final protected static function fetchAndCastToObjectArray ($endPoint, $className, $params = [])
    {
        $objects = self::sendGet($endPoint, $params);

        return self::castArrayToObjectArray($className, $objects);
    }

    /**
     * Convert an array of associative arrays into a specific object
     *
     * @param  string $className The class name of the Object type
     * @param  array  $objects   An associative array to be converted into an object
     *
     * @since  0.2.0
     *
     * @return array An array of the specified objects
     */
    final protected static function castArrayToObjectArray ($className, $objects)
    {
        $array = [];

        foreach ($objects as $post)
        {
            $array[] = new $className($post);
        }

        return $array;
    }

    // ================================================================================================================
    //   URL jobs functions
    // ================================================================================================================

    /**
     * Send a GET request to fetch the data from the specified URL
     *
     * @param  string $endPoint        The API endpoint to call
     * @param  array  $queryParameters An associative array of URL parameters that will be passed to the specific call. For
     *                        example, limiting the number of results or the pagination of results. **Warning** The API
     *                        key does NOT need to be passed here
     *
     * @since  0.1.0
     *
     * @return mixed          An associative array of the JSON response from DaPulse
     */
    final protected static function sendGet ($endPoint, array $queryParameters = [])
    {
        return self::sendRequest('GET', $endPoint, $queryParameters, []);
    }

    /**
     * Send a POST request to a specified URL
     *
     * @param  string $endPoint
     * @param  array  $formParameters
     * @param  array  $queryParameters
     *
     * @since  0.1.0
     *
     * @return mixed
     */
    final protected static function sendPost ($endPoint, array $formParameters, array $queryParameters = [])
    {
        return self::sendRequest('POST', $endPoint, $queryParameters, $formParameters);
    }

    /**
     * Send a PUT request to a specified URL
     *
     * @param  string $endPoint
     * @param  array  $formParameters
     * @param  array  $queryParameters
     *
     * @since  0.1.0
     *
     * @return mixed
     */
    final protected static function sendPut ($endPoint, array $formParameters, array $queryParameters = [])
    {
        return self::sendRequest('PUT', $endPoint, $queryParameters, $formParameters);
    }

    /**
     * Send a DELETE request to a specified URL
     *
     * @param  string $endPoint
     * @param  array  $queryParameters
     *
     * @since  0.1.0
     *
     * @return mixed
     */
    final protected static function sendDelete ($endPoint, array $queryParameters = [])
    {
        return self::sendRequest('DELETE', $endPoint, $queryParameters, []);
    }

    /**
     * Send the appropriate URL request
     *
     * @param  string $type
     * @param  string $endPoint
     * @param  array  $queryParameters
     * @param  array  $formParameters
     *
     * @since  0.1.0
     *
     * @throws \InvalidArgumentException
     *
     * @return mixed
     */
    private static function sendRequest ($type, $endPoint, array $queryParameters, array $formParameters)
    {
        switch ($type)
        {
            case 'GET':
                $result = self::$httpClient->get($endPoint, $queryParameters);
                break;

            case 'POST':
                $result = self::$httpClient->post($endPoint, $queryParameters, $formParameters);
                break;

            case 'PUT':
                $result = self::$httpClient->put($endPoint, $queryParameters, $formParameters);
                break;

            case 'DELETE':
                $result = self::$httpClient->delete($endPoint, $queryParameters);
                break;

            default:
                throw new \InvalidArgumentException('HTTP type is not supported.');
        }

        return $result;
    }

    // ================================================================================================================
    //   API key functions
    // ================================================================================================================

    /**
     * Get the base URL to use in all of the API calls
     *
     * @param  string|null $apiPrefix If the API end point is different from the class's constant, this value will be
     *                                used as the suffix for the API endpoint
     *
     * @since  0.1.0
     *
     * @return string The base URL to call
     */
    final protected static function apiEndpoint ($apiPrefix = null)
    {
        $apiSection = isset($apiPrefix) ? $apiPrefix : static::API_PREFIX;

        return sprintf("%s://%s/%s/%s", self::API_PROTOCOL, self::API_ENDPOINT, self::API_VERSION, $apiSection);
    }

    /**
     * Set the API for all calls to the API
     *
     * @param string $apiKey The API key used to access the DaPulse API
     *
     * @since 0.1.0
     */
    final public static function setApiKey ($apiKey)
    {
        self::$apiKey = $apiKey;

        self::$httpClient = new HttpClient(
            sprintf('%s://%s/%s/', self::API_PROTOCOL, self::API_ENDPOINT, self::API_VERSION), [
                'query' => [
                    'api_key' => $apiKey,
                ],
            ]
        );
    }
}
