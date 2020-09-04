<?php

namespace Simple_REST_API;

use ReflectionException;
use WP_REST_Request;
use WP_REST_Response;
use ReflectionFunction;
use WP_Error;

/**
 * Class Route
 * @package Simple_REST_API
 */
class Route
{
    protected $path;
    protected $method;
    protected $callback;

    protected $custom_params = [];

    protected $accept_json = false;

    protected $asserts = [];
    protected $converts = [];
    protected $before = [];
    protected $after = [];

    /**
     * Route constructor.
     * @param string $method
     * @param string $path
     * @param callable $callback
     */
    public function __construct( $method, $path, callable $callback )
    {
        $this->method = $method;

        if ( 0 !== mb_strpos( $path, '/' ) ) {
            $path = '/' . $path;
        }

        $this->path = $path;
        $this->callback = $callback;
    }

    /**
     * @return mixed
     */
    public function get_method()
    {
        return $this->method;
    }

    /**
     * @return string
     */
    public function get_path()
    {
        return $this->replace_path_custom_params( $this->path, $this->custom_params );
    }

    /**
     * @return bool
     */
    public function is_accept_json()
    {
        return $this->accept_json;
    }

    /**
     * @param bool $accept
     * @return $this
     */
    public function accept_json( $accept = true )
    {
        $this->accept_json = boolval( $accept );

        return $this;
    }

    /**
     * Sets a callback to handle before triggering the route callback.
     *
     * @param callable $callback A PHP callback to be triggered when the route is matched, just before the route callback
     *
     * @return Route $this The current instance
     */
    public function before( callable $callback )
    {
        $this->before[] = $callback;

        return $this;
    }

    /**
     * Sets a callback to handle after the route callback.
     *
     * @param callable $callback A PHP callback to be triggered after the route callback
     *
     * @return Route $this The current instance
     */
    public function after( callable $callback )
    {
        $this->after[] = $callback;

        return $this;
    }

    /**
     * Sets the requirement for a route variable.
     *
     * @param string $variable The variable name
     * @param string $pattern The regexp to apply
     *
     * @return Route $this The current instance
     */
    public function assert( $variable, $pattern )
    {
        $this->asserts[ $variable ] = $pattern;

        return $this;
    }

    /**
     * Sets a converter for a route variable.
     *
     * @param string $variable The variable name
     * @param callable $callback A PHP callback that converts the original value
     *
     * @return Route $this The current instance
     */
    public function convert( $variable, callable $callback )
    {
        $this->converts[ $variable ] = $callback;

        return $this;
    }

    /**
     * @param WP_REST_Request $request
     * @return mixed|WP_Error|WP_REST_Response
     * @throws ReflectionException
     */
    public function execute( WP_REST_Request $request )
    {
        $response = new WP_REST_Response();

        foreach ( $this->converts as $key => $convert_callback ) {
            if ( in_array( $key, array_values( $this->custom_params ) ) ) {
                $url_params = $request->get_url_params();
                $url_params[ $key ] = $this->execute_callback( $convert_callback, $request, $response );
                $request->set_url_params( $url_params );
            }
        }

        foreach ( $this->before as $before_callback ) {
            $this->execute_callback( $before_callback, $request, $response );
        }

        $result = $this->execute_callback( $this->callback, $request, $response );

        if ( $result ) {
            if ( ! ( $result instanceof WP_REST_Response || $result instanceof WP_Error ) ) {
                $response->set_data( $result );
            } else {
                $response = $result;
            }
        }

        if ( ! ( $response instanceof WP_Error ) ) {
            foreach ( $this->after as $after_callback ) {
                $this->execute_callback( $after_callback, $request, $response );
            }
        }

        return $response;
    }

    /**
     * @param callable         $callback
     * @param WP_REST_Request  $request
     * @param WP_REST_Response $response
     * @return mixed
     * @throws ReflectionException
     */
    protected function execute_callback( callable $callback, WP_REST_Request $request, WP_REST_Response $response )
    {
        $reflection = new ReflectionFunction( $callback );
        $reflection_parameters = $reflection->getParameters();

        $args = [];

        foreach ( $reflection_parameters as $reflection_parameter ) {
            $name = $reflection_parameter->getName();

            if ( 'request' == $name ) {
                $args[ $name ] = $request;
            } elseif ( 'response' == $name ) {
                $args[ $name ] = $response;
            } elseif ( in_array( $name, array_values( $this->custom_params ) ) ) {
                $url_params = $request->get_url_params();
                $args[ $name ] = $url_params[ $name ];
            }
        }

        return call_user_func_array( $callback, $args );
    }

    /**
     * @param string $path
     * @param array  $params
     * @return string
     */
    protected function replace_path_custom_params( $path, &$params )
    {
        $params = [];
        $matches = null;

        if ( preg_match_all( '/\{([a-zA-z0-9]+)\}/i', $path, $matches ) ) {
            foreach ( $matches[1] as $param ) {
                $path_parts = explode( '{' . $param . '}', $path );

                $index = 1;

                if (
                    count( $path_parts ) > 0 &&
                    preg_match_all( '/[^\\\\](\()/i', $path_parts[0], $groups )
                ) {
                    $index = count( $groups[1] ) + 1;
                }

                $params[ $index ] = $param;
                $pattern = array_key_exists( $param, $this->asserts ) ? $this->asserts[ $param ] : '[^/]+';
                $path = implode( '(?P<' . $param . '>' . $pattern . ')', $path_parts );
            }
        }

        return $path;
    }
}