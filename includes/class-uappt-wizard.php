<?php
/**
 * 前台預約精靈：shortcode `[uappt_booking]`。
 *
 * 步驟式的預約介面（選人 → 選項目 → 選時間 → 確認），設計與決策見
 * docs/booking-wizard-plan.md。跟商品頁那套內嵌表單完全獨立：自己的 CSS／JS、
 * 自己的 class 前綴（`.uappt-wiz-*`），資料一律走 `/wp-json/uappt/v1/` 的唯讀
 * REST 端點。
 *
 * **刻意不共用商品頁的 frontend.css／frontend.js**：那兩支的載入條件第一行就是
 * `is_product()`，而精靈可能出現在任何頁面。與其把那段載入邏輯拆開改寫（動到
 * 正在運作的商品頁），不如讓精靈自帶資產——反正兩邊的 UI 本來就不一樣，共用
 * 只會讓改其中一邊時要擔心另一邊。
 *
 * 送出預約走 WooCommerce 原生的加入購物車表單（不是 REST），所以
 * `UAPPT_Cart::validate_and_hold()` 的守門邏輯完全沿用，見計畫文件的路線 B。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UAPPT_Wizard {

	const SHORTCODE = 'uappt_booking';

	const STYLE_HANDLE  = 'uappt-wizard';
	const SCRIPT_HANDLE = 'uappt-wizard';

	/**
	 * 這次請求有沒有真的輸出過精靈。
	 *
	 * 用來決定 render 當下要不要補一次 enqueue（見 render() 的說明）。
	 *
	 * @var bool
	 */
	protected static $rendered = false;

	/**
	 * 掛上 hooks。
	 */
	public static function init() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
	}

	/**
	 * 註冊資產（只註冊，不載入）。
	 *
	 * 分成「註冊」與「載入」兩步，是為了讓 render() 在 the_content 階段也能
	 * 補一次 `wp_enqueue_*`——那時候才註冊就來不及設定相依關係了。
	 */
	public static function register_assets() {
		if ( wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
			return;
		}

		wp_register_style( self::STYLE_HANDLE, UAPPT_PLUGIN_URL . 'assets/css/wizard.css', array(), UAPPT_VERSION );
		wp_register_script( self::SCRIPT_HANDLE, UAPPT_PLUGIN_URL . 'assets/js/wizard.js', array(), UAPPT_VERSION, true );

		wp_localize_script(
			self::SCRIPT_HANDLE,
			'UAPPT_WizardConfig',
			array(
				// REST 走的是公開的唯讀端點，不需要 nonce；帶上 wp_rest nonce 只是
				// 讓已登入的客人在請求裡保有身分（例如「上次為您服務」的判斷）。
				'rest'        => esc_url_raw( rest_url( UAPPT_Rest::NAMESPACE_V1 . '/' ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				// 送出用的欄位。表單 action 由 JS 用 window.location.href 當場決定：
				// 貼著目前網址送出，驗證失敗時客人會留在精靈這一頁（而且
				// ?staff=3 這類深連結參數也會保留），不會被丟到別的頁面。
				'sourceField' => UAPPT_Cart::SOURCE_FIELD,
				'sourceValue' => UAPPT_Cart::SOURCE_WIZARD,
				'addNonce'    => wp_create_nonce( 'uappt_add_booking' ),
				// JS 要用它組出 Elementor 的 frontend/element_ready hook 名稱。
				// 從這裡傳而不是在 JS 裡寫死，兩邊才不會各改各的。
				'elementorWidget' => class_exists( 'UAPPT_Elementor' ) ? UAPPT_Elementor::WIDGET_BOOKING : self::SHORTCODE,
				'weekdays' => array(
					__( '日', 'ultimate-appointments' ),
					__( '一', 'ultimate-appointments' ),
					__( '二', 'ultimate-appointments' ),
					__( '三', 'ultimate-appointments' ),
					__( '四', 'ultimate-appointments' ),
					__( '五', 'ultimate-appointments' ),
					__( '六', 'ultimate-appointments' ),
				),
				'i18n'     => array(
					'loading'        => __( '載入中…', 'ultimate-appointments' ),
					'error'          => __( '載入失敗，請稍後再試。', 'ultimate-appointments' ),
					'stepStaff'      => __( '選擇服務人員', 'ultimate-appointments' ),
					'stepService'    => __( '選擇服務項目', 'ultimate-appointments' ),
					'stepTime'       => __( '選擇時間', 'ultimate-appointments' ),
					'stepConfirm'    => __( '確認預約', 'ultimate-appointments' ),
					'anyStaff'       => __( '不指定', 'ultimate-appointments' ),
					'anyStaffHint'   => __( '由店家安排', 'ultimate-appointments' ),
					'lastStaff'      => __( '上次為您服務', 'ultimate-appointments' ),
					'noStaff'        => __( '目前沒有可預約的服務人員。', 'ultimate-appointments' ),
					'noService'      => __( '這位人員目前沒有可預約的服務項目。', 'ultimate-appointments' ),
					'noSlots'        => __( '這天已經約滿了，請換一天。', 'ultimate-appointments' ),
					'pickDate'       => __( '請先選擇日期', 'ultimate-appointments' ),
					'back'           => __( '上一步', 'ultimate-appointments' ),
					'prevMonth'      => __( '上個月', 'ultimate-appointments' ),
					'nextMonth'      => __( '下個月', 'ultimate-appointments' ),
					'minutes'        => __( '分鐘', 'ultimate-appointments' ),
					'staffSurcharge' => __( '指定加價', 'ultimate-appointments' ),
					'confirm'        => __( '確認預約', 'ultimate-appointments' ),
					'submitting'     => __( '處理中…', 'ultimate-appointments' ),
					'summaryStaff'   => __( '服務人員', 'ultimate-appointments' ),
					'summaryService' => __( '服務項目', 'ultimate-appointments' ),
					'summaryTime'    => __( '預約時間', 'ultimate-appointments' ),
					'total'          => __( '合計', 'ultimate-appointments' ),
					// 這一行要跟著「送出後去哪一頁」的設定走，不然客人會被騙一次。
					// 走購物車時順便把好處講出來——多出來的那一步才會被當成
					// 「可以折點數」而不是「又要多按一次」。
					'nextNote'       => 'checkout' === UAPPT_Cart::after_add_destination()
						? __( '下一步將前往結帳頁完成預約。', 'ultimate-appointments' )
						: __( '下一步將前往購物車，可使用優惠券與點數折抵。', 'ultimate-appointments' ),
				),
			)
		);
	}

	/**
	 * 頁面內容裡有 shortcode 時，在 wp_enqueue_scripts 階段就載入資產。
	 *
	 * 為什麼不只靠 render() 當下載入：shortcode 是在 the_content 展開的，那時候
	 * `wp_head` 已經輸出完畢，樣式會被擠到頁尾才印出來，畫面會先閃一下沒有樣式
	 * 的版本。所以能提早判斷就提早載。
	 *
	 * ⚠️ Elementor 頁面用這招判斷不到（它的 post_content 存的是渲染後的 HTML，
	 * `has_shortcode()` 永遠是 false），那要靠階段 4 的 widget 自己掃
	 * `_elementor_data`。render() 裡的補救 enqueue 就是給這種情況兜底的。
	 */
	public static function maybe_enqueue() {
		self::register_assets();

		$post = get_post();
		if ( $post instanceof WP_Post && has_shortcode( (string) $post->post_content, self::SHORTCODE ) ) {
			self::enqueue();
			return;
		}

		// Elementor 頁面的 post_content 是渲染後的 HTML，has_shortcode() 永遠
		// 是 false，要另外掃 _elementor_data 才知道這頁有沒有精靈 widget。
		if ( class_exists( 'UAPPT_Elementor' ) && UAPPT_Elementor::page_has_widget() ) {
			self::enqueue();
			return;
		}

		// 商品頁設定成「精靈」模式時，精靈是由 UAPPT_Frontend 在模板階段才印出來
		// 的，那時候 wp_head 已經結束。這裡提前載好，避免閃一下沒有樣式的版本。
		if ( function_exists( 'is_product' ) && is_product()
			&& 'wizard' === UAPPT_Product::product_page_mode()
			&& UAPPT_Product::booking_enabled( get_queried_object_id() )
		) {
			self::enqueue();
		}
	}

	/**
	 * 載入資產。
	 */
	public static function enqueue() {
		self::register_assets();
		wp_enqueue_style( self::STYLE_HANDLE );
		wp_enqueue_script( self::SCRIPT_HANDLE );
	}

	/**
	 * 找出站上已經放了預約精靈的頁面。
	 *
	 * 設定頁的「前台預約介面」區塊用它列出「你把精靈放在哪幾頁」——不然管理者
	 * 過幾個月就會忘記，只能一頁一頁翻。
	 *
	 * 兩種放法都要找：post_content 裡的 shortcode，以及 Elementor 存在
	 * `_elementor_data` 的 widget（Elementor 頁面的 post_content 是渲染後的
	 * HTML，搜尋 shortcode 找不到，見 UAPPT_Elementor::page_has_widget()）。
	 *
	 * 用 LIKE 掃兩個欄位不算便宜，但這只在後台設定頁跑一次，不值得為它另外
	 * 維護一份索引。
	 *
	 * @param int $limit 最多回傳幾筆。
	 * @return array 每個元素：['id'=>int,'title'=>string,'url'=>string,'via'=>'shortcode'|'elementor']
	 */
	public static function find_pages( $limit = 20 ) {
		global $wpdb;

		$like_shortcode = '%' . $wpdb->esc_like( '[' . self::SHORTCODE ) . '%';
		$like_widget    = '%' . $wpdb->esc_like( self::SHORTCODE ) . '%';

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT p.ID, p.post_title,
						MAX( CASE WHEN p.post_content LIKE %s THEN 1 ELSE 0 END ) AS via_shortcode
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_elementor_data'
				 WHERE p.post_status = 'publish'
				   AND p.post_type NOT IN ( 'revision', 'attachment' )
				   AND ( p.post_content LIKE %s OR m.meta_value LIKE %s )
				 GROUP BY p.ID, p.post_title
				 ORDER BY p.post_title ASC
				 LIMIT %d",
				$like_shortcode,
				$like_shortcode,
				$like_widget,
				max( 1, (int) $limit )
			),
			ARRAY_A
		);

		$pages = array();
		foreach ( (array) $rows as $row ) {
			$pages[] = array(
				'id'    => (int) $row['ID'],
				'title' => $row['post_title'] ? $row['post_title'] : __( '（無標題）', 'ultimate-appointments' ),
				'url'   => get_permalink( (int) $row['ID'] ),
				'via'   => ! empty( $row['via_shortcode'] ) ? 'shortcode' : 'elementor',
			);
		}

		return $pages;
	}

	/**
	 * 現在這個請求能不能安全地呼叫 WooCommerce 的通知函式。
	 *
	 * ⚠️ **`function_exists( 'wc_notice_count' )` 不夠。** 那支內部是
	 * `WC()->session->get(…)`，而 WooCommerce 只在前台請求初始化 session——
	 * 後台、REST、以及 Elementor 為了組編輯器設定所做的伺服器端渲染，
	 * `WC()->session` 都是 null，函式存在但一呼叫就 fatal。
	 *
	 * 實際踩過：把精靈 widget 放進 Elementor 頁面後，點「使用 Elementor 編輯」
	 * 或在後台編輯那一頁，整個後台頁面變成「這個網站發生嚴重錯誤」。
	 * Elementor 會在 wp_head 階段呼叫 widget 的 render() 來取得 raw data
	 * （get_raw_data → render_content → render），那一條路完全不在前台情境裡。
	 *
	 * @return bool
	 */
	protected static function can_print_notices() {
		return ! is_admin()
			&& function_exists( 'wc_notice_count' )
			&& function_exists( 'WC' )
			&& WC()->session;
	}

	/**
	 * 輸出精靈容器。
	 *
	 * 實際的介面由 assets/js/wizard.js 掛進來，這裡只負責產生容器與設定值——
	 * 一頁可以放兩個精靈（例如限定不同人員），所以設定值走 data-* 屬性掛在
	 * 各自的容器上，不是塞進全域的 localize 物件。
	 *
	 * @param array $atts shortcode 屬性。
	 * @return string
	 */
	public static function render( $atts ) {
		$atts = shortcode_atts(
			array(
				// staff_first：先選人再選項目（客人習慣指定設計師的情境）
				// service_first：先選項目再選人
				'mode'    => 'staff_first',
				// 鎖定某位人員／某個服務，鎖定的那一步會直接跳過。
				'staff'   => 0,
				'service' => 0,
				'plan'    => '',
				// 限定單一商品但不鎖死方案：商品頁用精靈模式時用的。
				// 那個商品只有一個方案時，JS 會自動當成鎖定並跳過選項目那步；
				// 有兩個以上就保留那一步，只是清單裡只有這個商品的方案。
				'product' => 0,
			),
			$atts,
			self::SHORTCODE
		);

		// 網址參數可以覆蓋 shortcode 設定，讓「專屬預約連結」成立：
		// 設計師把 /預約?staff=3 放在 IG 簡介或 LINE 名片，客人點進來就已經
		// 選好人，直接從選項目開始。
		$staff   = isset( $_GET['staff'] ) ? absint( $_GET['staff'] ) : absint( $atts['staff'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$service = isset( $_GET['service'] ) ? absint( $_GET['service'] ) : absint( $atts['service'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$plan    = isset( $_GET['plan'] ) ? sanitize_key( wp_unslash( $_GET['plan'] ) ) : sanitize_key( (string) $atts['plan'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// ⚠️ 網址參數用 `uappt_product` 而不是 `product`：`product` 是 WooCommerce
		// 註冊商品文章類型時佔用的查詢變數，帶在任何頁面網址上都會讓 WordPress
		// 改去找「slug 等於這個值」的商品，整頁變成 404（實測過）。
		// shortcode 屬性維持叫 `product`，那個不受查詢變數影響。
		$product = isset( $_GET['uappt_product'] ) ? absint( $_GET['uappt_product'] ) : absint( $atts['product'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$mode = ( 'service_first' === $atts['mode'] ) ? 'service_first' : 'staff_first';

		self::enqueue();
		self::$rendered = true;

		ob_start();

		// 加入購物車失敗（例如時段剛被別人搶走）時，WooCommerce 不會轉址，客人
		// 會留在這一頁——但一般頁面沒有佈景主題的通知輸出點，錯誤訊息會完全
		// 不見，客人只會覺得「按了沒反應」。所以自己印一次。
		// 成功的情況不會走到這裡（已經轉去結帳頁了）。
		if ( self::can_print_notices() && wc_notice_count() > 0 ) {
			echo '<div class="uappt-wiz-notices">';
			wc_print_notices();
			echo '</div>';
		}
		?>
		<div class="uappt-wiz"
			data-mode="<?php echo esc_attr( $mode ); ?>"
			data-staff="<?php echo esc_attr( $staff ); ?>"
			data-service="<?php echo esc_attr( $service ); ?>"
			data-plan="<?php echo esc_attr( $plan ); ?>"
			data-product="<?php echo esc_attr( $product ); ?>">
			<noscript>
				<p class="uappt-wiz-noscript">
					<?php esc_html_e( '線上預約需要開啟 JavaScript，請開啟後重新整理，或直接聯繫我們。', 'ultimate-appointments' ); ?>
				</p>
			</noscript>
			<div class="uappt-wiz-boot"><?php esc_html_e( '載入中…', 'ultimate-appointments' ); ?></div>
		</div>
		<?php
		return (string) ob_get_clean();
	}
}
