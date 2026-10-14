<?php
/**
 * PHP 8.2 動態屬性回歸測試
 *
 * PHP 8.2 起，對未宣告的屬性賦值會觸發
 * `Creation of dynamic property X::$y is deprecated`（PHP 9 將變成 Error）。
 * 站台開 Query Monitor 時，這些警告會被塞進回應標頭，超過 nginx
 * fastcgi_buffer_size 就變成 503（issue #140）。
 *
 * 本測試實例化好用版註冊的所有金流閘道與物流方式（含帶 instance_id 觸發 init()），
 * 並攔截 E_DEPRECATED，只要出現動態屬性建立就 fail 並列出。
 *
 * @package Woomp\Tests\Integration
 */

/**
 * 動態屬性回歸測試類別
 *
 * @group php82
 */
class DynamicPropertiesTest extends WP_UnitTestCase {

	/**
	 * 攔截到的動態屬性 deprecation 訊息
	 *
	 * @var string[]
	 */
	private $dynamic_property_notices = [];

	/**
	 * 是否已安裝錯誤攔截器
	 *
	 * @var bool
	 */
	private $handler_installed = false;

	/**
	 * 設定測試環境
	 *
	 * 打開各子外掛的啟用選項，並補上 PayUni 測試憑證（避免 SettingDTO strict type 錯誤）。
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( 'wc_woomp_enabled_payuni_gateway', 'yes' );
		update_option( 'wc_woomp_setting_paynow_gateway', 'yes' );
		update_option( 'wc_woomp_setting_paynow_shipping', 'yes' );
		update_option( 'ry_wt_enabled_ecpay_gateway', 'yes' );
		update_option( 'ry_wt_enabled_ecpay_shipping', 'yes' );
		update_option( 'ry_wt_enabled_newebpay_gateway', 'yes' );
		update_option( 'ry_wt_enabled_newebpay_shipping', 'yes' );
		update_option( 'ry_wt_enabled_smilepay_gateway', 'yes' );
		update_option( 'ry_wt_enabled_smilepay_shipping', 'yes' );
		update_option( 'woocommerce_linepay_enabled', 'yes' );
		update_option( 'woocommerce_pchomepay_enabled', 'yes' );

		update_option( 'payuni_payment_testmode', 'yes' );
		update_option( 'payuni_payment_merchant_no_test', 'TEST_MERCHANT' );
		update_option( 'payuni_payment_hash_key_test', 'TEST_HASH_KEY_1234567890123456' );
		update_option( 'payuni_payment_hash_iv_test', 'TEST_HASH_IV_123456' );

		// 重置 SettingDTO 單例，讓上面的測試憑證生效。
		if ( class_exists( \J7\Payuni\Contracts\DTOs\SettingDTO::class ) ) {
			$ref  = new \ReflectionClass( \J7\Payuni\Contracts\DTOs\SettingDTO::class );
			$prop = $ref->getProperty( 'instance' );
			$prop->setAccessible( true );
			$prop->setValue( null, null );
		}

		$this->dynamic_property_notices = [];
	}

	/**
	 * 清理測試環境
	 */
	public function tearDown(): void {
		$this->stop_capture();
		parent::tearDown();
	}

	/**
	 * 開始攔截動態屬性 deprecation
	 *
	 * 只吃掉「Creation of dynamic property」，其他錯誤交回上一層（PHPUnit）處理。
	 */
	private function start_capture(): void {
		$notices  = &$this->dynamic_property_notices;
		$previous = null;
		$previous = set_error_handler(
			static function ( $errno, $errstr, $errfile = '', $errline = 0 ) use ( &$notices, &$previous ) {
				if ( E_DEPRECATED === $errno && false !== strpos( $errstr, 'Creation of dynamic property' ) ) {
					$notices[] = sprintf( '%s（%s:%d）', $errstr, str_replace( WOOMP_PLUGIN_DIR_TEST, '', $errfile ), $errline );
					return true;
				}
				return $previous ? (bool) call_user_func( $previous, $errno, $errstr, $errfile, $errline ) : false;
			}
		);
		$this->handler_installed = true;
	}

	/**
	 * 停止攔截
	 */
	private function stop_capture(): void {
		if ( $this->handler_installed ) {
			restore_error_handler();
			$this->handler_installed = false;
		}
	}

	/**
	 * 判斷類別是否定義在好用版目錄內（排除 vendor）
	 *
	 * @param string|object $class_or_object 類別名稱或物件。
	 * @return bool
	 */
	private function is_woomp_class( $class_or_object ): bool {
		$class = is_object( $class_or_object ) ? get_class( $class_or_object ) : (string) $class_or_object;
		if ( ! class_exists( $class ) ) {
			return false;
		}
		$file = ( new \ReflectionClass( $class ) )->getFileName();
		if ( ! $file ) {
			return false;
		}
		$file = wp_normalize_path( $file );
		$root = wp_normalize_path( WOOMP_PLUGIN_DIR_TEST );

		return 0 === strpos( $file, $root ) && false === strpos( $file, '/vendor/' );
	}

	/**
	 * 收集要測的類別
	 *
	 * 包含透過 filter 註冊的類別，以及已宣告但未經 filter 註冊的好用版子類別
	 * （例如綠界／藍新分期 N 期閘道），兩者聯集後排除抽象類別與非好用版類別。
	 *
	 * @param array  $registered  filter 回傳的類別名稱或物件。
	 * @param string $base_class  基底類別（WC_Payment_Gateway／WC_Shipping_Method）。
	 * @return string[]
	 */
	private function collect_classes( array $registered, string $base_class ): array {
		$classes = [];
		foreach ( $registered as $item ) {
			$classes[] = is_object( $item ) ? get_class( $item ) : (string) $item;
		}
		foreach ( get_declared_classes() as $class ) {
			if ( is_subclass_of( $class, $base_class ) ) {
				$classes[] = $class;
			}
		}

		$classes = array_filter(
			array_unique( $classes ),
			function ( $class ) {
				return $this->is_woomp_class( $class ) && ( new \ReflectionClass( $class ) )->isInstantiable();
			}
		);
		sort( $classes );

		return $classes;
	}

	/**
	 * 斷言沒有攔截到動態屬性建立
	 *
	 * @param string $context 情境說明。
	 */
	private function assert_no_dynamic_properties( string $context ): void {
		$notices = array_values( array_unique( $this->dynamic_property_notices ) );
		$this->assertSame(
			[],
			$notices,
			sprintf( "%s 出現 %d 筆動態屬性 deprecation：\n%s", $context, count( $notices ), implode( "\n", $notices ) )
		);
	}

	/**
	 * 載入預設情境下不會載入的金流閘道類別
	 *
	 * - LINE Pay：需 woocommerce_linepay_enabled，且於 plugins_loaded 才 include。
	 * - 好用版貨到付款：檔案以 active_plugins 判斷 WooCommerce 目錄名稱，測試環境不符會提早 return，
	 *   但頂層函式仍會被宣告，故直接呼叫。
	 * - PChomePay 支付連：需 woocommerce_pchomepay_enabled，且於 plugins_loaded 才 require。
	 *
	 * @return string[] 額外要測的閘道類別名稱。
	 */
	private function load_optional_gateway_classes(): array {
		$classes = [];

		if ( class_exists( 'WC_Gateway_LINEPay_Handler' ) ) {
			$handler = new WC_Gateway_LINEPay_Handler();
			$handler->init_wc_gateway_linepay_handler();
			$classes[] = 'WC_Gateway_LINEPay';
		}

		if ( ! class_exists( 'WooMP_Payment_Cod' ) && function_exists( 'init_woomp_gateway_cod' ) ) {
			init_woomp_gateway_cod();
		}
		if ( class_exists( 'WooMP_Payment_Cod' ) ) {
			$classes[] = 'WooMP_Payment_Cod';
		}

		$pchomepay_dir = WOOMP_PLUGIN_DIR_TEST . 'includes/PChomePay-Cart-for-WooCommerce/includes/';
		require_once $pchomepay_dir . 'PChomePayClient.php';
		require_once $pchomepay_dir . 'PChomePayGateway.php';
		require_once $pchomepay_dir . 'PChomePayPIGateway.php';
		$classes[] = 'WC_Gateway_PChomePay';
		$classes[] = 'WC_PI_Gateway_PChomePay';

		return $classes;
	}

	/**
	 * 實例化好用版所有金流閘道不得建立動態屬性
	 *
	 * @testdox 實例化好用版所有金流閘道時不產生 dynamic property deprecation
	 */
	public function test_payment_gateways_do_not_create_dynamic_properties(): void {
		$this->start_capture();

		$classes = $this->collect_classes(
			array_merge( $this->load_optional_gateway_classes(), (array) apply_filters( 'woocommerce_payment_gateways', [] ) ),
			'WC_Payment_Gateway'
		);

		$this->assertGreaterThan( 40, count( $classes ), '好用版註冊的金流閘道數量異常，測試範圍可能失效：' . implode( ', ', $classes ) );

		foreach ( $classes as $class ) {
			new $class();
		}

		$this->stop_capture();
		$this->assert_no_dynamic_properties( '金流閘道（' . count( $classes ) . ' 個類別）' );
	}

	/**
	 * 實例化好用版所有物流方式（含 instance 觸發 init()、運費試算）不得建立動態屬性
	 *
	 * @testdox 實例化好用版所有物流方式（含 instance_id 與運費試算）時不產生 dynamic property deprecation
	 */
	public function test_shipping_methods_do_not_create_dynamic_properties(): void {
		$this->start_capture();

		$classes = $this->collect_classes( (array) apply_filters( 'woocommerce_shipping_methods', [] ), 'WC_Shipping_Method' );

		$this->assertGreaterThan( 15, count( $classes ), '好用版註冊的物流方式數量異常，測試範圍可能失效：' . implode( ', ', $classes ) );

		foreach ( $classes as $class ) {
			// 不帶 instance_id（全域設定頁）與帶 instance_id（運送區域內，會呼叫 init()）兩種情境。
			new $class();
			$method = new $class( 1 );

			// evaluate_cost() 會寫入 fee_cost，一併覆蓋。
			// 註：運算式刻意不含 [fee]，RY 系列物流沒有實作 fee() 回呼，帶 [fee] 會觸發 _doing_it_wrong（與本測試無關）。
			if ( method_exists( $method, 'evaluate_cost' ) ) {
				$ref = new \ReflectionMethod( $method, 'evaluate_cost' );
				if ( $this->is_woomp_class( $ref->getDeclaringClass()->getName() ) ) {
					$ref->setAccessible( true );
					$ref->invoke(
						$method,
						'10 * [qty]',
						[
							'qty'  => 1,
							'cost' => 100,
						]
					);
				}
			}
		}

		$this->stop_capture();
		$this->assert_no_dynamic_properties( '物流方式（' . count( $classes ) . ' 個類別）' );
	}

	/**
	 * LINE Pay handler 初始化不得建立動態屬性
	 *
	 * @testdox LINE Pay handler 初始化時不產生 dynamic property deprecation
	 */
	public function test_linepay_handler_does_not_create_dynamic_properties(): void {
		$this->start_capture();

		$handler = new WC_Gateway_LINEPay_Handler();
		$handler->init_wc_gateway_linepay_handler();

		$this->stop_capture();
		$this->assert_no_dynamic_properties( 'WC_Gateway_LINEPay_Handler' );
	}
}
