<?php
/**
 * 預約專用的結帳頁精簡。
 *
 * 前台預約精靈送出後會把客人帶到購物車頁（預設）或結帳頁，見
 * UAPPT_Cart::after_add_destination()。不論走哪一條，結帳頁都是精靈的最後
 * 一步，但 WooCommerce 預設會問公司名稱、地址、縣市、郵遞區號——預約一個
 * 美甲時段根本不需要那些。
 *
 * 「返回修改預約」則兩頁都要掛：客人第一個落地的頁面可能是購物車，那裡
 * 沒有回精靈的路的話，只能把項目刪掉重來。
 *
 * ⚠️ **所有精簡都只在「購物車裡全部都是預約商品」時生效。**結帳頁是跟一般
 * 商品共用的，無條件砍掉地址欄位，實體商品就會拿不到收件地址、直接壞掉。
 * 混合購物車（預約＋保養品）會自動落回完整結帳頁，這是刻意的：那張訂單真的
 * 需要寄東西。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UAPPT_Checkout {

	/**
	 * 記住精靈是從哪一頁送出的，用來產生「返回修改」連結。
	 */
	const RETURN_URL_KEY = 'uappt_wizard_return';

	/**
	 * 掛上 hooks。
	 */
	public static function init() {
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'simplify_fields' ), 20 );
		add_action( 'woocommerce_before_checkout_form', array( __CLASS__, 'render_back_link' ), 5 );
		// 購物車頁也要有返回連結——精靈預設就是把客人送到這一頁。
		add_action( 'woocommerce_before_cart', array( __CLASS__, 'render_back_link' ), 5 );
		// 購物車空了的時候，預設版型只有一顆「回到商店」。
		add_action( 'woocommerce_cart_is_empty', array( __CLASS__, 'render_empty_cart_link' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_style' ) );
	}

	/**
	 * 購物車頁與結帳頁只載入精靈的 CSS，不載它的 JS。
	 *
	 * 樣式共用 wizard.css（只有隱藏欄位與返回連結兩段，不值得另開檔案），
	 * 但這兩頁都沒有精靈要掛載，載 JS 只是多下載一支用不到的檔案。
	 */
	public static function enqueue_style() {
		if ( ! function_exists( 'is_checkout' ) || ! function_exists( 'is_cart' ) ) {
			return;
		}

		// ⚠️ 購物車空掉時 cart_is_booking_only() 一定是 false（空車回 false），
		// 但那正是要印「回到預約」的時候——只看 cart_is_booking_only() 會讓
		// 那顆按鈕沒有樣式。
		// 三種情況要載，對應這支 class 印出來的三樣東西：
		// 1. 結帳欄位精簡（只有購物車全是預約時）
		// 2. 返回修改預約（車上有預約就會出現，混合購物車也算）
		// 3. 空購物車的「回到預約」（車上什麼都沒有，只能靠 session 的網址判斷）
		//
		// 第 3 項要一併確認購物車是空的：純零售的購物車也可能帶著 session 裡
		// 的網址（客人這一輪稍早用過精靈），那時候不會印任何東西，載了是白載。
		$cart_empty = ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty();

		if ( is_cart() || is_checkout() ) {
			$needed = self::cart_is_booking_only()
				|| (bool) self::booking_cart_item_keys()
				|| ( is_cart() && $cart_empty && '' !== self::return_url() );
		} else {
			$needed = false;
		}

		if ( ! $needed ) {
			return;
		}

		UAPPT_Wizard::register_assets();
		wp_enqueue_style( UAPPT_Wizard::STYLE_HANDLE );
	}

	/**
	 * 購物車是不是「只有預約」。
	 *
	 * 空購物車回 false：沒有東西可結帳，也就沒有要精簡的對象（而且結帳頁本來
	 * 就會顯示「購物車是空的」）。
	 *
	 * @return bool
	 */
	public static function cart_is_booking_only() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			$product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			if ( ! $product_id || ! UAPPT_Product::booking_enabled( $product_id ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * 只有預約時，把跟預約無關的欄位拿掉。
	 *
	 * 保留姓名／電話／Email：綠界送單需要這三個（見
	 * wc-hotel-booking/includes/class-wchb-manual.php 的實測結論），提醒通知
	 * 也靠 Email 與電話。訂單備註保留——「希望安靜一點的位置」這類需求對
	 * 服務業其實有用。
	 *
	 * 國家欄位刻意**保留成隱藏欄位**而不是拿掉：這個站雖然已經關閉稅金計算
	 * （woocommerce_calc_taxes = no）、綠界電子發票也沒啟用（唯一會讀
	 * billing_country 的地方），但 WooCommerce 內部仍有不少地方假設訂單有國家。
	 * 留一個帶預設值的隱藏欄位，客人看不到、系統拿得到，是風險最低的做法。
	 *
	 * @param array $fields 結帳欄位。
	 * @return array
	 */
	public static function simplify_fields( $fields ) {
		if ( ! self::cart_is_booking_only() ) {
			return $fields;
		}

		$drop = array(
			'billing_company',
			'billing_address_1',
			'billing_address_2',
			'billing_city',
			'billing_state',
			'billing_postcode',
		);

		foreach ( $drop as $key ) {
			unset( $fields['billing'][ $key ] );
		}

		if ( isset( $fields['billing']['billing_country'] ) ) {
			$fields['billing']['billing_country']['type']     = 'hidden';
			$fields['billing']['billing_country']['required'] = false;
			$fields['billing']['billing_country']['label']    = '';
			$fields['billing']['billing_country']['default']  = self::default_country();
			// class 會被套到外層 <p>，清掉欄寬相關的樣式，免得隱藏欄位在版面上
			// 佔一個空格子。
			$fields['billing']['billing_country']['class']    = array( 'uappt-checkout-hidden' );
		}

		// 虛擬商品本來就不會顯示運送欄位，但購物車若曾經有實體商品、又被移除，
		// 這一區有時候會殘留。只有預約時一律清掉最保險。
		$fields['shipping'] = array();

		return $fields;
	}

	/**
	 * 結帳用的預設國家（只取國碼，不要 WooCommerce 的「國碼:州碼」格式）。
	 *
	 * @return string
	 */
	protected static function default_country() {
		$base = (string) get_option( 'woocommerce_default_country', 'TW' );
		$parts = explode( ':', $base );
		return $parts[0] ? $parts[0] : 'TW';
	}

	/**
	 * 記住精靈是從哪一頁送出的。
	 *
	 * 由 UAPPT_Cart 在確認「這次是精靈送出」時呼叫——那個時間點的網址就是
	 * 精靈所在的頁面（精靈的表單是 POST 回自己那一頁）。
	 *
	 * @param string $url 精靈頁面網址。
	 */
	public static function remember_return_url( $url ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		WC()->session->set( self::RETURN_URL_KEY, esc_url_raw( $url ) );
	}

	/**
	 * 購物車頁／結帳頁上方的「返回修改預約」連結。
	 *
	 * 同一支掛在兩個頁面上：`woocommerce_before_cart` 與
	 * `woocommerce_before_checkout_form`。兩邊要做的事一模一樣（把客人送回
	 * 精靈那一頁），沒有任何一頁特有的差異，所以不拆成兩支。
	 *
	 * 刻意不再重複列一次預約明細：兩頁的商品表格都已經會顯示服務方案、
	 * 預約時段、服務人員與時段保留到幾點（那是 UAPPT_Cart 的
	 * display_cart_item_data() 印的），再列一次只是同樣的資訊出現兩遍。
	 * 這裡缺的其實只有「回去改」這條路。
	 */
	public static function render_back_link() {
		// ⚠️ 這裡刻意**不是** cart_is_booking_only()。那個判斷是給「結帳欄位精簡」
		// 用的——有實體商品就得問地址，所以它必須是「全部都是預約」。但「回去改
		// 預約」跟有沒有實體商品無關，只要車上有預約就該給路。
		//
		// 兩者綁在一起會出事，而且是很容易踩到的一條路：購物車頁上就有加購商品
		// 區塊（twshop 掛在 `woocommerce_after_cart_table`），客人順手加一瓶保養品，
		// 「返回修改預約」就整個消失了。
		if ( ! self::booking_cart_item_keys() ) {
			return;
		}

		$url = self::return_url();
		if ( '' === $url ) {
			return;
		}

		// ⚠️ 只有「購物車裡剛好一筆預約」才是真的能改。帶上那一筆的 key，精靈
		// 送出後 UAPPT_Cart::replace_edited_booking() 會把舊的換掉；沒有這個
		// 參數的話按鈕寫著「修改」、做的卻是「再加一筆」。
		//
		// 兩筆以上時這顆按鈕指不出要改哪一筆（版面上只有一顆），所以退回誠實
		// 的說法：它就是再約一個時段。要改特定那一筆，客人可以先刪掉它——刪除
		// 會釋放時段，是安全的。
		$keys  = self::booking_cart_item_keys();
		$label = __( '‹ 再預約一個時段', 'ultimate-appointments' );

		if ( 1 === count( $keys ) ) {
			$url   = add_query_arg( UAPPT_Cart::EDIT_FIELD, rawurlencode( $keys[0] ), $url );
			$label = __( '‹ 返回修改預約', 'ultimate-appointments' );
		}

		printf(
			'<p class="uappt-checkout-back"><a href="%s">%s</a></p>',
			esc_url( $url ),
			esc_html( $label )
		);
	}

	/**
	 * 購物車裡所有預約項目的 key。
	 *
	 * @return string[]
	 */
	public static function booking_cart_item_keys() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return array();
		}

		$keys = array();
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( ! empty( $item['uappt_booking_id'] ) ) {
				$keys[] = $key;
			}
		}

		return $keys;
	}

	/**
	 * 記下來的精靈頁網址（沒有就回空字串）。
	 *
	 * @return string
	 */
	public static function return_url() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return '';
		}
		return (string) WC()->session->get( self::RETURN_URL_KEY, '' );
	}

	/**
	 * 購物車空了的時候，多給一條回精靈的路。
	 *
	 * WooCommerce 的空購物車版型只有一顆「回到商店」（`cart/cart-empty.php`），
	 * 對純服務的店家來說那條路通到商品列表，不是客人想去的地方。而且客人會
	 * 走到這一頁通常正是因為**剛把預約刪掉**、或**時段逾時被釋放**——後者的
	 * 錯誤訊息還寫著「請重新選擇時段」，卻沒有任何按鈕可以去重新選。
	 *
	 * **只在 session 記得精靈頁網址時才印。** 那個值是在精靈送出時寫入的
	 * （UAPPT_Cart::redirect_wizard_after_add()），所以它同時代表「這位訪客這
	 * 一輪確實走過精靈」。純零售的客人清空購物車不會看到這顆按鈕——他們本來
	 * 就該去商品列表。
	 *
	 * 掛在優先度 20：`wc_empty_cart_message` 是 10，要排在那句話後面；版型裡
	 * 的「回到商店」在 `do_action()` 之後才印，所以這顆會在它前面——剛刪掉
	 * 預約的人，下一步是回去重約的機率比去逛商品高。
	 */
	public static function render_empty_cart_link() {
		$url = self::return_url();
		if ( '' === $url ) {
			return;
		}

		// 沿用佈景主題給 WooCommerce 按鈕的 class，外觀才會跟旁邊的
		// 「回到商店」一致（版型自己也是這樣取的）。
		$theme_class = function_exists( 'wc_wp_theme_get_element_class_name' ) && wc_wp_theme_get_element_class_name( 'button' )
			? ' ' . wc_wp_theme_get_element_class_name( 'button' )
			: '';

		printf(
			'<p class="uappt-cart-empty-back"><a class="button wc-backward%s" href="%s">%s</a></p>',
			esc_attr( $theme_class ),
			esc_url( $url ),
			esc_html__( '回到預約', 'ultimate-appointments' )
		);
	}
}
