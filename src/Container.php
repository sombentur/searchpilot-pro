<?php
/**
 * Minimal auto-wiring service container.
 *
 * @package SearchPilot
 */

namespace SearchPilot;

defined( 'ABSPATH' ) || exit;

/**
 * Lazily builds shared service instances. Constructor parameters that are
 * class-typed are resolved from the container; scalar parameters must have
 * defaults. Services can be overridden (for tests) with set().
 */
final class Container {

	/**
	 * Built instances keyed by id.
	 *
	 * @var array<string,object>
	 */
	private $instances = array();

	/**
	 * Explicit factories keyed by id.
	 *
	 * @var array<string,callable>
	 */
	private $factories = array();

	/**
	 * Ids currently being resolved (circular dependency guard).
	 *
	 * @var array<string,bool>
	 */
	private $resolving = array();

	/**
	 * Registers a factory or an instance.
	 *
	 * @param string          $id      Service id (normally a class name).
	 * @param callable|object $factory Factory receiving the container, or a ready instance.
	 */
	public function set( $id, $factory ) {
		$id = ltrim( $id, '\\' );
		unset( $this->instances[ $id ] );
		if ( is_object( $factory ) && ! ( $factory instanceof \Closure ) ) {
			$this->instances[ $id ] = $factory;
			return;
		}
		$this->factories[ $id ] = $factory;
	}

	/**
	 * Whether a service can be resolved.
	 *
	 * @param string $id Service id.
	 * @return bool
	 */
	public function has( $id ) {
		$id = ltrim( $id, '\\' );
		return isset( $this->instances[ $id ] ) || isset( $this->factories[ $id ] ) || class_exists( $id );
	}

	/**
	 * Returns the shared instance of a service.
	 *
	 * @param string $id Service id.
	 * @return object
	 * @throws \RuntimeException When the service cannot be built.
	 */
	public function get( $id ) {
		$id = ltrim( $id, '\\' );
		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}
		if ( isset( $this->resolving[ $id ] ) ) {
			throw new \RuntimeException( 'Circular dependency while resolving ' . $id );
		}
		$this->resolving[ $id ] = true;
		try {
			if ( isset( $this->factories[ $id ] ) ) {
				$instance = call_user_func( $this->factories[ $id ], $this );
			} else {
				$instance = $this->build( $id );
			}
		} finally {
			unset( $this->resolving[ $id ] );
		}
		$this->instances[ $id ] = $instance;
		return $instance;
	}

	/**
	 * Builds a class by reflecting its constructor.
	 *
	 * @param string $class Class name.
	 * @return object
	 * @throws \RuntimeException When a dependency cannot be resolved.
	 */
	private function build( $class ) {
		if ( ! class_exists( $class ) ) {
			throw new \RuntimeException( 'Unknown service ' . $class );
		}
		$reflection = new \ReflectionClass( $class );
		if ( ! $reflection->isInstantiable() ) {
			throw new \RuntimeException( 'Service is not instantiable: ' . $class );
		}
		$constructor = $reflection->getConstructor();
		if ( null === $constructor ) {
			return new $class();
		}
		$args = array();
		foreach ( $constructor->getParameters() as $param ) {
			$type = $param->getType();
			if ( $type instanceof \ReflectionNamedType && ! $type->isBuiltin() ) {
				$dep = $type->getName();
				if ( self::class === $dep ) {
					$args[] = $this;
					continue;
				}
				if ( $param->isDefaultValueAvailable() && ! $this->has( $dep ) ) {
					$args[] = $param->getDefaultValue();
					continue;
				}
				$args[] = $this->get( $dep );
				continue;
			}
			if ( $param->isDefaultValueAvailable() ) {
				$args[] = $param->getDefaultValue();
				continue;
			}
			throw new \RuntimeException( sprintf( 'Cannot resolve parameter $%s of %s', $param->getName(), $class ) );
		}
		return $reflection->newInstanceArgs( $args );
	}
}
