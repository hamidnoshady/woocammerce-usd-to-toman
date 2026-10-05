<?php
use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase {
	public function testPluginHeaderExists(): void {
		$this->assertFileExists(dirname(__DIR__) . '/woocammerce-usd-to-toman.php');
	}
}
