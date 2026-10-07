<?php
/**
 * Module contract.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * A feature module. register() only adds WordPress hooks.
 */
interface Module {

	/**
	 * Adds the module's hooks.
	 */
	public function register();
}
