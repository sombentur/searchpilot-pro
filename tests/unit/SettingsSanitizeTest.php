<?php
/**
 * Tests for Core\Settings sanitizers.
 *
 * @package SearchPilot
 */

namespace SearchPilot\Tests;

use SearchPilot\Core\Settings;

// phpcs:disable

class SettingsSanitizeTest extends \PHPUnit\Framework\TestCase {

	public function test_raw_text_keeps_percent_encoded_paths() {
		$rules = "User-agent: *\r\nDisallow: /%E0%B2%95%E0%B2%A8%E0%B3%8D%E0%B2%A8%E0%B2%A1\nDisallow: /%e0%b2%95/?s=\n";
		$clean = Settings::sanitize_raw_text( $rules );
		$this->assertSame( "User-agent: *\nDisallow: /%E0%B2%95%E0%B2%A8%E0%B3%8D%E0%B2%A8%E0%B2%A1\nDisallow: /%e0%b2%95/?s=", $clean );
	}

	public function test_raw_text_strips_tags_and_control_characters() {
		$this->assertSame( "Disallow: /private/\nAllow: /", Settings::sanitize_raw_text( "Disallow: /private/<script>x</script>\x07\nAllow: /" ) );
	}

	public function test_schema_uses_raw_text_for_rules_and_patterns() {
		$schema = Settings::schema();
		$this->assertSame( 'raw_text', $schema['robots.custom_rules']['type'] );
		$this->assertSame( 'raw_text', $schema['redirects.ignore_patterns']['type'] );
		$this->assertSame( 'template', $schema['meta.title_post']['type'] );
	}
}
