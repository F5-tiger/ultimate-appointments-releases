<?php
/**
 * 「預約商品」WooCommerce 商品類型。
 *
 * v2.1.0 重新規劃：從 `WC_Product_Variable` 改為繼承 `WC_Product_Simple`。
 *
 * v2.0.0 當初繼承可變商品，是想直接沿用變化款當「服務方案」。實際上測試站後
 * 這個決定的代價比效益大：
 *
 * - 可變商品的價格在變化款上，所以 WooCommerce 核心刻意把「一般」分頁裡的價格
 *   區塊（`options_group pricing show_if_simple show_if_external`）藏起來，
 *   預約商品跟著看不到價格欄位。
 * - 變化款帶著庫存、SKU、屬性分類法一整套機制，對「同一項服務的不同時長方案」
 *   全部是雜訊，管理介面也繁瑣。
 *
 * 改用外掛自己的 `_uappt_plans`（見 UAPPT_Product）之後，這個類型就是一個
 * 「虛擬的簡單商品 + 服務方案 + 時段選擇」，繼承 WC_Product_Simple 最貼切。
 *
 * 幾件必須自己處理的事：
 *
 * 1. `get_type()` 回報 'uappt_booking'，給後台下拉選單、商品匯出、第三方外掛辨識。
 * 2. **不再覆寫 `is_type()`**。v2.0.0 額外承認 'variable' 是為了讓核心的變化款
 *    邏輯生效；查過核心原始碼確認：`WC_Form_Handler::add_to_cart_action()` 的
 *    handler 派發用的是 `get_type()`（`woocommerce_add_to_cart_handler` 篩選器的
 *    預設值）而不是 `is_type()`，未知型別會落到 `add_to_cart_handler_simple()`
 *    ——這正是方案制商品要的行為。
 * 3. 前台「加入購物車」區塊的模板是核心直接組出
 *    `do_action( 'woocommerce_' . $product->get_type() . '_add_to_cart' )` 來呼叫的，
 *    所以要把這個新 action 接到內建的 `woocommerce_simple_add_to_cart()`。
 * 4. **關掉 AJAX 加入購物車**。`WC_Product_Simple` 的建構子會把 'ajax_add_to_cart'
 *    加進 supports，商品列表頁的按鈕會直接把商品丟進購物車、跳過選時段那一步。
 *    必須覆寫 `supports()` 與 `add_to_cart_url()`，讓按鈕導回商品頁。
 * 5. 強制虛擬商品（不出貨）。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 商品物件類別：一個「虛擬的簡單商品」，價格顯示與加入購物車行為為預約服務調整過。
 */
class UAPPT_Product_Booking extends WC_Product_Simple {

	/**
	 * 回報真正的型別，給後台下拉選單、商品匯出、第三方外掛辨識用。
	 *
	 * @return string
	 */
	public function get_type() {
		return UAPPT_Product_Type::PRODUCT_TYPE;
	}

	/**
	 * 關掉 AJAX 加入購物車。
	 *
	 * 預約商品一定要先選方案與時段才能加入購物車，商品列表頁那顆「直接丟進購物車」
	 * 的 AJAX 按鈕會整個繞過這件事。父類別（WC_Product_Simple）在建構子裡把
	 * 'ajax_add_to_cart' 推進 $supports，所以只能在這裡攔下來。
	 *
	 * @param string $feature 功能名稱。
	 * @return bool
	 */
	public function supports( $feature ) {
		if ( 'ajax_add_to_cart' === $feature ) {
			return false;
		}
		return parent::supports( $feature );
	}

	/**
	 * 商品列表頁的按鈕一律導回商品頁（父類別會回傳 ?add-to-cart=ID 的直接加入網址）。
	 *
	 * @return string
	 */
	public function add_to_cart_url() {
		return apply_filters( 'woocommerce_product_add_to_cart_url', $this->get_permalink(), $this );
	}

	/**
	 * 列表頁按鈕文字：講清楚點下去是去選時段，不是直接買。
	 *
	 * @return string
	 */
	public function add_to_cart_text() {
		$text = UAPPT_Product::is_paused( $this->get_id() )
			? __( '暫停接受預約', 'ultimate-appointments' )
			: __( '選擇時段', 'ultimate-appointments' );

		return apply_filters( 'woocommerce_product_add_to_cart_text', $text, $this );
	}

	/**
	 * 暫停接受預約時，這個商品不可購買。
	 *
	 * 這樣一來 WooCommerce 自己就不會輸出「加入購物車」表單（單一商品模板會先問
	 * is_purchasable()），不必用 JS 去把按鈕藏掉。
	 *
	 * ⚠️ **v2.68.0 更正：這裡原本寫著「已經在購物車裡的項目不會被扯掉，核心只在
	 * 加入的當下檢查 is_purchasable()」——那是錯的，而且方向剛好相反。**
	 * `WC_Cart_Session::get_cart_from_session()` **每次載入購物車都會重驗一次**
	 * （WooCommerce 7.0 起還為此開了 `woocommerce_cart_item_is_purchasable`
	 * 篩選器），不通過就把項目丟掉、只印一句「已從購物車移除」。更糟的是那條
	 * 分支**不會**觸發 `woocommerce_cart_item_removed`，所以時段的暫留不會被
	 * 釋放，會一路卡到過期才由 cron 收掉。
	 *
	 * 實測過：購物車裡有一筆預約時按下暫停，重新載入購物車頁 → 購物車空了，
	 * 資料庫裡那筆仍是 held、expires_at 還沒到。
	 *
	 * 真正的修正在 UAPPT_Cart::keep_held_booking_purchasable()：客人已經握著的
	 * 暫留不受暫停影響（暫停要擋的是**新的**預約）。這一支維持原樣即可。
	 *
	 * @return bool
	 */
	public function is_purchasable() {
		if ( UAPPT_Product::is_paused( $this->get_id() ) ) {
			return false;
		}

		return parent::is_purchasable();
	}

	/**
	 * 撇開「暫停接受預約」不談，這個商品本身可不可以購買。
	 *
	 * 給 UAPPT_Cart::keep_held_booking_purchasable() 判斷用：它只想在
	 * 「不可購買**只是因為**暫停」時才放行，商品被改成草稿、沒有價格這類
	 * 真正的問題仍然要照核心的規則把項目移除。`parent::` 只能在類別內部呼叫，
	 * 所以開一個公開入口。
	 *
	 * @return bool
	 */
	public function is_purchasable_ignoring_pause() {
		return parent::is_purchasable();
	}

	/**
	 * 價格顯示：有兩個以上不同價格的方案時顯示價格區間。
	 *
	 * @param string $deprecated 沿用父類別簽章。
	 * @return string
	 */
	public function get_price_html( $deprecated = '' ) {
		// 已停用的方案不列入價格區間：客人選不到它，價格卻被它拉寬，區間會說謊。
		$prices = array();
		foreach ( UAPPT_Product::get_plans( $this->get_id(), true ) as $plan ) {
			if ( '' !== (string) $plan['price'] ) {
				$prices[] = (float) $plan['price'];
			}
		}

		if ( count( $prices ) < 2 ) {
			return parent::get_price_html( $deprecated );
		}

		$min = min( $prices );
		$max = max( $prices );

		if ( $min === $max ) {
			return parent::get_price_html( $deprecated );
		}

		$html = wc_format_price_range(
			wc_get_price_to_display( $this, array( 'price' => $min ) ),
			wc_get_price_to_display( $this, array( 'price' => $max ) )
		) . $this->get_price_suffix();

		return apply_filters( 'woocommerce_get_price_html', $html, $this );
	}

	/**
	 * 強制虛擬商品：預約商品不需要出貨，運費完全不介入。
	 * 同時覆寫 get_virtual()，不只 is_virtual()——避免直接讀 prop（例如 REST API
	 * 序列化、或直接查 _virtual meta 的第三方程式碼）繞過 is_virtual() 拿到錯誤值。
	 *
	 * @param string $context 讀取情境，沿用父類別簽章。
	 * @return bool
	 */
	public function get_virtual( $context = 'view' ) {
		return true;
	}

	/**
	 * @return bool
	 */
	public function is_virtual() {
		return true;
	}

	/**
	 * @return bool
	 */
	public function needs_shipping() {
		return false;
	}

	/**
	 * 預約商品不支援下載型商品的邏輯，固定回傳 false 避免混淆。
	 *
	 * @return bool
	 */
	public function is_downloadable() {
		return false;
	}
}

/**
 * 商品類型註冊、後台分頁相容補丁、一鍵轉換／還原。
 */
class UAPPT_Product_Type {

	const PRODUCT_TYPE = 'uappt_booking';

	/**
	 * @var UAPPT_Product_Type|null
	 */
	protected static $instance = null;

	/**
	 * 單例。
	 *
	 * @return UAPPT_Product_Type
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * 建構子：掛載 hooks。刻意不限制只在 is_admin() 才註冊——前台的加入購物車
	 * 派發、is_type() 相容性判斷在前台請求時也必須生效。
	 */
	protected function __construct() {
		add_filter( 'product_type_selector', array( $this, 'add_type_option' ) );
		add_filter( 'woocommerce_product_class', array( $this, 'map_product_class' ), 10, 2 );

		// 後台商品資料分頁的顯示／隱藏，是由核心的 JS 拿 #product-type 目前選的值
		// 去比對 show_if_{type} / hide_if_{type} class 決定的，跟 PHP 端的 is_type()
		// 完全是兩條獨立的機制。核心不認識我們這個新型別，所以要自己標記。
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'adjust_tabs_visibility' ), 100 );

		// 前台「加入購物車」模板不是靠 is_type() 派發，是核心直接呼叫
		// woocommerce_{$product->get_type()}_add_to_cart 這個 action；接到內建的
		// 簡單商品輸出函式（v2.1.0 起不再有變化款）。
		add_action( 'woocommerce_' . self::PRODUCT_TYPE . '_add_to_cart', 'woocommerce_simple_add_to_cart', 30 );

		// 存檔時強制這個類型的商品一定是「虛擬商品」，並維護 _uappt_enable_booking
		// 這個相容旗標（v2.1.0 起判斷一律走商品類型，這個 meta 只剩下讓舊查詢與
		// 一鍵還原的候選判斷還能運作的作用）。
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'enforce_flags_on_save' ), 20 );
		add_action( 'woocommerce_product_quick_edit_save', array( $this, 'enforce_flags_on_quick_edit' ), 20 );

		add_action( 'admin_footer', array( $this, 'print_admin_js' ) );

		add_action( 'admin_post_uappt_convert_to_booking_type', array( $this, 'handle_convert' ) );
		add_action( 'admin_post_uappt_revert_booking_type', array( $this, 'handle_revert' ) );
	}

	/**
	 * 在後台商品類型下拉選單加上「預約商品」選項。
	 *
	 * @param array $types 現有選項（type slug => 標籤）。
	 * @return array
	 */
	public function add_type_option( $types ) {
		$types[ self::PRODUCT_TYPE ] = __( '預約商品', 'ultimate-appointments' );
		return $types;
	}

	/**
	 * 讓 WooCommerce 商品工廠遇到這個型別時，實例化成我們的子類別。
	 *
	 * @param string $classname    原本要用的類別名稱。
	 * @param string $product_type 商品型別 slug。
	 * @return string
	 */
	public function map_product_class( $classname, $product_type ) {
		if ( self::PRODUCT_TYPE === $product_type ) {
			return 'UAPPT_Product_Booking';
		}
		return $classname;
	}

	/**
	 * 後台商品資料分頁：把對預約商品沒有意義的分頁藏起來。
	 *
	 * | 分頁 | 預約商品 | 理由 |
	 * | --- | --- | --- |
	 * | 庫存 | 隱藏 | 本外掛的模型是「人力即庫存」：可服務人數是由「人員在該時段是否
	 * |      |      | 空著」推導出來的，WooCommerce 的庫存計數會變成第二套互相衝突的
	 * |      |      | 事實來源，正是設計紀律 #2 禁止的數量模型。 |
	 * | 屬性 | 隱藏 | 規格改由「預約設定」的服務方案表達；拿掉變化款之後，屬性對這個
	 * |      |      | 型別只剩「額外資訊」的展示用途，分類需求用商品分類／標籤即可。 |
	 * | 變化類型 | 不必處理 | 它只有 show_if_variable，本來就不會出現。 |
	 * | 運送方式 | 不必處理 | 它是 hide_if_virtual，本型別強制虛擬商品，核心自己會藏。 |
	 * | 一般／連結商品／進階 | 保留 | 原生就都看得到，不要動它們。 |
	 *
	 * **要隱藏一律用 `hide_if_{型別}`，絕對不要反過來「不加 show_if_」**，理由是
	 * WooCommerce 的顯示演算法（assets/js/admin/meta-boxes-product.js 的
	 * show_and_hide_controls()）：
	 *
	 *   1. 先把「帶有任何 hide_if_{任一型別}」的元素全部**顯示**
	 *   2. 再把「帶有任何 show_if_{任一型別}」的元素全部**隱藏**
	 *   3. 只把 .show_if_{目前型別} 顯示回來
	 *   4. 只把 .hide_if_{目前型別} 隱藏起來
	 *
	 * 所以 `hide_if_uappt_booking` 只會在「目前正是預約商品」時生效（步驟 4），
	 * 對其他型別可證明零影響（步驟 1 會先把它顯示回來）。
	 *
	 * 反過來說，**`show_if_*` 的語意是「只有列出的型別看得到」**，不是「這個型別也
	 * 看得到」。對一個原本沒有 show_if_* 的分頁（＝所有型別都看得到）加上
	 * show_if_uappt_booking，等於把它改成只有預約商品看得到，其他型別上就直接消失
	 * ——v2.1.0 初版就是這樣把「一般」「連結商品」「進階」對簡單／可變商品弄不見的。
	 * 「不加 show_if_」對「庫存」這種本來就有條件的分頁剛好也能達到隱藏效果，但對
	 * 「屬性」這種無條件顯示的分頁完全無效，而且意圖不明確；統一用 hide_if_ 表達。
	 *
	 * @param array $tabs woocommerce_product_data_tabs 篩選器目前的分頁陣列。
	 * @return array
	 */
	public function adjust_tabs_visibility( $tabs ) {
		$hide_class = 'hide_if_' . self::PRODUCT_TYPE;

		foreach ( array( 'inventory', 'attribute' ) as $key ) {
			if ( ! isset( $tabs[ $key ] ) ) {
				continue;
			}

			$classes = isset( $tabs[ $key ]['class'] ) ? (array) $tabs[ $key ]['class'] : array();

			if ( in_array( $hide_class, $classes, true ) ) {
				continue;
			}

			$classes[]             = $hide_class;
			$tabs[ $key ]['class'] = $classes;
		}

		return $tabs;
	}

	/**
	 * 存檔時強制這個型別的商品維持「虛擬商品」「不管理庫存」「有庫存」。
	 *
	 * 庫存那兩項是配合「庫存」分頁被藏起來（見 adjust_tabs_visibility()）：管理者
	 * 沒有 UI 可以修正「不小心變成缺貨」的商品，所以要保證那些看不見的欄位不會
	 * 偷偷把商品變成不能買。可服務人數一律由人力推導，不經過 WooCommerce 庫存。
	 *
	 * **一定要用 setter 而不是 update_post_meta()**：這支掛在
	 * woocommerce_admin_process_product_object，它跑完之後 WooCommerce 才會呼叫
	 * $product->save() 把物件的 props 寫進 meta——先用 update_post_meta() 寫的值
	 * 會被那一步蓋掉。（_virtual 之前用 update_post_meta() 之所以沒出事，只是因為
	 * 我們覆寫了 get_virtual() 讓它永遠回傳 true，save() 剛好也寫成 yes；不要依賴
	 * 這種間接關係，一併改成 setter。）
	 *
	 * 注意這跟 _price 的處理方向剛好相反：_price 有「依 _regular_price/_sale_price
	 * 重算」的特殊邏輯會蓋掉 setter 設的值，所以那邊必須在存檔完成後直接寫 postmeta
	 * （見 UAPPT_Product::sync_price_after_save()）；manage_stock/stock_status 則是
	 * 普通 prop，setter 就是正確做法。
	 *
	 * @param WC_Product $product 正在儲存的商品物件。
	 */
	public function enforce_flags_on_save( $product ) {
		if ( ! $product instanceof WC_Product || self::PRODUCT_TYPE !== $product->get_type() ) {
			return;
		}

		$product->set_virtual( true );
		$product->set_manage_stock( false );
		$product->set_stock_status( 'instock' );
		$product->update_meta_data( '_uappt_enable_booking', 'yes' );
	}

	/**
	 * 商品列表的「快速編輯」不會觸發 woocommerce_admin_process_product_object，
	 * 那條路徑仍然能把預約商品設成缺貨，所以同一組強制值要在這裡再套一次。
	 *
	 * @param WC_Product $product 正在儲存的商品物件。
	 */
	public function enforce_flags_on_quick_edit( $product ) {
		if ( ! $product instanceof WC_Product || self::PRODUCT_TYPE !== $product->get_type() ) {
			return;
		}

		$product->set_manage_stock( false );
		$product->set_stock_status( 'instock' );
		$product->save();
	}

	/**
	 * 後台商品編輯頁的兩個相容補丁，都只能在 JS 端做（核心沒有對應的 PHP 篩選器）。
	 *
	 * 1. **把「一般」分頁的價格區塊顯示出來**。核心模板寫死
	 *    `<div class="options_group pricing show_if_simple show_if_external hidden">`
	 *    （html-product-data-general.php），而 show_and_hide_controls() 會先把所有
	 *    `.show_if_*` 藏起來、再只顯示目前型別的那些。分頁本身是 hide_if_grouped
	 *    所以看得到，但裡面空無一物——這就是 v2.0.0「價格欄位不見」的真正原因，
	 *    不是分頁消失。這裡替那個區塊補上 show_if_uappt_booking 再讓核心重跑一次。
	 * 2. 選這個類型時把「虛擬商品」勾選並鎖住（唯讀）：取消也沒有意義，
	 *    get_virtual() 永遠回傳 true。
	 *
	 * 只在商品編輯頁輸出，避免影響其他頁面。
	 */
	public function print_admin_js() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'product' !== $screen->id ) {
			return;
		}
		?>
		<script type="text/javascript">
		jQuery( function ( $ ) {
			var UAPPT_BOOKING_TYPE = <?php echo wp_json_encode( self::PRODUCT_TYPE ); ?>;

			function uapptSyncVirtualCheckbox() {
				var $virtual = $( '#_virtual' );
				if ( ! $virtual.length ) {
					return;
				}
				var isBookingType = $( '#product-type' ).val() === UAPPT_BOOKING_TYPE;
				if ( isBookingType && ! $virtual.is( ':checked' ) ) {
					$virtual.prop( 'checked', true ).trigger( 'change' );
				}
				$virtual.prop( 'disabled', isBookingType );
			}

			// 規則：「預約商品在『一般』分頁比照簡單商品」。
			//
			// 凡是遵循 WooCommerce 慣例、用 show_if_simple 標示「簡單商品看得到」的
			// 區塊，預約商品一律跟著看得到。這樣寫而不是逐一寫死選擇器，是因為要
			// 涵蓋的東西不只核心的價格：還有核心的稅務欄位（wc_tax_enabled() 時才
			// 輸出），以及終極電商（twshop）掛在 woocommerce_product_options_general_product_data
			// 的折扣徽章設定——後者是站台自己的外掛，寫死它的 class 等於把耦合帶進來，
			// 日後任何外掛照慣例加欄位也都要再改一次這裡。
			//
			// 這些區塊本來就帶著 show_if_*（＝已經是「只有列出的型別看得到」），
			// 再多掛一個 show_if_uappt_booking 不會改變其他型別的行為——簡單商品仍會
			// 被它自己的 .show_if_simple 顯示回來。
			// （反過來說，對「本來沒有任何 show_if_*」的元素這樣做會害它對其他型別
			// 整個消失，理由見 adjust_tabs_visibility() 的說明。）
			//
			// 只挑 show_if_simple 而不是所有 show_if_*，才不會誤放行「外部商品網址」
			// （只有 show_if_external）與「可下載檔案」（只有 show_if_downloadable）。
			$( '#general_product_data .options_group.show_if_simple' )
				.not( '.show_if_' + UAPPT_BOOKING_TYPE )
				.addClass( 'show_if_' + UAPPT_BOOKING_TYPE );

			// 只有目前就是預約商品時才需要補觸發一次重算：WooCommerce 自己的
			// document ready 早於這支行內腳本，剛才加的 class 沒被算進去。其他
			// 商品類型不需要、也不該被我們多觸發一次事件，維持原生行為。
			if ( $( '#product-type' ).val() === UAPPT_BOOKING_TYPE ) {
				$( 'select#product-type' ).trigger( 'change' );
			}

			$( document.body ).on( 'woocommerce-product-type-change', uapptSyncVirtualCheckbox );
			uapptSyncVirtualCheckbox();
		} );
		</script>
		<?php
	}

	/**
	 * 找出「已勾選啟用預約、且目前是簡單或可變商品」的商品 ID——這些是可以
	 * 安全轉換成「預約商品」類型的候選（分組/外部商品沒有對應的預約邏輯，
	 * 轉了也沒有意義，不列入）。
	 *
	 * @return int[]
	 */
	public static function get_convertible_product_ids() {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'posts_per_page' => -1,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'meta_key'       => '_uappt_enable_booking', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => 'yes', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		$filtered = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product || self::PRODUCT_TYPE === $product->get_type() ) {
				continue;
			}
			if ( $product->is_type( 'simple' ) || $product->is_type( 'variable' ) ) {
				$filtered[] = (int) $id;
			}
		}
		return $filtered;
	}

	/**
	 * 找出目前是「預約商品」類型的商品 ID（一鍵還原的候選）。
	 *
	 * @return int[]
	 */
	public static function get_converted_product_ids() {
		$ids = get_posts(
			array(
				'post_type'      => 'product',
				'posts_per_page' => -1,
				'post_status'    => 'any',
				'fields'         => 'ids',
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => 'product_type',
						'field'    => 'slug',
						'terms'    => self::PRODUCT_TYPE,
					),
				),
			)
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * 一鍵轉換：把目前已勾選「啟用預約」的簡單/可變商品全部轉成「預約商品」
	 * 類型。只變更 product_type 分類（商品類型本身），並記下轉換前的原始
	 * 類型供還原用；完全不動訂單、預約紀錄、商品的其他資料。
	 */
	public function handle_convert() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}
		check_admin_referer( 'uappt_convert_to_booking_type' );

		$converted = 0;
		foreach ( self::get_convertible_product_ids() as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}
			update_post_meta( $product_id, '_uappt_product_type_backup', $product->get_type() );
			wp_set_object_terms( $product_id, self::PRODUCT_TYPE, 'product_type' );
			update_post_meta( $product_id, '_virtual', 'yes' );
			update_post_meta( $product_id, '_uappt_enable_booking', 'yes' );
			++$converted;
		}

		$this->redirect_to_settings(
			array(
				'uappt_notice' => 'booking_type_converted',
				'uappt_count'  => $converted,
			)
		);
	}

	/**
	 * 一鍵還原：把「預約商品」類型轉回轉換前記錄的原始類型；找不到記錄
	 * （例如商品是直接以預約商品建立的）則還原為簡單商品。
	 *
	 * 服務方案（_uappt_plans）與預約紀錄都不會被刪除，隨時可以再轉回來。
	 * 注意：v2.0.0 遺留的變化款 post 在遷移時刻意保留著沒刪，所以還原成可變商品
	 * 時那些變化款會再度出現——但它們的價格與屬性可能已經和方案不同步了。
	 */
	public function handle_revert() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( '權限不足。', 'ultimate-appointments' ) );
		}
		check_admin_referer( 'uappt_revert_booking_type' );

		$reverted = 0;
		foreach ( self::get_converted_product_ids() as $product_id ) {
			$original_type = get_post_meta( $product_id, '_uappt_product_type_backup', true );
			if ( ! in_array( $original_type, array( 'simple', 'variable' ), true ) ) {
				$original_type = 'simple';
			}
			wp_set_object_terms( $product_id, $original_type, 'product_type' );
			delete_post_meta( $product_id, '_uappt_product_type_backup' );
			++$reverted;
		}

		$this->redirect_to_settings(
			array(
				'uappt_notice' => 'booking_type_reverted',
				'uappt_count'  => $reverted,
			)
		);
	}

	/**
	 * 轉址回設定頁並附上查詢參數。
	 *
	 * @param array $args 查詢參數。
	 */
	protected function redirect_to_settings( $args ) {
		// 這兩個動作的按鈕都在設定頁的「進階工具」頁籤，轉址回去要停在同一個
		// 頁籤，不然看不到剛剛執行完的清單變成什麼樣子。
		$url = add_query_arg(
			array_merge(
				array(
					'page' => UAPPT_Admin::PAGE_SLUG,
					'section' => 'settings',
					'tab'  => 'tools',
				),
				$args
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}
}
