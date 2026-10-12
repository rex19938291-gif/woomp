<?php
/**
 * 綠界電子發票明細組裝整合測試
 *
 * 驗證送給綠界的 Items 加總必須等於 SalesAmount，否則綠界回 RtnCode 1200031
 * 「驗證發票金額發現錯誤，與商品合計金額不符」（issue #137）。
 *
 * @package Woomp\Tests\Integration
 */

use WOOMPECPAYINVOICE\APIs\EcpayInvoiceHandler;

/**
 * 綠界電子發票明細組裝測試類別
 *
 * @covers \WOOMPECPAYINVOICE\APIs\EcpayInvoiceHandler::build_invoice_items
 * @group invoice
 * @group ecpay
 */
class EcpayInvoiceItemsTest extends WP_UnitTestCase {

	/**
	 * 設定測試環境
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! class_exists( EcpayInvoiceHandler::class ) ) {
			$this->markTestSkipped( '綠界電子發票模組未載入' );
		}

		update_option( 'woocommerce_calc_taxes', 'no' );
		update_option( 'woocommerce_prices_include_tax', 'no' );
		update_option( 'woocommerce_tax_round_at_subtotal', 'no' );
		update_option( 'woocommerce_price_num_decimals', '0' );
	}

	/**
	 * 啟用 WooCommerce 稅金（5% 營業稅）
	 *
	 * @param bool $prices_include_tax 商品價格是否含稅.
	 */
	private function enable_tax( bool $prices_include_tax = true ): void {
		update_option( 'woocommerce_calc_taxes', 'yes' );
		update_option( 'woocommerce_prices_include_tax', $prices_include_tax ? 'yes' : 'no' );
		update_option( 'woocommerce_tax_display_cart', $prices_include_tax ? 'incl' : 'excl' );

		WC_Tax::_insert_tax_rate(
			[
				'tax_rate_country'  => '',
				'tax_rate_state'    => '',
				'tax_rate'          => '5.0000',
				'tax_rate_name'     => '營業稅',
				'tax_rate_priority' => '1',
				'tax_rate_compound' => '0',
				'tax_rate_shipping' => '1',
				'tax_rate_order'    => '1',
				'tax_rate_class'    => '',
			]
		);
	}

	/**
	 * 建立含單一商品的訂單（尚未計算總額）
	 *
	 * @param string $price 商品價格.
	 * @return WC_Order
	 */
	private function create_order( string $price ): WC_Order {
		$product = new WC_Product_Simple();
		$product->set_name( '主機服務費' );
		$product->set_regular_price( $price );
		$product->save();

		$order = wc_create_order();
		$order->set_billing_country( 'TW' );
		$order->set_billing_email( 'test@example.com' );
		$order->add_product( $product, 1 );
		$order->save();

		return $order;
	}

	/**
	 * 建立固定金額折價券
	 *
	 * @param string $amount 折抵金額.
	 * @return string 折價券代碼
	 */
	private function create_coupon( string $amount ): string {
		$code   = 'woomp-' . wp_generate_password( 6, false );
		$coupon = new WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'fixed_cart' );
		$coupon->set_amount( $amount );
		$coupon->save();

		return $code;
	}

	/**
	 * 新增運費項目
	 *
	 * @param WC_Order $order 訂單.
	 * @param string   $cost  未稅運費.
	 */
	private function add_shipping( WC_Order $order, string $cost ): void {
		$shipping = new WC_Order_Item_Shipping();
		$shipping->set_method_title( '宅配' );
		$shipping->set_method_id( 'flat_rate' );
		$shipping->set_total( $cost );
		$order->add_item( $shipping );
	}

	/**
	 * 新增費用項目
	 *
	 * @param WC_Order $order  訂單.
	 * @param string   $amount 未稅費用.
	 */
	private function add_fee( WC_Order $order, string $amount ): void {
		$fee = new WC_Order_Item_Fee();
		$fee->set_name( '手續費' );
		$fee->set_amount( $amount );
		$fee->set_total( $amount );
		$fee->set_tax_status( 'taxable' );
		$order->add_item( $fee );
	}

	/**
	 * 共通斷言：明細加總（四捨五入）必須等於 SalesAmount 且等於訂單總額
	 *
	 * @param array    $result build_invoice_items() 回傳值.
	 * @param WC_Order $order  訂單.
	 */
	private function assert_items_balance( array $result, WC_Order $order ): void {
		$sum = array_sum( array_column( $result['items'], 'ItemAmount' ) );

		$this->assertEquals( round( (float) $order->get_total() ), round( $result['sales_amount'] ), 'SalesAmount 應等於訂單總額' );
		$this->assertEquals( round( $result['sales_amount'] ), round( $sum ), '明細加總應等於 SalesAmount，否則綠界回 1200031' );

		foreach ( $result['items'] as $item ) {
			$this->assertEqualsWithDelta( $item['ItemAmount'], $item['ItemPrice'] * $item['ItemCount'], 0.01, '單價 × 數量應等於小計' );
		}
	}

	/**
	 * 取得指定名稱的明細行
	 *
	 * @param array  $result build_invoice_items() 回傳值.
	 * @param string $name   明細名稱.
	 * @return array|null
	 */
	private function find_item( array $result, string $name ): ?array {
		foreach ( $result['items'] as $item ) {
			if ( $name === $item['ItemName'] ) {
				return $item;
			}
		}
		return null;
	}

	/**
	 * 以 issue #137 的案例重現：含稅價 6300、含稅折扣 300
	 *
	 * @testdox 開啟稅金且套用折價券時，折扣行應包含折抵的營業稅（issue #137）
	 */
	public function test_tax_inclusive_coupon_discount_includes_tax() {
		$this->enable_tax( true );
		$order = $this->create_order( '6300' );
		$order->apply_coupon( $this->create_coupon( '300' ) );
		$order->calculate_totals();

		$this->assertEquals( 6000, round( (float) $order->get_total() ), '前置條件：訂單總額應為 6000' );

		$result = ( new EcpayInvoiceHandler() )->build_invoice_items( $order );

		$this->assertEquals( 6300, $this->find_item( $result, '主機服務費' )['ItemAmount'] );
		$this->assertEquals( -300, round( $this->find_item( $result, '折扣' )['ItemAmount'] ), '折扣應為含稅的 -300，不是未稅的 -286' );
		$this->assert_items_balance( $result, $order );
	}

	/**
	 * 未稅價商店套用折價券
	 *
	 * @testdox 開啟稅金（價格未稅）且套用折價券時，明細加總仍等於訂單總額
	 */
	public function test_tax_exclusive_coupon_balances() {
		$this->enable_tax( false );
		$order = $this->create_order( '1000' );
		$order->apply_coupon( $this->create_coupon( '100' ) );
		$order->calculate_totals();

		$result = ( new EcpayInvoiceHandler() )->build_invoice_items( $order );

		$this->assertNotNull( $this->find_item( $result, '折扣' ), '應有折扣行' );
		$this->assert_items_balance( $result, $order );
	}

	/**
	 * 回歸：未開啟稅金（多數台灣商店）套用折價券
	 *
	 * @testdox 未開啟稅金時套用折價券，折扣行等於折價券金額
	 */
	public function test_no_tax_coupon_regression() {
		$order = $this->create_order( '1000' );
		$order->apply_coupon( $this->create_coupon( '150' ) );
		$order->calculate_totals();

		$result = ( new EcpayInvoiceHandler() )->build_invoice_items( $order );

		$this->assertEquals( -150, $this->find_item( $result, '折扣' )['ItemAmount'] );
		$this->assert_items_balance( $result, $order );
	}

	/**
	 * 運費有課稅、沒有折扣時，不應出現假的折扣行
	 *
	 * @testdox 運費課稅且無折扣時，不應產生多餘的折扣行
	 */
	public function test_taxed_shipping_without_discount_has_no_discount_line() {
		$this->enable_tax( true );
		$order = $this->create_order( '1050' );
		$this->add_shipping( $order, '100' );
		$order->calculate_totals();

		$result = ( new EcpayInvoiceHandler() )->build_invoice_items( $order );

		$this->assertNull( $this->find_item( $result, '折扣' ), '沒有折扣就不該有折扣行' );
		$this->assertEquals( 105, round( $this->find_item( $result, '運費' )['ItemAmount'] ), '運費行應含稅' );
		$this->assert_items_balance( $result, $order );
	}

	/**
	 * 費用有課稅時，費用行應含稅
	 *
	 * @testdox 費用課稅時，費用行應包含費用稅額
	 */
	public function test_taxed_fee_includes_tax() {
		$this->enable_tax( true );
		$order = $this->create_order( '1050' );
		$this->add_fee( $order, '100' );
		$order->calculate_totals();

		$result = ( new EcpayInvoiceHandler() )->build_invoice_items( $order );

		$this->assertEquals( 105, round( $this->find_item( $result, '費用' )['ItemAmount'] ), '費用行應含稅' );
		$this->assertNull( $this->find_item( $result, '折扣' ), '沒有折扣就不該有折扣行' );
		$this->assert_items_balance( $result, $order );
	}

	/**
	 * 回歸：後台手動改單價（只改 total、不改 subtotal）
	 *
	 * @testdox 後台手動調降商品金額時，以折扣行補足差額
	 */
	public function test_manual_price_edit_regression() {
		$order = $this->create_order( '1000' );
		foreach ( $order->get_items() as $item ) {
			$item->set_total( '800' );
			$item->save();
		}
		$order->calculate_totals( false );

		$result = ( new EcpayInvoiceHandler() )->build_invoice_items( $order );

		$this->assertEquals( 1000, $this->find_item( $result, '主機服務費' )['ItemAmount'] );
		$this->assertEquals( -200, $this->find_item( $result, '折扣' )['ItemAmount'] );
		$this->assert_items_balance( $result, $order );
	}
}
