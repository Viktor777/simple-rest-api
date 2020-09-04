<?php

namespace Simple_REST_API;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Class Router
 * @package Simple_REST_API
 */
class Router
{
    protected $namespace;

    /**
     * @var Route[]
     */
    protected $routes = [];

    protected $before = [];
    protected $after = [];

    protected $options = [
        'etag' => false,
    ];

    /**
     * @param string $namespace The first URL segment after core prefix. Should be unique to your package/plugin.
     * @param array  $options
     */
    public function __construct( $namespace, array $options = [] )
    {
        $namespace = trim( $namespace, '/' );
        $this->namespace = $namespace;
        $this->options = wp_parse_args( $options, $this->options );

        add_action( 'rest_api_init', function() {
            $this->register();
        } );
    }

    /**
     * Maps a GET request to a callable.
     *
     * @param string   $path Matched route path
     * @param callable $callback Callback that returns the response when matched
     * @return Route
     */
    public function get( $path, callable $callback )
    {
        $route = new Route( 'GET', $path, $callback );
        $this->routes[] = $route;

        return $route;
    }

    /**
     * Maps a POST request to a callable.
     *
     * @param string   $path Matched route path
     * @param callable $callback Callback that returns the response when matched
     * @return Route
     */
    public function post( $path, callable $callback )
    {
        $route = new Route( 'POST', $path, $callback );
        $this->routes[] = $route;

        return $route;
    }

    /**
     * Maps a PUT request to a callable.
     *
     * @param string   $path Matched route path
     * @param callable $callback Callback that returns the response when matched
     * @return Route
     */
    public function put( $path, callable $callback )
    {
        $route = new Route( 'PUT', $path, $callback );
        $this->routes[] = $route;

        return $route;
    }

    /**
     * Maps a PATCH request to a callable.
     *
     * @param string   $path Matched route path
     * @param callable $callback Callback that returns the response when matched
     * @return Route
     */
    public function patch( $path, callable $callback )
    {
        $route = new Route( 'PATCH', $path, $callback );
        $this->routes[] = $route;

        return $route;
    }

    /**
     * Maps a DELETE request to a callable.
     *
     * @param string   $path Matched route path
     * @param callable $callback Callback that returns the response when matched
     * @return Route
     */
    public function delete( $path, callable $callback )
    {
        $route = new Route( 'DELETE', $path, $callback );
        $this->routes[] = $route;

        return $route;
    }

    /**
     * Maps a request to a callable.
     *
     * @param string   $method Request method
     * @param string   $path Matched route path
     * @param callable $callback Callback that returns the response when matched
     * @return Route
     */
    public function match( $method, $path, callable $callback )
    {
        $route = new Route( mb_strtoupper( $method ), $path, $callback );
        $this->routes[] = $route;

        return $route;
    }

    /**
     * Sets a callback to handle before triggering any route callback.
     *
     * @param callable $callback A PHP callback to be triggered when the route is matched, just before the route callback
     *
     * @return Router $this The current instance
     */
    public function before( callable $callback )
    {
        $this->before[] = $callback;

        return $this;
    }

    /**
     * Sets a callback to handle after any route callback.
     *
     * @param callable $callback A PHP callback to be triggered after the route callback
     *
     * @return Router $this The current instance
     */
    public function after( callable $callback )
    {
        $this->after[] = $callback;

        return $this;
    }

    protected function register()
    {
        foreach ( $this->routes as $route ) {
            foreach ( $this->before as $callback ) {
                $route->before( $callback );
            }

            foreach ( $this->after as $callback ) {
                $route->after( $callback );
            }

            $args = [
                'methods'             => $route->get_method(),
                'accept_json'         => $route->is_accept_json(),
                'permission_callback' => '__return_true', // @TODO: Create a method.
                'callback'            => function ( WP_REST_Request $request ) use ( $route ) {
                    $response = $route->execute( $request );

                    if ( ! ( $response instanceof WP_Error ) ) {
                        $this->maybe_add_etag( $request, $response );
                    }

                    return $response;
                }
            ];

            register_rest_route( $this->namespace, $route->get_path(), $args );
        }
    }

    /**
     * @param WP_REST_Request  $request
     * @param WP_REST_Response $response
     */
    protected function maybe_add_etag( WP_REST_Request $request, WP_REST_Response $response )
    {
        if ( $this->options['etag'] && 'GET' === $request->get_method() && 200 == $response->get_status() ) {
            $etag = md5( serialize( $response->get_data() ) );
            $response->header( 'Etag', $etag );

            if ( $etag && $etag === $request->get_header( 'if_none_match' ) ) {
                $response->set_status( 304 ); // Not Modified
                $response->set_data( null );
            }
        }
    }
}