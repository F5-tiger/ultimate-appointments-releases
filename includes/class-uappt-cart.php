<?php
/**
 * 購物車串接：加入購物車時鎖定時段、購物車顯示、移除/還原時釋放或重新鎖定、逾時偵測。
 *
 * 這是本外掛與 WooCommerce 唯一真正的整合點：只掛在標準 WooCommerce 購物車
 * hook 上，不觸碰既有電商模組與結帳流程本身。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UAPPT_Cart {

	/**
	 * @var UAPPT_Cart|null
	 */
	protected static $instance = null;

	/**
	 * 暫存本次請求中剛建立、尚未取得 cart_item_key 的暫留資訊。
	 *
	 * @var array|null
	 */
	protected $pending_hold = null;

	/**
	 * 單例。
	 *
	 * @return UAPPT_Cart
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * 建構子：掛載 hooks。
	 */
	protected function __construct() {
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_and_hold' ), 10, 5 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'attach_cart_item_data' ), 10, 3 );
		add_action( 'woocommerce_add_to_cart', array( $this, 'link_cart_item_key' ), 10, 6 );

		add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_item_data' ), 10, 2 );
		add_filter( 'woocommerce_cart_item_quantity', array( $this, 'lock_quantity_display' ), 10, 3 );

		add_action( 'woocommerce_cart_item_removed', array( $this, 'on_cart_item_removed' ), 10, 2 );
		add_action( 'woocommerce_cart_item_restored', array( $this, 'on_cart_item_restored' ), 10, 2 );
		add_action( 'woocommerce_before_cart_emptied', array( $this, 'on_cart_emptied' ) );
		add_filter( 'woocommerce_cart_item_is_purchasable', array( $this, 'keep_held_booking_purchasable' ), 10, 4 );
		// 刻意排在很後面：要看的是**最終**判定，不是自己剛才回傳的那個值。
		add_filter( 'woocommerce_cart_item_is_purchasable', array( $this, 'release_dropped_booking' ), 9999, 4 );
		add_action( 'woocommerce_remove_cart_item_from_session', array( $this, 'on_cart_item_dropped_from_session' ), 10, 2 );

		add_action( 'woocommerce_check_cart_items', array( $this, 'purge_expired_cart_items' ) );

		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_order_item_meta' ), 10, 4 );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'link_order_bookings' ), 10, 1 );
		// 區塊結帳（Store API）不會觸發上面那個 hook，訂單項目照樣寫得到預約 ID，
		// 預約卻沒掛上訂單——15 分鐘後被當成購物車暫留釋放，付款時找不到預約可確認
		// （v3.0.1 修正）。WooCommerce 新站預設就是區塊結帳。
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'link_store_api_order_bookings' ) );

		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_cart_item_price' ) );

		add_filter( 'woocommerce_add_to_cart_redirect', array( $this, 'redirect_wizard_after_add' ), 10, 2 );
		add_filter( 'wc_add_to_cart_message_html', array( $this, 'silence_wizard_add_to_cart_message' ), 10, 2 );
		add_action( 'woocommerce_add_to_cart', array( $this, 'replace_edited_booking' ), 20, 2 );
	}

	/**
	 * 表單欄位名稱：標記這次加入購物車是從前台預約精靈送出的。
	 */
	const SOURCE_FIELD = 'uappt_source';

	/**
	 * SOURCE_FIELD 的值：預約精靈。
	 */
	const SOURCE_WIZARD = 'wizard';

	/**
	 * 「精靈送出後要帶客人去哪一頁」的設定鍵。
	 */
	const AFTER_ADD_OPTION = 'uappt_wizard_after_add';

	/**
	 * 網址參數：這次回精靈是要「改」購物車裡的哪一筆預約。
	 *
	 * 值是購物車項目的 key。由購物車／結帳頁的「返回修改預約」帶上，精靈的
	 * 表單 action 是 `window.location.href`（見 wizard.js 的 submit()），所以
	 * 送出時這個參數會原封不動跟著回到伺服器，不必為它改前端。
	 */
	const EDIT_FIELD = 'uappt_edit';

	/**
	 * 精靈送出後的去處。
	 *
	 * 預設是購物車頁。v2.66.0 之前寫死成「跳過購物車、直接去結帳」，理由是
	 * 精靈的動線不該退回電商購物流程——那個理由本身沒錯，但當時漏掉一件事：
	 * **折抵點數、挑優惠券、加購這些操作介面，在 WooCommerce 生態裡幾乎一律
	 * 掛在購物車頁的 hook 上**。本站的 twshop 就是五個區塊（加購商品、點數
	 * 兌換商品、點數折抵、儲值金、視覺化優惠券）全部掛
	 * `woocommerce_after_cart_table` 與 `woocommerce_before_cart_totals`，
	 * 結帳頁那邊只剩唯讀的「本次使用 N 點」——而那一行讀的還是在購物車頁
	 * 按下折抵時才寫入的值，所以跳過購物車頁的客人連它都不會看到。
	 *
	 * 仍然做成設定而不是直接寫死：沒在用優惠券／點數的店家，那一頁對他們
	 * 來說確實是純粹多出來的一步。
	 *
	 * @return string 'cart' 或 'checkout'。
	 */
	public static function after_add_destination() {
		return 'checkout' === (string) get_option( self::AFTER_ADD_OPTION, 'cart' ) ? 'checkout' : 'cart';
	}

	/**
	 * 這次請求是不是前台預約精靈送出的。
	 *
	 * 精靈的表單會帶 SOURCE_FIELD，商品頁原本的加入購物車不會——轉址與
	 * 訊息兩支都要用同一個判斷，所以抽出來，免得兩邊的條件哪天改到不一致。
	 *
	 * @param WC_Product|int|null $product 一併確認是預約商品；null 代表不檢查。
	 * @return bool
	 */
	public static function is_wizard_request( $product = null ) {
		$source = isset( $_REQUEST[ self::SOURCE_FIELD ] ) // phpcs:ignore WordPress.Security.NonceVerification
			? sanitize_key( wp_unslash( $_REQUEST[ self::SOURCE_FIELD ] ) ) // phpcs:ignore WordPress.Security.NonceVerification
			: '';

		if ( self::SOURCE_WIZARD !== $source ) {
			return false;
		}

		if ( null === $product ) {
			return true;
		}

		$product_id = $product instanceof WC_Product ? $product->get_id() : (int) $product;

		return $product_id > 0 && UAPPT_Product::booking_enabled( $product_id );
	}

	/**
	 * 精靈送出時不要印 WooCommerce 那句「⋯已加入您的購物車〔查看購物車〕」。
	 *
	 * ⚠️ 那顆按鈕會指向**客人當下正站著的那一頁**。WooCommerce 的邏輯是
	 * 「`woocommerce_cart_redirect_after_add` 不是 yes 就假設客人留在原頁，
	 * 所以給一條去購物車的路」（`wc-cart-functions.php` 的 `wc_add_to_cart_message()`），
	 * 但我們自己把客人轉去購物車了，那個前提不成立。
	 *
	 * 連訊息本身一起拿掉而不是只拆按鈕：客人剛按完「確認預約」，落地頁上
	 * 預約明細就在眼前，再用電商語氣講一次「已加入您的購物車」只是雜訊，
	 * 而且「加入購物車」也不是他剛才做的那件事。轉去結帳頁的設定也一樣處理，
	 * 兩種設定的體驗才會一致。
	 *
	 * 只吃精靈送出的請求——商品頁、其他外掛的加入購物車訊息一個字都不動。
	 *
	 * @param string $message  原本的訊息 HTML。
	 * @param array  $products [商品 ID => 數量]。
	 * @return string
	 */
	public function silence_wizard_add_to_cart_message( $message, $products = array() ) {
		if ( ! self::is_wizard_request() ) {
			return $message;
		}

		// 精靈一次只送一個預約商品。萬一同一次請求還夾帶了別的商品（理論上
		// 不會發生），保守起見維持原訊息，不要連別人的通知一起吃掉。
		$ids = array_keys( (array) $products );
		if ( 1 !== count( $ids ) || ! self::is_wizard_request( (int) $ids[0] ) ) {
			return $message;
		}

		return '';
	}

	/**
	 * 「返回修改預約」改完之後，把原本那一筆從購物車拿掉。
	 *
	 * ⚠️ **沒有這一支的話「修改」其實是「再加一筆」**：客人從購物車按返回、
	 * 重選一個時段、再按確認，購物車會變成兩筆預約、兩個時段都被鎖住，金額
	 * 也是兩倍。實測過（13:00 與 18:00 同時在購物車裡，合計 NT$2,400）。
	 * 對店家更糟——產能被莫名其妙佔掉一格。
	 *
	 * **順序是先加後刪，不是先刪後加。** 新的時段必須先鎖定成功，舊的才釋放；
	 * 反過來的話，新時段剛好被別人搶走時客人會兩頭落空。這一支掛在
	 * `woocommerce_add_to_cart`，也就是新項目已經確定進購物車之後——加入失敗
	 * 時 WooCommerce 根本不會觸發這個 action，舊的自然原封不動。
	 *
	 * 安全性：這條路只在精靈送出時成立，而精靈的 POST 有 `uappt_add_booking`
	 * nonce（見 validate_and_hold()），所以不會被外部網址誘導觸發。再加上
	 * 「只刪購物車裡真的存在、而且真的是預約項目的 key」兩道界線，最壞情況
	 * 也只是刪掉客人自己剛剛那一筆。
	 *
	 * @param string $cart_item_key 這次新增進去的項目 key。
	 * @param int    $product_id    這次新增的商品 ID。
	 * @return void
	 */
	public function replace_edited_booking( $cart_item_key, $product_id = 0 ) {
		if ( ! self::is_wizard_request( $product_id ) ) {
			return;
		}

		$edit_key = isset( $_REQUEST[ self::EDIT_FIELD ] ) // phpcs:ignore WordPress.Security.NonceVerification
			? wc_clean( wp_unslash( $_REQUEST[ self::EDIT_FIELD ] ) ) // phpcs:ignore WordPress.Security.NonceVerification
			: '';

		// 同一個 key 代表 WooCommerce 判定「跟舊項目一模一樣」而只加了數量，
		// 這時候刪掉等於把客人剛送出的預約也刪掉。
		if ( '' === $edit_key || $edit_key === $cart_item_key ) {
			return;
		}

		$cart = WC()->cart;
		if ( ! $cart ) {
			return;
		}

		$old = $cart->get_cart_item( $edit_key );

		// 只動預約項目：這個參數是從網址來的，不能讓它有機會清掉客人購物車裡
		// 的保養品。找不到也直接算了——舊項目可能已經逾時被釋放。
		if ( ! $old || empty( $old['uappt_booking_id'] ) ) {
			return;
		}

		// remove_cart_item() 會觸發 on_cart_item_removed()，舊時段在那裡釋放。
		$cart->remove_cart_item( $edit_key );
	}

	/**
	 * 從預約精靈送出的預約，加入購物車後帶去購物車頁（或結帳頁）。
	 *
	 * 去哪一頁由 after_add_destination() 決定，見那一支的說明。
	 *
	 * ⚠️ **不能靠「拿掉這個 filter」換成購物車頁。** 沒有 filter 時的行為是
	 * WooCommerce 自己的 `woocommerce_cart_redirect_after_add` 選項在管的，
	 * 那個選項預設（本站也是）是 `no`＝「留在原頁」——客人會停在精靈那一頁，
	 * 既沒去購物車也沒去結帳，比兩個去處都糟。所以兩種設定都要自己明確
	 * 回傳網址。
	 *
	 * **刻意只認精靈送出的請求**，不是「所有預約商品一律轉址」：
	 *
	 * 1. 商品頁原本的加入購物車行為一個字都不改。要不要跳過購物車頁是
	 *    WooCommerce 自己的 `woocommerce_cart_redirect_after_add` 設定在管的，
	 *    店家可能刻意設成留在原頁讓客人繼續加購。
	 * 2. 客人加購一瓶保養品（非預約商品）不該被丟去結帳。
	 *
	 * ⚠️ `$adding_to_cart` 可能是 null：WooCommerce 另外在
	 * `class-wc-frontend-scripts.php` 用 `null` 呼叫同一個 filter，去算給前端 JS
	 * 用的 `cart_url` 參數（已讀原始碼確認）。那時候沒有人在加入購物車，
	 * 不擋掉的話會把**全站的購物車網址**換成結帳頁。
	 *
	 * 加入失敗時不會走到這裡：WooCommerce 只在
	 * `$was_added_to_cart && 0 === wc_notice_count( 'error' )` 時才套用這個
	 * filter（`class-wc-form-handler.php:944`），所以時段被搶走之類的情況會
	 * 停在原頁顯示錯誤，不會把客人帶到空的購物車。
	 *
	 * @param string          $url            預定要轉址的網址（空字串代表不轉址）。
	 * @param WC_Product|null $adding_to_cart 這次加入的商品；null 見上方說明。
	 * @return string
	 */
	public function redirect_wizard_after_add( $url, $adding_to_cart = null ) {
		if ( ! $adding_to_cart instanceof WC_Product ) {
			return $url;
		}

		if ( ! self::is_wizard_request( $adding_to_cart ) ) {
			return $url;
		}

		// 這個時間點的網址就是精靈所在的頁面（精靈的表單是 POST 回自己那一頁），
		// 記下來給購物車／結帳頁的「返回修改預約」用。
		//
		// 三個參數一定要拿掉：
		// - add-to-cart／quantity：不拿掉的話點回去會再加一次購物車。
		// - ⚠️ uappt_edit：這一次「改」用掉的 key 已經失效了，留在記住的網址裡，
		//   下一次按返回時會帶著一把過期的鑰匙。真正會出事的是購物車裡有兩筆
		//   預約的情況——那時按鈕寫的是「再預約一個時段」、不該刪任何東西，
		//   卻會因為這個殘留參數默默刪掉一筆。
		if ( isset( $_SERVER['REQUEST_URI'] ) ) {
			$here = home_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			UAPPT_Checkout::remember_return_url(
				remove_query_arg( array( 'add-to-cart', 'quantity', self::EDIT_FIELD ), $here )
			);
		}

		return 'checkout' === self::after_add_destination() ? wc_get_checkout_url() : wc_get_cart_url();
	}

	/**
	 * 加入購物車驗證：讀取客人選擇的日期/時段，交易鎖定該時段，失敗則阻擋加入購物車。
	 *
	 * @param bool  $passed       目前是否通過驗證。
	 * @param int   $product_id   商品 ID。
	 * @param int   $quantity     數量。
	 * @param int   $variation_id 變化款 ID（預約商品不使用，保留以符合 hook 簽章）。
	 * @param array $variations   變化款屬性（同上）。
	 * @return bool
	 */
	public function validate_and_hold( $passed, $product_id, $quantity, $variation_id = 0, $variations = array() ) {
		if ( ! $passed || ! UAPPT_Product::booking_enabled( $product_id ) ) {
			return $passed;
		}

		// 暫停接受預約：早一步擋下來，客人才會看到「暫停」而不是後面那些通用錯誤。
		// （真正的防線在 UAPPT_Booking::create_hold()，那裡也會再檢查一次。）
		if ( UAPPT_Product::is_paused( $product_id ) ) {
			wc_add_notice( __( '此服務目前暫停接受預約，請聯繫客服。', 'ultimate-appointments' ), 'error' );
			return false;
		}

		$plan_key = isset( $_POST['uappt_plan_key'] ) ? sanitize_key( wp_unslash( $_POST['uappt_plan_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		// 商品有方案卻沒送方案鍵：多半是前台 JS 沒跑（快取、佈景衝突），
		// 這時候放行等於讓客人買到一個沒指定時長與價格的服務。
		if ( UAPPT_Product::get_plans( $product_id ) && '' === $plan_key ) {
			wc_add_notice( __( '請先選擇服務方案，再加入購物車。', 'ultimate-appointments' ), 'error' );
			return false;
		}

		// 停售的方案：客人可能是拿著舊分頁或快取頁面送出的。create_hold() 才是真正的
		// 防線，這裡先給一句看得懂的話，免得他撞到引擎的通用錯誤訊息。
		if ( '' !== $plan_key && UAPPT_Product::is_plan_inactive( $product_id, $plan_key ) ) {
			wc_add_notice( __( '此服務方案目前暫停銷售，請重新整理頁面後改選其他方案。', 'ultimate-appointments' ), 'error' );
			return false;
		}

		// 商品是預約商品，但這個方案沒有指定可服務人員（或方案已被刪除）時，不能讓它
		// 靜默地以「非預約商品」的身分被加入購物車——那會變成沒有時段卻賣得出去。
		if ( ! UAPPT_Product::is_bookable( $product_id, $plan_key ) ) {
			wc_add_notice(
				__( '此服務尚未完成預約設定（缺少可服務人員），或選擇的方案已不存在，請重新整理頁面或聯繫客服。', 'ultimate-appointments' ),
				'error'
			);
			return false;
		}

		if ( $quantity > 1 ) {
			wc_add_notice( __( '此服務每次僅能預約一個時段，如需多個時段請分別加入購物車。', 'ultimate-appointments' ), 'error' );
			return false;
		}

		if (
			! isset( $_POST['uappt_booking_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['uappt_booking_nonce'] ) ), 'uappt_add_booking' )
		) {
			wc_add_notice( __( '安全驗證失敗，請重新整理頁面後再試一次。', 'ultimate-appointments' ), 'error' );
			return false;
		}

		$date_ymd = isset( $_POST['uappt_booking_date'] ) ? sanitize_text_field( wp_unslash( $_POST['uappt_booking_date'] ) ) : '';
		$time_hm  = isset( $_POST['uappt_booking_time'] ) ? sanitize_text_field( wp_unslash( $_POST['uappt_booking_time'] ) ) : '';
		$staff_id = isset( $_POST['uappt_booking_staff'] ) ? absint( $_POST['uappt_booking_staff'] ) : 0;

		// 全域關掉「讓客人指定服務人員」、或這個商品自己勾了「不開放客人指定」時，
		// 一律當作不指定。前台不輸出那個下拉選單只是體驗層，欄位是客人自己可以
		// 補上的，而指定人員還牽涉到加價。
		if ( ! UAPPT_Product::staff_choice_enabled( $product_id ) ) {
			$staff_id = 0;
		}

		if ( '' === $date_ymd || '' === $time_hm ) {
			wc_add_notice( __( '請先選擇預約日期與時段，再加入購物車。', 'ultimate-appointments' ), 'error' );
			return false;
		}

		// 團體預約「報名人數」，跟上面 $quantity（購物車列的份數，這個商品永遠
		// 固定 1）是兩件不相干的事：這裡是「這一個時段要算幾個人頭」。商品沒開
		// 團體預約時這個欄位前台根本不會出現，$_POST 拿不到值，預設回 1，
		// 行為跟今天完全一樣。真正擋 >1 是否合法的地方在 create_hold()。
		$participants = isset( $_POST['uappt_participants'] ) ? max( 1, absint( $_POST['uappt_participants'] ) ) : 1;

		$booking_id = UAPPT_Booking::create_hold(
			array(
				'product_id'  => $product_id,
				'plan_key'    => $plan_key,
				'date_ymd'    => $date_ymd,
				'time_hm'     => $time_hm,
				'staff_id'    => $staff_id,
				'units'       => $participants,
				'customer_id' => get_current_user_id(),
			)
		);

		if ( is_wp_error( $booking_id ) ) {
			wc_add_notice( $booking_id->get_error_message(), 'error' );
			return false;
		}

		$settings = UAPPT_Product::get_booking_settings( $product_id, $plan_key );

		$this->pending_hold = array(
			'booking_id'   => $booking_id,
			'product_id'   => $product_id,
			'plan_key'     => $plan_key,
			'plan_name'    => $settings ? $settings['plan_name'] : '',
			'plan_price'   => $settings ? (string) $settings['price'] : '',
			'date_ymd'     => $date_ymd,
			'time_hm'      => $time_hm,
			'participants' => $participants,
		);

		return true;
	}

	/**
	 * 將剛建立的暫留 ID 與顯示用時段資訊寫入購物車項目資料（會被計入 cart_item_key 雜湊）。
	 *
	 * @param array $cart_item_data 購物車項目資料。
	 * @param int   $product_id 商品 ID。
	 * @param int   $variation_id 變體 ID。
	 * @return array
	 */
	public function attach_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
		if ( null === $this->pending_hold || (int) $this->pending_hold['product_id'] !== (int) $product_id ) {
			return $cart_item_data;
		}

		$booking = UAPPT_Booking::get( $this->pending_hold['booking_id'] );

		// plan_key 會被算進 cart_item_key 的雜湊，所以同一個商品的不同方案自然
		// 就是不同的購物車項目，不會被合併成數量 2。
		$cart_item_data['uappt_booking_id']            = $this->pending_hold['booking_id'];
		$cart_item_data['uappt_plan_key']              = $this->pending_hold['plan_key'];
		$cart_item_data['uappt_plan_name']             = $this->pending_hold['plan_name'];
		$cart_item_data['uappt_plan_price']            = $this->pending_hold['plan_price'];
		$cart_item_data['uappt_date']                  = $this->pending_hold['date_ymd'];
		$cart_item_data['uappt_time']                  = $this->pending_hold['time_hm'];
		$cart_item_data['uappt_label']                 = $booking ? $this->format_label( $booking ) : '';
		// 記錄「客人這次是否指定人員」而不是只記人員 ID：購物車項目被移除又復原
		// （on_cart_item_restored）時要原樣重現客人當初的選擇——有指定就只重試
		// 同一位，沒指定就讓系統重新挑選，不能一律鎖死成當初系統剛好選中的那位。
		$cart_item_data['uappt_staff_id']              = $booking ? (int) $booking['staff_id'] : 0;
		$cart_item_data['uappt_staff_requested']       = $booking && ! empty( $booking['staff_requested'] );
		$cart_item_data['uappt_staff_name']            = $booking ? self::get_staff_name( (int) $booking['staff_id'] ) : '';
		$cart_item_data['uappt_staff_price_adjustment'] = $booking ? (float) $booking['staff_price_adjustment'] : 0.0;
		// 團體預約的報名人數：讀回 booking 紀錄自己存的 occupied_units，不是
		// 直接沿用 pending_hold 裡客人送出的原始值——兩者理論上一致，但跟其他
		// 欄位一樣以資料庫紀錄為準才是唯一事實來源。
		$cart_item_data['uappt_participants']          = $booking ? max( 1, (int) $booking['occupied_units'] ) : 1;

		$this->pending_hold = null;

		return $cart_item_data;
	}

	/**
	 * 商品實際加入購物車、cart_item_key 產生後，把 key 補寫回預約紀錄，供後續移除/釋放查找。
	 *
	 * @param string $cart_item_key 購物車項目 key。
	 * @param int    $product_id 商品 ID。
	 * @param int    $quantity 數量。
	 * @param int    $variation_id 變體 ID。
	 * @param array  $variation 變體資料。
	 * @param array  $cart_item_data 購物車項目資料。
	 */
	public function link_cart_item_key( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
		if ( empty( $cart_item_data['uappt_booking_id'] ) ) {
			return;
		}
		UAPPT_Booking::attach_cart_item_key( $cart_item_data['uappt_booking_id'], $cart_item_key );
	}

	/**
	 * 在購物車/結帳頁顯示已選時段。
	 *
	 * @param array $item_data 顯示資料陣列。
	 * @param array $cart_item 購物車項目。
	 * @return array
	 */
	public function display_cart_item_data( $item_data, $cart_item ) {
		if ( ! empty( $cart_item['uappt_plan_name'] ) ) {
			$item_data[] = array(
				'key'   => __( '服務方案', 'ultimate-appointments' ),
				'value' => wc_clean( $cart_item['uappt_plan_name'] ),
			);
		}

		if ( ! empty( $cart_item['uappt_label'] ) ) {
			$item_data[] = array(
				'key'   => __( '預約時段', 'ultimate-appointments' ),
				'value' => wc_clean( $cart_item['uappt_label'] ),
			);
		}

		// 只有 > 1 才顯示：1 人是絕大多數服務唯一會出現的情況，每筆都印「人數：1」
		// 只是雜訊，跟「服務人員」只在客人自己指定時才顯示是同一個道理。
		if ( ! empty( $cart_item['uappt_participants'] ) && (int) $cart_item['uappt_participants'] > 1 ) {
			$item_data[] = array(
				'key'   => __( '報名人數', 'ultimate-appointments' ),
				/* translators: %d: 人數 */
				'value' => sprintf( __( '%d 人', 'ultimate-appointments' ), (int) $cart_item['uappt_participants'] ),
			);
		}

		// 只有「客人自己指定了人員」才顯示人員——客人選「不指定」時，系統雖然為了
		// 不超賣已經先暫定了一位（設計紀律 #3：必須在同一個交易內決定出具體的人），
		// 但那只是內部的產能鎖定，不是對客人的承諾：實務上人選可能到現場才依當天
		// 人力調度，也可能由管理者事後分派。把暫定的名字印給客人看，客人會以為
		// 已經排定，之後換人就變成「說好的人被換掉」的客訴。
		if ( ! empty( $cart_item['uappt_staff_requested'] ) && ! empty( $cart_item['uappt_staff_name'] ) ) {
			$value = $cart_item['uappt_staff_name'];
			if ( ! empty( $cart_item['uappt_staff_price_adjustment'] ) ) {
				$value .= sprintf( ' (+%s)', wc_price( (float) $cart_item['uappt_staff_price_adjustment'] ) );
			}
			$item_data[] = array(
				'key'   => __( '服務人員', 'ultimate-appointments' ),
				'value' => $value,
			);
		}

		// 讓客人知道「這個時段暫時保留到幾點」，逾時會被釋放——避免結帳結到一半
		// 才發現時段被別人搶走卻不知道為什麼。已確認的預約（已付款訂單裡的項目）
		// 不會再逾時，不需要顯示這行。
		if ( ! empty( $cart_item['uappt_booking_id'] ) ) {
			$booking = UAPPT_Booking::get( $cart_item['uappt_booking_id'] );
			if ( $booking && UAPPT_Booking::STATUS_HELD === $booking['status'] && ! empty( $booking['expires_at'] ) ) {
				$expires_dt = date_create( $booking['expires_at'], wp_timezone() );
				if ( $expires_dt ) {
					$item_data[] = array(
						'key'   => __( '時段保留至', 'ultimate-appointments' ),
						'value' => wc_clean( wp_date( 'H:i', $expires_dt->getTimestamp() ) ),
					);
				}
			}
		}

		return $item_data;
	}

	/**
	 * 預約商品在購物車內固定顯示數量 1，不提供輸入框調整（每筆代表一個獨立時段）。
	 *
	 * @param string $quantity_html 原本的數量欄位 HTML。
	 * @param string $cart_item_key 購物車項目 key。
	 * @param array  $cart_item 購物車項目。
	 * @return string
	 */
	public function lock_quantity_display( $quantity_html, $cart_item_key, $cart_item ) {
		if ( ! empty( $cart_item['uappt_booking_id'] ) ) {
			return '1 <input type="hidden" name="cart[' . esc_attr( $cart_item_key ) . '][qty]" value="1" />';
		}
		return $quantity_html;
	}

	/**
	 * 購物車項目被移除時，釋放對應的暫留。
	 *
	 * @param string   $cart_item_key 購物車項目 key。
	 * @param WC_Cart  $cart 購物車物件。
	 */
	public function on_cart_item_removed( $cart_item_key, $cart ) {
		$removed = isset( $cart->removed_cart_contents[ $cart_item_key ] ) ? $cart->removed_cart_contents[ $cart_item_key ] : null;

		if ( $removed && ! empty( $removed['uappt_booking_id'] ) ) {
			UAPPT_Booking::release( (int) $removed['uappt_booking_id'] );
			return;
		}

		UAPPT_Booking::release_by_cart_item_key( $cart_item_key );
	}

	/**
	 * 店家按下「暫停接受預約」時，不要把客人已經握在手上的暫留扯掉。
	 *
	 * ⚠️ **`WC_Cart_Session::get_cart_from_session()` 每次載入購物車都會重驗
	 * `is_purchasable()`**（WooCommerce 7.0 起走這個篩選器），不通過就把項目
	 * 丟掉、只印一句「已從購物車移除」。而那條分支**不會**觸發
	 * `woocommerce_cart_item_removed`，所以我們的暫留不會被釋放——時段被卡著、
	 * 客人的預約也不見了，兩邊都輸。實測重現過。
	 *
	 * 「暫停接受預約」要擋的是**新的**預約（create_hold() 自己也會擋，
	 * class-uappt-booking.php 的 ignore_paused）。客人在暫停之前就已經鎖好的
	 * 時段，讓他把流程走完才合理。
	 *
	 * **只在「不可購買純粹是因為暫停」時放行。** 商品被改成草稿、價格被清空
	 * 這類真正的問題，照核心的規則移除——那時候項目本來就不該結得出去。
	 *
	 * 也只放行**還在有效期內的暫留**：已經逾時的讓核心移除，反正
	 * purge_expired_cart_items() 本來就會處理它。expires_at 是 NULL 的代表
	 * 已經掛上訂單（見 UAPPT_Booking::link_to_order()），那不歸這裡管。
	 *
	 * @param bool       $purchasable 目前的判定。
	 * @param string     $cart_item_key 購物車項目 key。
	 * @param array      $values        購物車項目內容。
	 * @param WC_Product $product       商品。
	 * @return bool
	 */
	public function keep_held_booking_purchasable( $purchasable, $cart_item_key, $values, $product ) {
		if ( $purchasable || empty( $values['uappt_booking_id'] ) ) {
			return $purchasable;
		}

		if ( ! $product instanceof WC_Product || ! UAPPT_Product::is_paused( $product->get_id() ) ) {
			return $purchasable;
		}

		// 撇開暫停之後仍然不可購買，就不是暫停造成的，照核心處理。
		if ( ! method_exists( $product, 'is_purchasable_ignoring_pause' ) || ! $product->is_purchasable_ignoring_pause() ) {
			return $purchasable;
		}

		$booking = UAPPT_Booking::get( (int) $values['uappt_booking_id'] );

		if ( ! $booking || UAPPT_Booking::STATUS_HELD !== $booking['status'] || empty( $booking['expires_at'] ) ) {
			return $purchasable;
		}

		$expires = date_create( $booking['expires_at'], wp_timezone() );
		if ( ! $expires || $expires->getTimestamp() < time() ) {
			return $purchasable;
		}

		return true;
	}

	/**
	 * 核心確定要把這個預約項目丟掉時，順手釋放時段。
	 *
	 * ⚠️ `WC_Cart_Session::get_cart_from_session()` 的「不可購買」那條分支只印
	 * 一句通知就 `continue`，**沒有任何 action 可以掛**。所以唯一能得知「這筆
	 * 要被丟掉了」的地方，就是這個篩選器本身——它回 false 的下一行核心就把項目
	 * 跳過了。
	 *
	 * 沒有這一支的話，商品被改成草稿／丟進垃圾桶時，購物車裡那筆預約會消失，
	 * 但時段的暫留還卡著，要等 expires_at 過期再等 cron 掃（最壞約 20 分鐘）。
	 * 時段格是掛在**人員**身上的，所以就算商品被刪掉，那段時間照樣被佔著。
	 *
	 * 優先度 9999：要讀的是所有外掛都跑完之後的最終值。自己的
	 * keep_held_booking_purchasable() 在 10，會先把「只是暫停」的救回來，
	 * 走到這裡還是 false 的才是真的要被丟掉。
	 *
	 * 這是個純讀取的篩選器，值原封不動傳出去。
	 *
	 * @param bool       $purchasable   最終判定。
	 * @param string     $cart_item_key 購物車項目 key。
	 * @param array      $values        購物車項目內容。
	 * @param WC_Product $product       商品。
	 * @return bool
	 */
	public function release_dropped_booking( $purchasable, $cart_item_key, $values, $product ) {
		if ( $purchasable || empty( $values['uappt_booking_id'] ) ) {
			return $purchasable;
		}

		$booking = UAPPT_Booking::get( (int) $values['uappt_booking_id'] );

		// 跟 on_cart_emptied() 同一條規則：expires_at 為 NULL 代表已經掛上訂單，
		// 那不是購物車暫留，不能碰。
		if ( $booking && UAPPT_Booking::STATUS_HELD === $booking['status'] && ! empty( $booking['expires_at'] ) ) {
			UAPPT_Booking::release( (int) $booking['id'] );
		}

		return $purchasable;
	}

	/**
	 * 其他外掛用 `woocommerce_pre_remove_cart_item_from_session` 把項目踢掉時，
	 * 一併釋放時段。
	 *
	 * 這是 WooCommerce 在「還原購物車時丟掉項目」這條路上唯一提供的 action
	 * （另外兩條分支——商品不存在、不可購買——連 action 都沒有）。掛上它至少
	 * 讓第三方那條路不會留下孤兒暫留；剩下的仍有 cron 當最後一道保險。
	 *
	 * @param string $cart_item_key 購物車項目 key。
	 * @param array  $values        購物車項目內容。
	 * @return void
	 */
	public function on_cart_item_dropped_from_session( $cart_item_key, $values = array() ) {
		$booking_id = ! empty( $values['uappt_booking_id'] ) ? (int) $values['uappt_booking_id'] : 0;

		if ( ! $booking_id ) {
			UAPPT_Booking::release_by_cart_item_key( $cart_item_key );
			return;
		}

		$booking = UAPPT_Booking::get( $booking_id );

		// 跟 on_cart_emptied() 同一條規則：掛了訂單的（expires_at 為 NULL）不能碰。
		if ( $booking && UAPPT_Booking::STATUS_HELD === $booking['status'] && ! empty( $booking['expires_at'] ) ) {
			UAPPT_Booking::release( $booking_id );
		}
	}

	/**
	 * 整車清空時釋放還沒付款的暫留時段。
	 *
	 * `empty_cart()` **不會**逐項觸發 `woocommerce_cart_item_removed`，所以少了
	 * 這一支，被整車清掉的暫留會一路卡到 `expires_at` 過期、再等最多 5 分鐘讓
	 * cron 掃到（`UAPPT_Cron::HOOK`）——最壞情況那個時段會白白鎖住約 20 分鐘。
	 * 不是資料錯誤，是可以省下來的等待。
	 *
	 * ⚠️ **這裡的條件必須是「held 而且 expires_at 不是 NULL」，少一個都會出事。**
	 *
	 * 1. `UAPPT_Booking::release()` 連 **confirmed** 也照樣取消（它的白名單是
	 *    held＋confirmed），所以不能無條件呼叫。
	 * 2. 更隱蔽的是 held：`link_to_order()` 只寫入 order_id 並把 `expires_at`
	 *    設成 NULL，**狀態仍然停在 held**，要等付款完成才變 confirmed。而
	 *    `wc_clear_cart_after_payment()` 會在下單後清空購物車——也就是說，這支
	 *    hook 執行的當下，一張「已成立但還沒付款」的訂單（綠界轉頁、ATM 轉帳、
	 *    貨到付款）底下的預約正好是 held。只看狀態就會把真實訂單的預約取消掉。
	 *
	 * `expires_at IS NOT NULL` 正是「還只是購物車暫留、尚未掛上訂單」的判準，
	 * 跟 `release_expired_holds()` 的 SQL 用的是同一條規則——兩邊要一起看。
	 * 掛單未付款的那些，本來就有 `release_stale_pending_orders()` 在管。
	 *
	 * 用 `woocommerce_before_cart_emptied` 而不是 `woocommerce_cart_emptied`：
	 * 後者觸發時 `cart_contents` 已經被清成空陣列，讀不到任何 booking id。
	 *
	 * @return void
	 */
	public function on_cart_emptied() {
		$cart = WC()->cart;
		if ( ! $cart ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['uappt_booking_id'] ) ) {
				continue;
			}

			$booking = UAPPT_Booking::get( (int) $cart_item['uappt_booking_id'] );
			if ( ! $booking ) {
				continue;
			}

			if ( UAPPT_Booking::STATUS_HELD !== $booking['status'] || empty( $booking['expires_at'] ) ) {
				continue;
			}

			UAPPT_Booking::release( (int) $booking['id'] );
		}
	}

	/**
	 * 購物車項目被「復原」（undo remove）時，因原本的暫留已被釋放，需重新嘗試鎖定同一時段；
	 * 若該時段已被別人搶走，則整筆從購物車移除並提示客人。
	 *
	 * @param string  $cart_item_key 購物車項目 key。
	 * @param WC_Cart $cart 購物車物件。
	 */
	public function on_cart_item_restored( $cart_item_key, $cart ) {
		if ( ! isset( $cart->cart_contents[ $cart_item_key ] ) ) {
			return;
		}

		$item = $cart->cart_contents[ $cart_item_key ];
		if ( empty( $item['uappt_date'] ) || empty( $item['uappt_time'] ) ) {
			return;
		}

		// 重現客人當初的選擇：有指定人員就只重試同一位，沒指定就讓系統重新挑選
		// （原本系統挑的那位不一定還有空，因為暫留已經被釋放過一次）；團體預約
		// 的報名人數也要原樣重試，不能重建成 1 人——否則客人復原購物車項目後，
		// 明明訂的是 3 人的課，時段卻只鎖了 1 個名額。
		$booking_id = UAPPT_Booking::create_hold(
			array(
				'product_id'  => $item['product_id'],
				'plan_key'    => isset( $item['uappt_plan_key'] ) ? (string) $item['uappt_plan_key'] : '',
				'date_ymd'    => $item['uappt_date'],
				'time_hm'     => $item['uappt_time'],
				'staff_id'    => ! empty( $item['uappt_staff_requested'] ) ? (int) $item['uappt_staff_id'] : 0,
				'units'       => ! empty( $item['uappt_participants'] ) ? (int) $item['uappt_participants'] : 1,
				'customer_id' => get_current_user_id(),
			)
		);

		if ( is_wp_error( $booking_id ) ) {
			unset( $cart->cart_contents[ $cart_item_key ] );
			wc_add_notice(
				__( '很抱歉，您先前選擇的時段已被別人預約走了，該項目已從購物車移除，請重新選擇時段。', 'ultimate-appointments' ),
				'error'
			);
			return;
		}

		$booking = UAPPT_Booking::get( $booking_id );

		UAPPT_Booking::attach_cart_item_key( $booking_id, $cart_item_key );
		$cart->cart_contents[ $cart_item_key ]['uappt_booking_id']             = $booking_id;
		$cart->cart_contents[ $cart_item_key ]['uappt_staff_id']               = $booking ? (int) $booking['staff_id'] : 0;
		$cart->cart_contents[ $cart_item_key ]['uappt_staff_name']             = $booking ? self::get_staff_name( (int) $booking['staff_id'] ) : '';
		$cart->cart_contents[ $cart_item_key ]['uappt_staff_price_adjustment'] = $booking ? (float) $booking['staff_price_adjustment'] : 0.0;
		$cart->cart_contents[ $cart_item_key ]['uappt_participants']           = $booking ? max( 1, (int) $booking['occupied_units'] ) : 1;
	}

	/**
	 * 設定預約項目的實際單價：方案價 + 指定人員加價。
	 *
	 * 每次都從基準價「重新計算」（而不是在目前價格上累加），是因為
	 * woocommerce_before_calculate_totals 同一次請求可能觸發多次（例如同時有
	 * 運費外掛也在算總價），用累加寫法會疊加好幾次金額。
	 *
	 * 基準價優先用購物車項目上記錄的方案價（客人加入購物車當下的價格），沒有方案
	 * 才回頭用商品目前的售價。
	 *
	 * @param WC_Cart $cart 購物車物件。
	 */
	public function apply_cart_item_price( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['uappt_booking_id'] ) ) {
				continue;
			}

			$adjustment   = isset( $cart_item['uappt_staff_price_adjustment'] ) ? (float) $cart_item['uappt_staff_price_adjustment'] : 0.0;
			$plan_price   = isset( $cart_item['uappt_plan_price'] ) ? (string) $cart_item['uappt_plan_price'] : '';
			$participants = isset( $cart_item['uappt_participants'] ) ? max( 1, (int) $cart_item['uappt_participants'] ) : 1;

			if ( '' === $plan_price && 0.0 === $adjustment && 1 === $participants ) {
				// 沒有方案價、沒有加價、單人：商品原價就是正確答案，不必動它。
				continue;
			}

			if ( '' !== $plan_price ) {
				$base = (float) $plan_price;
			} else {
				$fresh = wc_get_product( $cart_item['product_id'] );
				if ( ! $fresh ) {
					continue;
				}
				$base = (float) $fresh->get_price();
			}

			// 方案價是「每人」的價格，人數要乘進去；人員指定加價是「這一次
			// 服務」的加價（挑誰服務你多收一次錢），不是每人都再收一次，維持
			// 固定不乘。購物車列的數量（qty）本身鎖死在 1，這裡的 $participants
			// 才是團體預約真正的人頭數，兩者不是同一件事。
			$cart_item['data']->set_price( $base * $participants + $adjustment );
		}
	}

	/**
	 * 取得人員姓名（找不到時回傳空字串，不中斷流程）。
	 *
	 * @param int $staff_id 人員 ID。
	 * @return string
	 */
	protected static function get_staff_name( $staff_id ) {
		if ( ! $staff_id ) {
			return '';
		}
		$staff = UAPPT_Staff::get( $staff_id );
		return $staff ? $staff['name'] : '';
	}

	/**
	 * 每次載入購物車/結帳頁時，清掉已逾時但 cron 還沒來得及處理的暫留項目。
	 */
	public function purge_expired_cart_items() {
		$cart = WC()->cart;
		if ( ! $cart ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			if ( empty( $cart_item['uappt_booking_id'] ) ) {
				continue;
			}

			$booking = UAPPT_Booking::get( $cart_item['uappt_booking_id'] );

			if ( ! $booking || in_array( $booking['status'], array( UAPPT_Booking::STATUS_CANCELLED, UAPPT_Booking::STATUS_EXPIRED ), true ) ) {
				$cart->remove_cart_item( $cart_item_key );
				wc_add_notice(
					__( '很抱歉，您預約的時段已逾時釋放，該項目已從購物車移除，請重新選擇時段。', 'ultimate-appointments' ),
					'error'
				);
				continue;
			}

			$expires_dt = ! empty( $booking['expires_at'] ) ? date_create( $booking['expires_at'], wp_timezone() ) : null;
			if ( UAPPT_Booking::STATUS_HELD === $booking['status'] && $expires_dt && $expires_dt->getTimestamp() < time() ) {
				UAPPT_Booking::release( (int) $booking['id'], UAPPT_Booking::STATUS_EXPIRED );
				$cart->remove_cart_item( $cart_item_key );
				wc_add_notice(
					__( '很抱歉，您預約的時段已逾時釋放，該項目已從購物車移除，請重新選擇時段。', 'ultimate-appointments' ),
					'error'
				);
			}
		}
	}

	/**
	 * 結帳建立訂單時，把預約 ID 與時段標籤寫入訂單項目 meta。
	 *
	 * @param WC_Order_Item_Product $item 訂單項目。
	 * @param string                $cart_item_key 購物車項目 key。
	 * @param array                 $values 購物車項目資料。
	 * @param WC_Order              $order 訂單物件。
	 */
	public function save_order_item_meta( $item, $cart_item_key, $values, $order ) {
		if ( empty( $values['uappt_booking_id'] ) ) {
			return;
		}

		$item->add_meta_data( '_uappt_booking_id', (int) $values['uappt_booking_id'], true );

		if ( ! empty( $values['uappt_plan_name'] ) ) {
			$item->add_meta_data( __( '服務方案', 'ultimate-appointments' ), $values['uappt_plan_name'], true );
		}

		if ( ! empty( $values['uappt_label'] ) ) {
			$item->add_meta_data( __( '預約時段', 'ultimate-appointments' ), $values['uappt_label'], true );
		}

		// 同樣只有 > 1 才寫：跟購物車顯示同一條規則，避免每張訂單都印「報名人數：1」。
		if ( ! empty( $values['uappt_participants'] ) && (int) $values['uappt_participants'] > 1 ) {
			$item->add_meta_data(
				__( '報名人數', 'ultimate-appointments' ),
				/* translators: %d: 人數 */
				sprintf( __( '%d 人', 'ultimate-appointments' ), (int) $values['uappt_participants'] ),
				true
			);
		}

		// 跟購物車顯示同一條規則：客人選「不指定」就不寫這個 meta。這個 meta 是
		// 感謝頁、訂單詳情、訂單通知信共同的資料來源，所以不寫就等於三個地方
		// 一起不顯示，不需要各自去改樣板。後台要看目前排給誰，走預約列表／編輯頁／
		// 日曆，或訂單頁由 UAPPT_Order::render_booking_link_in_order() 顯示。
		if ( ! empty( $values['uappt_staff_requested'] ) && ! empty( $values['uappt_staff_name'] ) ) {
			$staff_value = $values['uappt_staff_name'];
			if ( ! empty( $values['uappt_staff_price_adjustment'] ) ) {
				$staff_value .= sprintf( ' (+%s)', wc_price( (float) $values['uappt_staff_price_adjustment'] ) );
			}
			$item->add_meta_data( __( '服務人員', 'ultimate-appointments' ), $staff_value, true );
		}
	}

	/**
	 * 訂單成立後，把預約紀錄與訂單/訂單項目建立關聯（此時尚未付款確認，狀態仍為 held）。
	 *
	 * @param int $order_id 訂單 ID。
	 */
	public function link_order_bookings( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		foreach ( $order->get_items() as $item_id => $item ) {
			$booking_id = $item->get_meta( '_uappt_booking_id', true );
			if ( $booking_id ) {
				UAPPT_Booking::link_to_order( (int) $booking_id, $order_id, $item_id );
			}
		}
	}

	/**
	 * 區塊結帳版的 link_order_bookings()：Store API 傳的是訂單物件，不是 ID。
	 *
	 * @param WC_Order $order 訂單。
	 */
	public function link_store_api_order_bookings( $order ) {
		if ( $order instanceof WC_Order ) {
			$this->link_order_bookings( $order->get_id() );
		}
	}

	/**
	 * 組出「2026-08-25 14:00–15:30」這樣的顯示字串。
	 *
	 * @param array $booking 預約紀錄。
	 * @return string
	 */
	protected function format_label( $booking ) {
		// service_start/service_end 是以站台本地時間儲存的裸字串（無時區資訊），
		// 這裡明確指定 wp_timezone() 解析，避免 PHP 預設時區（通常是 UTC）造成的偏移誤差。
		$start_dt = date_create( $booking['service_start'], wp_timezone() );
		$end_dt   = date_create( $booking['service_end'], wp_timezone() );

		if ( ! $start_dt || ! $end_dt ) {
			return '';
		}

		return wp_date( 'Y-m-d (D) H:i', $start_dt->getTimestamp() ) . '–' . wp_date( 'H:i', $end_dt->getTimestamp() );
	}
}
