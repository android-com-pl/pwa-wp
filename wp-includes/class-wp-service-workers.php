<?php
/**
 * Dependencies API: WP_Service_Workers class
 *
 * @since 0.1
 *
 * @package PWA
 */

/**
 * Class used to register service workers.
 *
 * @since 0.1
 *
 * @see WP_Dependencies
 */
class WP_Service_Workers extends WP_Scripts {
	/**
	 * Param for service workers.
	 *
	 * @var string
	 */
	const QUERY_VAR = 'wp_service_worker';

	/**
	 * Scope for front.
	 *
	 * @var int
	 */
	const SCOPE_FRONT = 1;

	/**
	 * Scope for admin.
	 *
	 * @var int
	 */
	const SCOPE_ADMIN = 2;

	/**
	 * Scope for both front and admin.
	 *
	 * @var int
	 */
	const SCOPE_ALL = 3;

	/**
	 * Stale while revalidate caching strategy.
	 *
	 * @var string
	 */
	const STRATEGY_STALE_WHILE_REVALIDATE = 'staleWhileRevalidate';

	/**
	 * Cache first caching strategy.
	 *
	 * @var string
	 */
	const STRATEGY_CACHE_FIRST = 'cacheFirst';

	/**
	 * Network first caching strategy.
	 *
	 * @var string
	 */
	const STRATEGY_NETWORK_FIRST = 'networkFirst';

	/**
	 * Cache only caching strategy.
	 *
	 * @var string
	 */
	const STRATEGY_CACHE_ONLY = 'cacheOnly';

	/**
	 * Network only caching strategy.
	 *
	 * @var string
	 */
	const STRATEGY_NETWORK_ONLY = 'networkOnly';

	/**
	 * Output for service worker scope script.
	 *
	 * @var string
	 */
	public $output = '';

	/**
	 * Registered caching routes and scripts.
	 *
	 * @var array
	 */
	public $registered_caching_routes = array();

	/**
	 * Registered routes and files for precaching.
	 *
	 * @var array
	 */
	public $registered_precaching_routes = array();

	/**
	 * Initialize the class.
	 */
	public function init() {

		$this->register(
			'workbox-sw',
			array( $this, 'get_workbox_script' ),
			array()
		);

		$this->register(
			'caching-utils-sw',
			PWA_PLUGIN_URL . '/wp-includes/js/service-worker.js',
			array( 'workbox-sw' )
		);

		// @todo Add precache as a default script?
		// @todo This needs to be added at the very end of the service worker so the navigation routing will apply after all others.
		$this->register(
			'error-response-handling',
			array( $this, 'get_error_response_handling_script' ),
			array( 'workbox-sw' )
		);

		/**
		 * Fires when the WP_Service_Workers instance is initialized.
		 *
		 * @param WP_Service_Workers $this WP_Service_Workers instance (passed by reference).
		 */
		do_action_ref_array( 'wp_default_service_workers', array( &$this ) );
	}

	/**
	 * Get the current scope for the service worker request.
	 *
	 * @return int Scope. Either SCOPE_FRONT, SCOPE_ADMIN, or if neither then 0.
	 * @global WP $wp
	 */
	public function get_current_scope() {
		global $wp;
		if ( ! isset( $wp->query_vars[ self::QUERY_VAR ] ) || ! is_numeric( $wp->query_vars[ self::QUERY_VAR ] ) ) {
			return 0;
		}
		$scope = (int) $wp->query_vars[ self::QUERY_VAR ];
		if ( self::SCOPE_FRONT === $scope ) {
			return self::SCOPE_FRONT;
		} elseif ( self::SCOPE_ADMIN === $scope ) {
			return self::SCOPE_ADMIN;
		}
		return 0;
	}

	/**
	 * Get script for handling of error responses when the user is offline or when there is an internal server error.
	 *
	 * @return string Script.
	 */
	public function get_error_response_handling_script() {

		$revision = sprintf( '%s-v%s', get_template(), wp_get_theme( get_template() )->Version );
		if ( get_template() !== get_stylesheet() ) {
			$revision .= sprintf( ';%s-v%s', get_stylesheet(), wp_get_theme( get_stylesheet() )->Version );
		}

		$scope = $this->get_current_scope();
		if ( self::SCOPE_FRONT === $scope ) {
			$offline_error_precache_entry = array(
				'url'      => add_query_arg( 'wp_error_template', 'offline', home_url( '/' ) ),
				'revision' => $revision,
			);
			$server_error_precache_entry  = array(
				'url'      => add_query_arg( 'wp_error_template', '500', home_url( '/' ) ),
				'revision' => $revision,
			);

			/**
			 * Filters what is precached to serve as the offline error response on the frontend.
			 *
			 * The URL returned in this array will be precached by the service worker and served as the response when
			 * the client is offline or their connection fails. To prevent this behavior, this value can be filtered
			 * to return false. When a theme or plugin makes a change to the response, the revision value in the array
			 * must be incremented to ensure the URL is re-fetched to store in the precache.
			 *
			 * @since 0.2
			 *
			 * @param array|false $entry {
			 *     Offline error precache entry.
			 *
			 *     @type string $url      URL to page that shows the offline error template.
			 *     @type string $revision Revision for the template. This defaults to the template and stylesheet names, with their respective theme versions.
			 * }
			 */
			$offline_error_precache_entry = apply_filters( 'wp_offline_error_precache_entry', $offline_error_precache_entry );

			/**
			 * Filters what is precached to serve as the internal server error response on the frontend.
			 *
			 * The URL returned in this array will be precached by the service worker and served as the response when
			 * the server returns a 500 internal server error . To prevent this behavior, this value can be filtered
			 * to return false. When a theme or plugin makes a change to the response, the revision value in the array
			 * must be incremented to ensure the URL is re-fetched to store in the precache.
			 *
			 * @since 0.2
			 *
			 * @param array $entry {
			 *     Server error precache entry.
			 *
			 *     @type string $url      URL to page that shows the server error template.
			 *     @type string $revision Revision for the template. This defaults to the template and stylesheet names, with their respective theme versions.
			 * }
			 */
			$server_error_precache_entry = apply_filters( 'wp_server_error_precache_entry', $server_error_precache_entry );

		} else {
			$offline_error_precache_entry = array(
				'url'      => add_query_arg( 'code', 'offline', admin_url( 'admin-ajax.php?action=wp_error_template' ) ), // Upon core merge, this would use admin_url( 'error.php' ).
				'revision' => PWA_VERSION, // Upon core merge, this should be the core version.
			);
			$server_error_precache_entry  = array(
				'url'      => add_query_arg( 'code', '500', admin_url( 'admin-ajax.php?action=wp_error_template' ) ), // Upon core merge, this would use admin_url( 'error.php' ).
				'revision' => PWA_VERSION, // Upon core merge, this should be the core version.
			);
		}

		$this->register_precached_routes( array_filter( array(
			$offline_error_precache_entry,
			$server_error_precache_entry,
		) ) );

		$blacklist_patterns = array();
		if ( self::SCOPE_FRONT === $scope ) {
			$blacklist_patterns[] = '^' . preg_quote( untrailingslashit( wp_parse_url( admin_url(), PHP_URL_PATH ) ), '/' ) . '($|\?.*|/.*)';
		}

		$replacements = array(
			'ERROR_OFFLINE_URL'  => isset( $offline_error_precache_entry['url'] ) ? wp_json_encode( $offline_error_precache_entry['url'] ) : null,
			'ERROR_500_URL'      => isset( $server_error_precache_entry['url'] ) ? wp_json_encode( $server_error_precache_entry['url'] ) : null,
			'BLACKLIST_PATTERNS' => wp_json_encode( $blacklist_patterns ),
		);

		$script = file_get_contents( PWA_PLUGIN_DIR . '/wp-includes/js/service-worker-error-response-handling.js' ); // phpcs:ignore

		return str_replace(
			array_keys( $replacements ),
			array_values( $replacements ),
			$script
		);
	}

	/**
	 * Get workbox script.
	 *
	 * @return string Script.
	 */
	public function get_workbox_script() {

		$workbox_dir = 'wp-includes/js/workbox-v3.4.1/';

		$script = sprintf(
			"importScripts( %s );\n",
			wp_json_encode( PWA_PLUGIN_URL . $workbox_dir . 'workbox-sw.js', 64 /* JSON_UNESCAPED_SLASHES */ )
		);

		$options = array(
			'debug'            => WP_DEBUG,
			'modulePathPrefix' => PWA_PLUGIN_URL . $workbox_dir,
		);
		$script .= sprintf( "workbox.setConfig( %s );\n", wp_json_encode( $options, 64 /* JSON_UNESCAPED_SLASHES */ ) );

		/**
		 * Filters whether navigation preload is enabled.
		 *
		 * The filtered value will be sent as the Service-Worker-Navigation-Preload header value if a truthy string.
		 * This filter should be set to return false to disable navigation preload such as when a site is using
		 * the app shell model.
		 *
		 * @param bool|string $navigation_preload Whether to use navigation preload.
		 */
		$navigation_preload = apply_filters( 'service_worker_navigation_preload', true ); // @todo This needs to vary between admin and backend.
		if ( false !== $navigation_preload ) {
			if ( is_string( $navigation_preload ) ) {
				$script .= sprintf( "workbox.navigationPreload.enable( %s );\n", wp_json_encode( $navigation_preload ) );
			} else {
				$script .= "workbox.navigationPreload.enable();\n";
			}
		} else {
			$script .= "/* Navigation preload disabled. */\n";
		}
		return $script;
	}

	/**
	 * Register service worker.
	 *
	 * Registers service worker if no item of that name already exists.
	 *
	 * @param string          $handle Name of the item. Should be unique.
	 * @param string|callable $src    URL to the source in the WordPress install, or a callback that returns the JS to include in the service worker.
	 * @param array           $deps   Optional. An array of registered item handles this item depends on. Default empty array.
	 * @param int             $scope  Scope for which service worker the script will be part of. Can be WP_Service_Workers::SCOPE_FRONT, WP_Service_Workers::SCOPE_ADMIN, or WP_Service_Workers::SCOPE_ALL. Default to WP_Service_Workers::SCOPE_ALL.
	 * @return bool Whether the item has been registered. True on success, false on failure.
	 */
	public function register( $handle, $src, $deps = array(), $scope = self::SCOPE_ALL ) {
		if ( ! in_array( $scope, array( self::SCOPE_FRONT, self::SCOPE_ADMIN, self::SCOPE_ALL ), true ) ) {
			_doing_it_wrong( __METHOD__, esc_html__( 'Scope must be either WP_Service_Workers::SCOPE_ALL, WP_Service_Workers::SCOPE_FRONT, or WP_Service_Workers::SCOPE_ADMIN.', 'pwa' ), '0.1' );
			$scope = self::SCOPE_ALL;
		}

		return parent::add( $handle, $src, $deps, false, compact( 'scope' ) );
	}

	/**
	 * Register route and caching strategy.
	 *
	 * @param string $route    Route regular expression, without delimiters.
	 * @param string $strategy Strategy, can be WP_Service_Workers::STRATEGY_STALE_WHILE_REVALIDATE, WP_Service_Workers::STRATEGY_CACHE_FIRST,
	 *                         WP_Service_Workers::STRATEGY_NETWORK_FIRST, WP_Service_Workers::STRATEGY_CACHE_ONLY,
	 *                         WP_Service_Workers::STRATEGY_NETWORK_ONLY.
	 * @param array  $strategy_args {
	 *     An array of strategy arguments.
	 *
	 *     @type string $cache_name Cache name. Optional.
	 *     @type array  $plugins    Array of plugins with configuration. The key of each plugin in the array must match the plugin's name.
	 *                              See https://developers.google.com/web/tools/workbox/guides/using-plugins#workbox_plugins.
	 * }
	 */
	public function register_cached_route( $route, $strategy, $strategy_args = array() ) {

		if ( ! in_array( $strategy, array(
			self::STRATEGY_STALE_WHILE_REVALIDATE,
			self::STRATEGY_CACHE_FIRST,
			self::STRATEGY_CACHE_ONLY,
			self::STRATEGY_NETWORK_FIRST,
			self::STRATEGY_NETWORK_ONLY,
		), true ) ) {
			_doing_it_wrong( __METHOD__, esc_html__( 'Strategy must be either WP_Service_Workers::STRATEGY_STALE_WHILE_REVALIDATE, WP_Service_Workers::STRATEGY_CACHE_FIRST,
	            WP_Service_Workers::STRATEGY_NETWORK_FIRST, WP_Service_Workers::STRATEGY_CACHE_ONLY, or WP_Service_Workers::STRATEGY_NETWORK_ONLY.', 'pwa' ), '0.2' );
			return;
		}

		if ( ! is_string( $route ) ) {
			/* translators: %s is caching strategy */
			$error = sprintf( __( 'Route for the caching strategy %s must be a string.', 'pwa' ), $strategy );
			_doing_it_wrong( __METHOD__, esc_html( $error ), '0.2' );
		} else {

			$this->registered_caching_routes[] = array(
				'route'         => $route,
				'strategy'      => $strategy,
				'strategy_args' => $strategy_args,
			);
		}
	}

	/**
	 * Register routes / files for precaching.
	 *
	 * @param array $routes {
	 *      Array of routes.
	 *
	 *      @type string $url      URL of the route.
	 *      @type string $revision Revision (optional).
	 * }
	 */
	public function register_precached_routes( $routes ) {
		if ( ! is_array( $routes ) || empty( $routes ) ) {
			_doing_it_wrong( __METHOD__, esc_html__( 'Routes must be an array.', 'pwa' ), '0.2' );
			return;
		}
		$this->registered_precaching_routes = array_merge(
			$routes,
			$this->registered_precaching_routes
		);
	}

	/**
	 * Register scripts which are pre-cached.
	 *
	 * @todo Consider storing the script $handles to refer to later by the offline page for what to dequeue.
	 * @since 0.2
	 * @param array $handles Script handles.
	 */
	public function register_precached_scripts( $handles ) {
		$precache_entries = array();
		$original_to_do   = wp_scripts()->to_do;
		wp_scripts()->all_deps( $handles );
		foreach ( wp_scripts()->to_do as $handle ) {
			if ( ! isset( wp_scripts()->registered[ $handle ] ) ) {
				continue;
			}
			$dependency = wp_scripts()->registered[ $handle ];

			// Skip bundles.
			if ( ! $dependency->src ) {
				continue;
			}

			$src = $dependency->src;

			$ver = false === $dependency->ver ? get_bloginfo( 'version' ) : $dependency->ver;

			// @todo Opt to remove 'ver' in favor of having arg included among ignoreUrlParametersMatching.
			$src = add_query_arg( 'ver', $ver, $src );

			/** This filter is documented in wp-includes/class.wp-scripts.php */
			$src = apply_filters( 'script_loader_src', $src, $handle );

			if ( $src ) {
				$precache_entries[] = array(
					'url'      => $src,
					'revision' => (string) $ver,
				);
			}
		}
		$this->register_precached_routes( $precache_entries );
		wp_scripts()->to_do = $original_to_do; // Restore original scripts to do.
	}

	/**
	 * Register styles which are pre-cached.
	 *
	 * @todo Consider storing the style $handles to refer to later by the offline page for what to dequeue.
	 * @since 0.2
	 * @param array $handles style handles.
	 */
	public function register_precached_styles( $handles ) {
		$precache_entries = array();
		$original_to_do   = wp_styles()->to_do;
		wp_styles()->all_deps( $handles );
		foreach ( wp_styles()->to_do as $handle ) {
			if ( ! isset( wp_styles()->registered[ $handle ] ) ) {
				continue;
			}
			$dependency = wp_styles()->registered[ $handle ];

			// Skip bundles.
			if ( ! $dependency->src ) {
				continue;
			}

			$src = $dependency->src;

			$ver = false === $dependency->ver ? get_bloginfo( 'version' ) : $dependency->ver;

			// @todo Opt to remove 'ver' in favor of having arg included among ignoreUrlParametersMatching.
			$src = add_query_arg( 'ver', $ver, $src );

			/** This filter is documented in wp-includes/class.wp-styles.php */
			$src = apply_filters( 'style_loader_src', $src, $handle );

			if ( $src ) {
				$precache_entries[] = array(
					'url'      => $src,
					'revision' => (string) $ver,
				);
			}
		}
		$this->register_precached_routes( $precache_entries );
		wp_styles()->to_do = $original_to_do; // Restore original styles to do.
	}

	/**
	 * Gets the script for precaching routes.
	 *
	 * @param array $routes Array of routes.
	 * @return string Precaching logic.
	 */
	protected function get_precaching_for_routes_script( $routes ) {

		$routes_list = array();
		foreach ( $routes as $route ) {
			if ( is_string( $route ) ) {
				$route = array( 'url' => $route );
			}
			if ( ! isset( $route['revision'] ) ) {
				$route['revision'] = get_bloginfo( 'version' );
			}

			$routes_list[] = $route;
		}
		if ( empty( $routes_list ) ) {
			return '';
		}

		// @todo This should include 'ver' among the ignoreUrlParametersMatching.
		// @todo We should not do precacheAndRoute here. We should just call precache. Otherwise then use staleWhileRevalidate.
		return sprintf( "wp.serviceWorker.precaching.precacheAndRoute( %s );\n", wp_json_encode( $routes_list, 128 | 64 /* JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES */ ) );
	}

	/**
	 * Get the caching strategy script for route.
	 *
	 * @param string $route Route.
	 * @param int    $strategy Caching strategy.
	 * @param array  $strategy_args {
	 *     An array of strategy arguments. If argument keys are supplied in snake_case, they'll be converted to camelCase for JS.
	 *
	 *     @type string $cache_name    Cache name to store and retrieve requests.
	 *     @type array  $plugins       Array of plugins with configuration. The key of each plugin must match the plugins name, with values being strategy options. Optional.
	 *                                 See https://developers.google.com/web/tools/workbox/guides/using-plugins#workbox_plugins.
	 *     @type array  $fetch_options Fetch options. Not supported by cacheOnly strategy. Optional.
	 *     @type array  $match_options Match options. Not supported by networkOnly strategy. Optional.
	 * }
	 * @return string Script.
	 */
	protected function get_caching_for_routes_script( $route, $strategy, $strategy_args ) {
		$script = '{'; // Begin lexical scope.

		// Extract plugins since not JSON-serializable as-is.
		$plugins = array();
		if ( isset( $strategy_args['plugins'] ) ) {
			$plugins = $strategy_args['plugins'];
			unset( $strategy_args['plugins'] );
		}

		$exported_strategy_args = array();
		foreach ( $strategy_args as $strategy_arg_name => $strategy_arg_value ) {
			if ( false !== strpos( $strategy_arg_name, '_' ) ) {
				$strategy_arg_name = preg_replace_callback( '/_[a-z]/', array( $this, 'convert_snake_case_to_camel_case_callback' ), $strategy_arg_name );
			}
			$exported_strategy_args[ $strategy_arg_name ] = $strategy_arg_value;
		}

		$script .= sprintf( 'const strategyArgs = %s;', wp_json_encode( $exported_strategy_args ) );

		if ( is_array( $plugins ) ) {

			$recognized_plugins = array(
				'backgroundSync',
				'broadcastUpdate',
				'cacheableResponse',
				'expiration',
				'rangeRequests',
			);

			$plugins_js = array();
			foreach ( $plugins as $plugin_name => $plugin_args ) {
				if ( false !== strpos( $plugin_name, '_' ) ) {
					$plugin_name = preg_replace_callback( '/_[a-z]/', array( $this, 'convert_snake_case_to_camel_case_callback' ), $plugin_name );
				}

				if ( ! in_array( $plugin_name, $recognized_plugins, true ) ) {
					_doing_it_wrong( 'WP_Service_Workers::register_cached_route', esc_html__( 'Unrecognized plugin', 'pwa' ), '0.2' );
				} else {
					$plugins_js[] = sprintf(
						'new wp.serviceWorker[ %s ].Plugin( %s )',
						wp_json_encode( $plugin_name ),
						empty( $plugin_args ) ? '{}' : wp_json_encode( $plugin_args )
					);
				}
			}

			$script .= sprintf( 'strategyArgs.plugins = [%s];', implode( ', ', $plugins_js ) );
		}

		$script .= sprintf(
			'wp.serviceWorker.routing.registerRoute( new RegExp( %s ), wp.serviceWorker.strategies[ %s ]( strategyArgs ) );',
			wp_json_encode( $route ),
			wp_json_encode( $strategy )
		);

		$script .= '}'; // End lexical scope.

		return $script;
	}

	/**
	 * Convert snake_case to camelCase.
	 *
	 * This is is used by `preg_replace_callback()` for the pattern /_[a-z]/.
	 *
	 * @see WP_Service_Workers::get_caching_for_routes_script()
	 * @param array $matches Matches.
	 * @return string Replaced string.
	 */
	protected function convert_snake_case_to_camel_case_callback( $matches ) {
		return strtoupper( ltrim( $matches[0], '_' ) );
	}

	/**
	 * Get service worker logic for scope.
	 *
	 * @see wp_service_worker_loaded()
	 * @param int $scope Scope of the Service Worker.
	 */
	public function serve_request( $scope ) {

		// @todo Opt to move this outside of serving request so that we can use the existence of the registered scripts for whether to install the service worker to begin with?
		if ( self::SCOPE_FRONT === $scope ) {
			wp_enqueue_scripts();

			/**
			 * Fires before serving the frontend service worker, when its scripts should be registered, caching routes established, and assets precached.
			 *
			 * @since 0.2
			 * @param WP_Service_Workers $this
			 */
			do_action( 'wp_front_service_worker', $this );
		} elseif ( self::SCOPE_ADMIN === $scope ) {
			/** This hook is documented in wp-admin/admin-header.php */
			do_action( 'admin_enqueue_scripts', 'index.php' ); // @todo Is 'index.php' the best here?

			/**
			 * Fires before serving the wp-admin service worker, when its scripts should be registered, caching routes established, and assets precached.
			 *
			 * @since 0.2
			 * @param WP_Service_Workers $this
			 */
			do_action( 'wp_admin_service_worker', $this );
		}

		/**
		 * Fires before serving the service worker (both front and admin), when its scripts should be registered, caching routes established, and assets precached.
		 *
		 * @since 0.2
		 * @param WP_Service_Workers $this
		 */
		do_action( 'wp_service_worker', $this );

		/*
		 * Per Workbox <https://developers.google.com/web/tools/workbox/guides/service-worker-checklist#cache-control_of_your_service_worker_file>:
		 * "Generally, most developers will want to set the Cache-Control header to no-cache,
		 * forcing browsers to always check the server for a new service worker file."
		 * Nevertheless, an ETag header is also sent with support for Conditional Requests
		 * to save on needlessly re-downloading the same service worker with each page load.
		 */
		@header( 'Cache-Control: no-cache' ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged

		@header( 'Content-Type: text/javascript; charset=utf-8' ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged

		if ( self::SCOPE_FRONT !== $scope && self::SCOPE_ADMIN !== $scope ) {
			status_header( 400 );
			echo '/* invalid_scope_requested */';
			return;
		}

		$scope_items = array();

		// Get handles from the relevant scope only.
		foreach ( $this->registered as $handle => $item ) {
			if ( $item->args['scope'] & $scope ) { // Yes, Bitwise AND intended. SCOPE_ALL & SCOPE_FRONT == true. SCOPE_ADMIN & SCOPE_FRONT == false.
				$scope_items[] = $handle;
			}
		}

		$this->output = '';
		$this->do_items( $scope_items );
		$this->do_precaching_routes();
		$this->do_caching_routes();

		$file_hash = md5( $this->output );
		@header( "ETag: $file_hash" ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged

		$etag_header = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( $_SERVER['HTTP_IF_NONE_MATCH'] ) : false;
		if ( $file_hash === $etag_header ) {
			status_header( 304 );
			return;
		}

		echo $this->output; // phpcs:ignore WordPress.XSS.EscapeOutput, WordPress.Security.EscapeOutput
	}

	/**
	 * Add logic for precaching to the request output.
	 */
	protected function do_precaching_routes() {
		$this->output .= $this->get_precaching_for_routes_script( $this->registered_precaching_routes ); // Once PHP 5.3 is minimum version, add array_unique() with SORT_REGULAR.
	}

	/**
	 * Add logic for routes caching to the request output.
	 */
	protected function do_caching_routes() {
		foreach ( $this->registered_caching_routes as $caching_route ) {
			$this->output .= $this->get_caching_for_routes_script( $caching_route['route'], $caching_route['strategy'], $caching_route['strategy_args'] );
		}
	}

	/**
	 * Process one registered script.
	 *
	 * @param string $handle Handle.
	 * @param bool   $group Group. Unused.
	 * @return void
	 */
	public function do_item( $handle, $group = false ) {
		$registered = $this->registered[ $handle ];
		$invalid    = false;

		if ( is_callable( $registered->src ) ) {
			$this->output .= sprintf( "\n/* Source %s: */\n", $handle );
			$this->output .= call_user_func( $registered->src ) . "\n";
		} elseif ( is_string( $registered->src ) ) {
			$validated_path = $this->get_validated_file_path( $registered->src );
			if ( is_wp_error( $validated_path ) ) {
				$invalid = true;
			} else {
				/* translators: %s is file URL */
				$this->output .= sprintf( "\n/* Source %s <%s>: */\n", $handle, $registered->src );
				$this->output .= @file_get_contents( $validated_path ) . "\n"; // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_read_file_get_contents
			}
		} else {
			$invalid = true;
		}

		if ( $invalid ) {
			/* translators: %s is script handle */
			$error = sprintf( __( 'Service worker src is invalid for handle "%s".', 'pwa' ), $handle );
			@_doing_it_wrong( 'WP_Service_Workers::register', esc_html( $error ), '0.1' ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- We want the error in the PHP log, but not in the JS output.
			$this->output .= sprintf( "console.warn( %s );\n", wp_json_encode( $error ) );
		}
	}

	/**
	 * Get validated path to file.
	 *
	 * @param string $url Relative path.
	 * @return null|string|WP_Error
	 */
	protected function get_validated_file_path( $url ) {
		$needs_base_url = (
			! is_bool( $url )
			&&
			! preg_match( '|^(https?:)?//|', $url )
			&&
			! ( $this->content_url && 0 === strpos( $url, $this->content_url ) )
		);
		if ( $needs_base_url ) {
			$url = $this->base_url . $url;
		}

		$url_scheme_pattern = '#^\w+:(?=//)#';

		// Strip URL scheme, query, and fragment.
		$url = preg_replace( $url_scheme_pattern, '', preg_replace( ':[\?#].*$:', '', $url ) );

		$includes_url = preg_replace( $url_scheme_pattern, '', includes_url( '/' ) );
		$content_url  = preg_replace( $url_scheme_pattern, '', content_url( '/' ) );
		$admin_url    = preg_replace( $url_scheme_pattern, '', get_admin_url( null, '/' ) );

		$allowed_hosts = array(
			wp_parse_url( $includes_url, PHP_URL_HOST ),
			wp_parse_url( $content_url, PHP_URL_HOST ),
			wp_parse_url( $admin_url, PHP_URL_HOST ),
		);

		$url_host = wp_parse_url( $url, PHP_URL_HOST );

		if ( ! in_array( $url_host, $allowed_hosts, true ) ) {
			/* translators: %s is file URL */
			return new WP_Error( 'external_file_url', sprintf( __( 'URL is located on an external domain: %s.', 'pwa' ), $url_host ) );
		}

		$base_path = null;
		$file_path = null;
		if ( 0 === strpos( $url, $content_url ) ) {
			$base_path = WP_CONTENT_DIR;
			$file_path = substr( $url, strlen( $content_url ) - 1 );
		} elseif ( 0 === strpos( $url, $includes_url ) ) {
			$base_path = ABSPATH . WPINC;
			$file_path = substr( $url, strlen( $includes_url ) - 1 );
		} elseif ( 0 === strpos( $url, $admin_url ) ) {
			$base_path = ABSPATH . 'wp-admin';
			$file_path = substr( $url, strlen( $admin_url ) - 1 );
		}

		if ( ! $file_path || false !== strpos( $file_path, '../' ) || false !== strpos( $file_path, '..\\' ) ) {
			/* translators: %s is file URL */
			return new WP_Error( 'file_path_not_allowed', sprintf( __( 'Disallowed URL filesystem path for %s.', 'pwa' ), $url ) );
		}
		if ( ! file_exists( $base_path . $file_path ) ) {
			/* translators: %s is file URL */
			return new WP_Error( 'file_path_not_found', sprintf( __( 'Unable to locate filesystem path for %s.', 'pwa' ), $url ) );
		}

		return $base_path . $file_path;
	}
}
