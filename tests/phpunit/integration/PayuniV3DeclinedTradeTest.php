<?php
/**
 * PayUni V3 信用卡交易遭拒（issue #141）整合測試
 *
 * 驗證 CreditV3::process_payment() 依 merchant_trade 回應分流：
 * - Status 非 SUCCESS 且無 TradeNo（如 IFTRADE02011）→ failure，不可寫「3D 驗證已建立」
 * - Status 非 SUCCESS 但有 TradeNo（授權失敗）→ failure，不可導向訂單完成頁
 * - Status=SUCCESS 帶 URL（3D 建立成功）→ 導向 3D 頁（回歸）
 * - Status=SUCCESS 帶 TradeNo（直接授權成功）→ 訂單完成（回歸）
 *
 * @package Woomp\Tests\Integration
 */

use J7\Payuni\Domains\Reconciliation\PendingReconciler;
use J7\Payuni\Shared\Utils\EncryptUtils;

/**
 * 交易遭拒測試類別
 *
 * @covers \PAYUNI\Gateways\CreditV3::process_payment
 * @group payuni
 */
final class PayuniV3DeclinedTradeTest extends WP_UnitTestCase {

	/**
	 * 供 pre_http_request 回傳的 merchant_trade HTTP body。
	 *
	 * @var string|null
	 */
	private ?string $http_mock_body = null;

	/**
	 * 受測閘道
	 *
	 * @var \PAYUNI\Gateways\CreditV3|null
	 */
	private ?\PAYUNI\Gateways\CreditV3 $gateway = null;

	/**
	 * 設定測試環境
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( 'payuni_payment_testmode', 'yes' );
		update_option( 'payuni_payment_merchant_no_test', 'TEST_MERCHANT' );
		update_option( 'payuni_payment_hash_key_test', 'TEST_HASH_KEY_1234567890123456' );
		update_option( 'payuni_payment_hash_iv_test', 'TEST_HASH_IV_123456' );

		$this->reset_setting_dto();

		// wc_add_notice() 需要 session
		if ( null === WC()->session ) {
			WC()->session = new \WC_Session_Handler();
			WC()->session->init();
		}
		wc_clear_notices();

		$previous      = error_reporting( E_ALL & ~E_DEPRECATED );
		$this->gateway = new \PAYUNI\Gateways\CreditV3();
		error_reporting( $previous );

		add_filter( 'pre_http_request', [ $this, 'filter_http' ], 10, 3 );
	}

	/**
	 * 清理測試環境
	 */
	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'filter_http' ], 10 );
		$this->http_mock_body = null;
		$this->gateway        = null;
		wc_clear_notices();

		$this->reset_setting_dto();

		parent::tearDown();
	}

	/**
	 * @testdox 金流以錯誤碼拒絕（無 TradeNo、無 URL）時回傳 failure，備註寫交易遭拒而非 3D 已建立
	 */
	public function test_declined_without_trade_no_returns_failure(): void {
		$order = $this->create_pending_v3_order();
		$this->mock_merchant_trade(
			[
				'Status'  => 'IFTRADE02011',
				'Message' => '訂單金額，格式錯誤',
			]
		);

		$result = $this->process( $order );

		$this->assertSame( 'failure', $result['result'], '遭拒交易不可回傳 success' );
		$this->assertArrayNotHasKey( 'redirect', $result, '遭拒交易不可導向訂單完成頁' );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'pending', $order->get_status(), '訂單應維持等待付款讓顧客重試' );

		$notes = $this->get_note_contents( $order );
		$this->assertStringContainsString( '統一金流交易遭拒', $notes );
		$this->assertStringContainsString( 'IFTRADE02011', $notes );
		$this->assertStringNotContainsString( '3D 驗證已建立', $notes, '不可誤標為 3D 驗證已建立' );

		$this->assertFalse( $this->is_reconcile_scheduled( $order->get_id() ), '交易不存在，不應排程補償查詢' );

		$errors = wc_get_notices( 'error' );
		$this->assertNotEmpty( $errors, '應顯示付款失敗訊息給顧客' );
		$this->assertStringContainsString( '訂單金額，格式錯誤', $errors[0]['notice'] );
	}

	/**
	 * @testdox 授權失敗（有 TradeNo、Status 非 SUCCESS）時回傳 failure，訂單不可標為已付款
	 */
	public function test_declined_with_trade_no_returns_failure(): void {
		$order = $this->create_pending_v3_order();
		$this->mock_merchant_trade(
			[
				'Status'  => 'CREDIT04001',
				'Message' => '授權失敗',
				'TradeNo' => 'DECLINED_TRADE_1',
			]
		);

		$result = $this->process( $order );

		$this->assertSame( 'failure', $result['result'] );

		$order = wc_get_order( $order->get_id() );
		$this->assertFalse( $order->is_paid(), '授權失敗的訂單不可標為已付款' );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertStringContainsString( '信用卡付款失敗', $this->get_note_contents( $order ) );
		$this->assertNotEmpty( wc_get_notices( 'error' ) );
	}

	/**
	 * @testdox 3D 建立成功（Status=SUCCESS 帶 URL）仍導向 3D 驗證頁並排程補償查詢
	 */
	public function test_3d_created_still_redirects_to_otp_page(): void {
		$order = $this->create_pending_v3_order();
		$this->mock_merchant_trade(
			[
				'Status'  => 'SUCCESS',
				'Message' => '建立幕後3D成功',
				'URL'     => 'https://sandbox-api.payuni.com.tw/3d/otp',
			]
		);

		$result = $this->process( $order );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 'https://sandbox-api.payuni.com.tw/3d/otp', $result['redirect'] );

		$order = wc_get_order( $order->get_id() );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertStringContainsString( '3D 驗證已建立', $this->get_note_contents( $order ) );
		$this->assertTrue( $this->is_reconcile_scheduled( $order->get_id() ) );
		$this->assertEmpty( wc_get_notices( 'error' ) );
	}

	/**
	 * @testdox 直接授權成功（Status=SUCCESS 帶 TradeNo）訂單完成並導向訂單完成頁
	 */
	public function test_direct_authorization_completes_order(): void {
		$order = $this->create_pending_v3_order();
		$this->mock_merchant_trade(
			[
				'Status'  => 'SUCCESS',
				'Message' => '授權成功',
				'TradeNo' => 'PAID_TRADE_1',
				'Card4No' => '4242',
			]
		);

		$result = $this->process( $order );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( $order->get_checkout_order_received_url(), $result['redirect'] );

		$order = wc_get_order( $order->get_id() );
		$this->assertTrue( $order->is_paid() );
		$this->assertSame( 'PAID_TRADE_1', $order->get_transaction_id() );
	}

	/**
	 * 執行 process_payment（遮蔽既有的動態屬性 deprecation）。
	 *
	 * @param \WC_Order $order 訂單物件
	 *
	 * @return array
	 */
	private function process( \WC_Order $order ): array {
		$previous = error_reporting( E_ALL & ~E_DEPRECATED );
		$result   = $this->gateway->process_payment( $order->get_id() );
		error_reporting( $previous );

		return $result;
	}

	/**
	 * 建立 V3 待付款訂單。
	 *
	 * @return \WC_Order
	 */
	private function create_pending_v3_order(): \WC_Order {
		$order = wc_create_order();
		$order->set_payment_method( \PAYUNI\Gateways\CreditV3::ID );
		$order->set_billing_email( 'buyer@example.com' );
		$order->set_total( 300000 );
		$order->set_status( 'pending' );
		$order->update_meta_data( 'sdk_token_tmp', 'test_sdk_token' );
		$order->save();

		return $order;
	}

	/**
	 * 設定 merchant_trade 回應（解密後內容）。
	 *
	 * @param array $decrypted_payload 解密後應得到的交易結果
	 *
	 * @return void
	 */
	private function mock_merchant_trade( array $decrypted_payload ): void {
		$encrypt_info = EncryptUtils::encrypt( $decrypted_payload );

		$this->http_mock_body = wp_json_encode(
			[
				'Status'      => $decrypted_payload['Status'],
				'MerID'       => 'TEST_MERCHANT',
				'Version'     => '1.0',
				'EncryptInfo' => $encrypt_info,
				'HashInfo'    => EncryptUtils::hash_info( $encrypt_info ),
			]
		);
	}

	/**
	 * pre_http_request 回呼：僅攔截 merchant_trade。
	 *
	 * @param mixed  $pre  短路值
	 * @param array  $args 請求參數
	 * @param string $url  請求網址
	 *
	 * @return mixed
	 */
	public function filter_http( $pre, $args, $url ) {
		if ( false === strpos( (string) $url, 'merchant_trade' ) || null === $this->http_mock_body ) {
			return $pre;
		}

		return [
			'headers'  => [],
			'body'     => $this->http_mock_body,
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'cookies'  => [],
			'filename' => null,
		];
	}

	/**
	 * 取得訂單所有備註內容（串接成單一字串）。
	 *
	 * @param \WC_Order $order 訂單物件
	 *
	 * @return string
	 */
	private function get_note_contents( \WC_Order $order ): string {
		$notes = wc_get_order_notes( [ 'order_id' => $order->get_id() ] );

		return implode( "\n", wp_list_pluck( $notes, 'content' ) );
	}

	/**
	 * 判斷指定訂單是否已排程補償對帳（相容 Action Scheduler 與 wp-cron）。
	 *
	 * @param int $order_id 訂單 ID
	 *
	 * @return bool
	 */
	private function is_reconcile_scheduled( int $order_id ): bool {
		if ( function_exists( 'as_next_scheduled_action' ) ) {
			if ( false !== as_next_scheduled_action( PendingReconciler::HOOK, [ $order_id ] ) ) {
				return true;
			}
		}

		return false !== wp_next_scheduled( PendingReconciler::HOOK, [ $order_id ] );
	}

	/**
	 * 重置 SettingDTO 單例。
	 *
	 * @return void
	 */
	private function reset_setting_dto(): void {
		$ref  = new \ReflectionClass( \J7\Payuni\Contracts\DTOs\SettingDTO::class );
		$prop = $ref->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}
}
