<?php
/** Standalone synthetic tests; no WordPress bootstrap or payment requests. */
declare(strict_types=1);
namespace Payuni\APIs {
	class Payment {
		public static int $decrypts = 0;
		public static int $hashes = 0;
		public static function hash_info(string $encrypted): string { ++self::$hashes; return 'synthetic-signature'; }
		public static function decrypt(string $encrypted): array { ++self::$decrypts; return []; }
		public static function log(...$args): void {}
	}
}
namespace {
	define('ABSPATH', __DIR__);
	function add_action(...$args): void {}
	class FinishIntercept extends \RuntimeException {}
	function do_action($hook, ...$args): void {
		if ($hook === 'woomp_payuni_v2_card_response_finish') { throw new FinishIntercept(); }
		throw new \RuntimeException('Unexpected action');
	}
	function wc_get_order(...$args): void { throw new \RuntimeException('Unexpected order access'); }
	$root = getenv('WOOMP_TEST_ROOT') ?: dirname(__DIR__, 2);
	require $root . '/includes/payuni/src/gateways/Response.php';
	$count = 0;
	foreach (['return', 'notify'] as $context) {
		foreach ([null, '', 'wrong-signature', ['synthetic-signature'], 'synthetic-signature'] as $signature) {
			$_REQUEST = ['EncryptInfo' => 'synthetic-encrypted'];
			if ($signature !== null) { $_REQUEST['HashInfo'] = $signature; }
			\Payuni\APIs\Payment::$decrypts = 0;
			try { ($context === 'notify') ? \PAYUNI\Gateways\Response::card_notify() : \PAYUNI\Gateways\Response::card_response(); }
			catch (FinishIntercept $e) {}
			$expected = $signature === 'synthetic-signature' ? 1 : 0;
			if (\Payuni\APIs\Payment::$decrypts !== $expected) { throw new \RuntimeException("$context signature requirement"); }
			++$count;
		}
	}
	$_REQUEST = [];
	\Payuni\APIs\Payment::$decrypts = 0;
	\Payuni\APIs\Payment::$hashes = 0;
	try { \PAYUNI\Gateways\Response::card_response((object) ['EncryptInfo' => 'synthetic-encrypted'], 'sync'); }
	catch (FinishIntercept $e) {}
	if (\Payuni\APIs\Payment::$decrypts !== 1 || \Payuni\APIs\Payment::$hashes !== 0) { throw new \RuntimeException('Sync compatibility'); }
	++$count;
	foreach (['return', 'notify'] as $context) {
		$_REQUEST = ['HashInfo' => 'synthetic-signature'];
		\Payuni\APIs\Payment::$decrypts = 0;
		try { ($context === 'notify') ? \PAYUNI\Gateways\Response::card_notify() : \PAYUNI\Gateways\Response::card_response(); }
		catch (FinishIntercept $e) {}
		if (\Payuni\APIs\Payment::$decrypts !== 0) { throw new \RuntimeException('Missing encrypted data'); }
		++$count;
	}
	echo "PASS $count checks\n";
}
