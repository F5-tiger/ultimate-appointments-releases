<?php
/**
 * 商品端擴充：「預約商品」類型專屬的「預約設定」商品資料分頁。
 *
 * v2.1.0 的重新規劃（v2.0.0 上測試站後的檢討結果）：
 *
 * 1. **不再沿用 WooCommerce 變化款**。變化款是為「同一件商品的不同規格」設計的，
 *    它帶著庫存、SKU、屬性分類法一整套東西，對「同一項服務的不同時長方案」而言
 *    全部都是雜訊，而且逼得商品類型必須繼承 WC_Product_Variable，連帶把「一般」
 *    分頁的價格欄位一起弄不見。改成外掛自己的 `_uappt_plans` 陣列後，方案就是
 *    「名稱／時長／價格」三件事，管理介面與資料結構都對得上實際的業務語意。
 * 2. **預約設定只在「預約商品」類型出現**。簡單／可變商品完全回復原狀，不再有
 *    預約分頁，也不再有「此商品需要預約時段」勾選框——商品類型本身就是開關。
 * 3. **可服務人員改用 wc-enhanced-select**。原本一堆 `<label>` 會被 WooCommerce 的
 *    `.form-field label { float:left; width:150px }` 全部推到同一欄疊在一起。
 *
 * 設定的繼承規則：方案的「時長／緩衝／可服務人員」留空即代表沿用商品層級設定。
 * 這讓「大部分方案共用同一批人員、只有進階方案限定資深人員」這種常見情境不必
 * 在每個方案重複勾一次。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UAPPT_Product {

	/**
	 * 服務方案陣列所在的 meta key。
	 */
	const PLANS_META = '_uappt_plans';

	/** 前台時段篩選頁籤的分類設定（全站一份，見 segment_config()）。 */
	const SEGMENTS_OPTION = 'uappt_time_segments';

	/**
	 * @var UAPPT_Product|null
	 */
	protected static $instance = null;

	/**
	 * 本次存檔中因為「沒填方案名稱」而被丟棄的列數。
	 *
	 * 靜態的原因：清洗流程是靜態方法，而通知要在稍後的 redirect_post_location
	 * 才發得出去，兩者之間需要一個能跨呼叫的暫存位置。單次請求內只會存一個商品，
	 * 不會互相污染。
	 *
	 * @var int
	 */
	protected static $dropped_plan_rows = 0;

	/**
	 * 本次存檔的預約商品 ID；0 代表這次存的不是預約商品（不發預約相關通知）。
	 *
	 * @var int
	 */
	protected static $saved_product_id = 0;

	/**
	 * 單例。
	 *
	 * @return UAPPT_Product
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * 建構子：掛載 hooks。
	 *
	 * v2.1.0 起不再有變化款層級的 hook（woocommerce_variation_options_pricing /
	 * woocommerce_save_product_variation），方案完全由本分頁管理。
	 */
	protected function __construct() {
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_product_data_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_product_data_panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_product_fields' ) );

		// _price 要在商品真正 save() 完成之後再直接改 postmeta，不能在
		// woocommerce_admin_process_product_object 裡對商品物件呼叫 set_price()。
		// 那個 hook 執行完之後，WooCommerce 自己的存檔流程還會依 _regular_price /
		// _sale_price「是否本次有變動」重新算一次 _price 蓋掉它（見
		// WC_Product_Data_Store_CPT，只要這兩個欄位本次沒被使用者改過，這段甚至
		// 不會執行，我們設的值就直接消失，行為飄忽又難以察覺）。改成比照
		// WooCommerce 自己對可變商品的做法（WC_Product_Variable_Data_Store_CPT::
		// sync_price()）：存檔完成後直接 update_post_meta()，徹底繞開那套機制。
		add_action( 'woocommerce_process_product_meta_' . UAPPT_Product_Type::PRODUCT_TYPE, array( $this, 'sync_price_after_save' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

		// 存檔時發現的問題（方案沒填名稱被丟掉、沒有可服務人員）要讓管理者看到。
		// 商品編輯頁存完會 redirect，訊息只能靠網址參數帶過去——這是 WordPress 對
		// post 編輯畫面唯一可靠的通知管道（transient 會在多分頁同時編輯時串台）。
		add_filter( 'redirect_post_location', array( $this, 'add_save_notice_args' ), 10, 2 );
	}

	/**
	 * 載入商品編輯頁需要的 JS/CSS。
	 *
	 * 依賴 wc-enhanced-select（可服務人員的多選）與 woocommerce_admin_styles
	 * （select2 的樣式）。WooCommerce 在商品編輯頁通常已經載入這兩者，這裡明確
	 * 宣告依賴，不倚賴那個「通常」。
	 *
	 * @param string $hook 目前後台頁面 hook。
	 */
	public function enqueue_admin_assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_style( 'uappt-admin', UAPPT_PLUGIN_URL . 'assets/css/admin.css', array(), UAPPT_VERSION );

		wp_enqueue_script(
			'uappt-admin-product',
			UAPPT_PLUGIN_URL . 'assets/js/admin-product.js',
			array( 'jquery', 'wc-enhanced-select', 'jquery-ui-sortable' ),
			UAPPT_VERSION,
			true
		);

		wp_localize_script(
			'uappt-admin-product',
			'UAPPT_AdminProduct',
			array(
				'confirm_remove_plan' => __( '確定要刪除這個方案嗎？已經用這個方案建立的預約不會被刪除，但之後就無法用它改期了。若只是暫時停售，請改用「進階」裡的「暫停銷售此方案」。', 'ultimate-appointments' ),
				'name_required'       => __( '這個方案還沒填名稱。沒有名稱的方案不會被儲存，請補上或直接刪除這一列。', 'ultimate-appointments' ),
				'inherit_prefix'      => __( '沿用', 'ultimate-appointments' ),
				'staff_required'      => __( '請至少選擇一位可服務的人員，否則這項服務在前台完全無法預約。', 'ultimate-appointments' ),
				// 送出前的驗證只能對「預約商品」生效，JS 要拿這個值跟 #product-type
				// 比對。寫死在 JS 裡的話，兩邊遲早會對不上。
				'product_type'        => UAPPT_Product_Type::PRODUCT_TYPE,
			)
		);
	}

	/**
	 * 在商品資料區塊加入「預約設定」分頁。
	 *
	 * class 只掛 show_if_uappt_booking：其他商品類型不該再看到預約設定（v2.0.0 的
	 * 「任何商品都能勾啟用預約」造成兩套並行的設定來源，維護成本遠高於效益）。
	 *
	 * @param array $tabs 現有分頁。
	 * @return array
	 */
	public function add_product_data_tab( $tabs ) {
		$tabs['uappt_booking'] = array(
			'label'    => __( '預約設定', 'ultimate-appointments' ),
			'target'   => 'uappt_booking_product_data',
			'class'    => array( 'show_if_' . UAPPT_Product_Type::PRODUCT_TYPE ),
			'priority' => 65,
		);
		return $tabs;
	}

	/**
	 * 輸出「預約設定」分頁內容。
	 *
	 * 區塊順序刻意是「狀態 → 方案 → 預設值 → 政策 → 暫停」：管理者最常回來改的是
	 * 方案的價格與名稱，最少動的是暫停開關。暫停雖然被排到最底下，但它的狀態會在
	 * 最上面的狀態列報出來（並附一個跳過去的連結），不會因為位置低就被忽略。
	 */
	public function render_product_data_panel() {
		global $post;

		$product_id = $post ? (int) $post->ID : 0;

		$duration      = get_post_meta( $product_id, '_uappt_duration_minutes', true );
		$buffer_before = get_post_meta( $product_id, '_uappt_buffer_before', true );
		$buffer_after  = get_post_meta( $product_id, '_uappt_buffer_after', true );
		$horizon       = get_post_meta( $product_id, '_uappt_horizon_days', true );
		$lead          = get_post_meta( $product_id, '_uappt_min_lead_minutes', true );
		$staff_ids     = self::get_staff_ids_meta( $product_id );
		$plans         = self::get_plans( $product_id );

		// 方案列的 placeholder 要顯示「沿用 60」而不是光一個「沿用」，所以先把商品
		// 層級的有效預設值算出來（跟儲存時的預設值一致）。
		$defaults = array(
			'duration'      => '' !== (string) $duration ? (int) $duration : 60,
			'buffer_before' => '' !== (string) $buffer_before ? (int) $buffer_before : 0,
			'buffer_after'  => '' !== (string) $buffer_after ? (int) $buffer_after : 0,
		);

		echo '<div id="uappt_booking_product_data" class="panel woocommerce_options_panel hidden">';

		self::render_status_bar( $product_id, $plans, $staff_ids );

		// --- 服務方案 ---
		echo '<div class="options_group uappt-plans-group">';
		echo '<p class="form-field"><strong>' . esc_html__( '服務方案', 'ultimate-appointments' ) . '</strong></p>';

		// 說明收成一段：其餘規則改成欄位就地提示（placeholder、欄位下方小字），
		// 出現在真正需要的位置，而不是全部堆在開頭讓人先讀一大段才看得到表單。
		echo '<div class="uappt-plans-intro">';
		echo '<p class="description">' .
			wp_kses_post(
				__( '同一項服務的不同選擇（例如 60 分鐘 / 90 分鐘），客人在商品頁先選方案再選時段。<strong>有方案時，商品售價由方案決定</strong>；沒有任何方案時才使用「一般」分頁的價格。', 'ultimate-appointments' )
			) .
			'</p>';
		echo '</div>';

		self::render_consumable_summary( $product_id, $plans );

		// 空狀態放在表格「之前」：它講的是「這張表為什麼是空的」，排在表格後面等於
		// 要管理者先看完一張空表再回頭找解釋。
		echo '<p class="form-field uappt-plans-empty"' . ( $plans ? ' style="display:none;"' : '' ) . '><span class="description">' .
			esc_html__( '尚未建立方案。未建立方案時，本商品是單一服務：售價用「一般」分頁的價格、時長用下方的預設服務設定。', 'ultimate-appointments' ) .
			'</span></p>';

		// **一方案一張卡片，不再用表格。**
		// 舊版是六欄的 <table> 加 min-width:760px + 外層水平捲動。實測商品資料
		// metabox 只有 585px 寬、表格可見區更只有 442px，等於永遠有 318px 被藏在
		// 捲軸後面（「可服務人員」欄要捲才看得到），而「進階」展開層被塞進最窄的
		// 名稱欄裡只剩 185px。卡片式讓每個欄位都拿得到整個 metabox 的寬度，窄
		// 版面下也不需要任何捲動——跟 WooCommerce 自己的「變化款」介面同一個做法。
		echo '<div class="uappt-plans-rows">';

		foreach ( $plans as $index => $plan ) {
			echo self::render_plan_row( (string) $index, $plan, $defaults ); // phpcs:ignore WordPress.Security.EscapeOutput
		}

		echo '</div>';

		echo '<p class="form-field"><button type="button" class="button uappt-add-plan">' .
			esc_html__( '新增方案', 'ultimate-appointments' ) . '</button></p>';

		// 範本：放在 <script type="text/template"> 裡才不會被表單一起送出。
		echo '<script type="text/template" id="uappt-plan-row-template">';
		echo self::render_plan_row( '__INDEX__', array(), $defaults ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</script>';

		echo '</div>';

		// --- 預設服務設定（方案留空時沿用這裡）---
		echo '<div class="options_group">';
		echo '<p class="form-field"><strong>' . esc_html__( '預設服務設定（方案未填時沿用這裡）', 'ultimate-appointments' ) . '</strong></p>';

		woocommerce_wp_text_input(
			array(
				'id'                => '_uappt_duration_minutes',
				'value'             => '' !== $duration ? $duration : 60,
				'label'             => __( '服務時長（分鐘）', 'ultimate-appointments' ),
				'description'       => __( '單次療程實際服務客人的時間長度。未建立任何方案時，這就是本商品的服務時長。', 'ultimate-appointments' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '5',
					'step' => '5',
				),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => '_uappt_buffer_before',
				'value'             => '' !== $buffer_before ? $buffer_before : 0,
				'label'             => __( '服務前緩衝（分鐘）', 'ultimate-appointments' ),
				'description'       => __( '例如準備場地/器材所需時間，這段時間同樣會佔用該人員的檔期。', 'ultimate-appointments' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '5',
				),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => '_uappt_buffer_after',
				'value'             => '' !== $buffer_after ? $buffer_after : 0,
				'label'             => __( '服務後緩衝（分鐘）', 'ultimate-appointments' ),
				'description'       => __( '例如整理清潔所需時間，這段時間同樣會佔用該人員的檔期。', 'ultimate-appointments' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '5',
				),
			)
		);

		echo '</div>';

		// --- 服務人員安排 ---
		// 獨立成一個區塊而不是留在「預設服務設定」裡：這裡的三個欄位是同一件事
		// （這項服務由誰做、客人能不能挑、由誰決定人選），跟時長/緩衝那種
		// 純數字設定的性質不一樣，混在一起會讓人漏看後面兩個 checkbox。
		echo '<div class="options_group">';
		echo '<p class="form-field"><strong>' . esc_html__( '服務人員安排', 'ultimate-appointments' ) . '</strong></p>';

		woocommerce_wp_select(
			array(
				'id'                => '_uappt_staff_ids',
				'name'              => '_uappt_staff_ids[]',
				// uappt-staff-select：跟方案卡片裡的 .uappt-plan-staff 共用同一組
				// CSS（見 admin.css「可服務人員」那一節）——人員一多，選起來的
				// 標籤會自動換行、超過高度上限時卡片本身自己捲動，不會把 50%
				// 寬度硬撐成一長串直排標籤（實測 12 位人員在舊版 50% 寬度下
				// 會把這個欄位撐高到 359px，整頁往下推）。
				'class'             => 'wc-enhanced-select uappt-staff-select',
				'style'             => 'width:100%;',
				// uappt-field-stacked：這個欄位寬度是 100%，WooCommerce 預設的
				// 「標籤浮在左邊 150px、欄位緊接在後」排版放不下這麼寬的欄位，
				// 欄位會被擠到下一行，「?」提示圖示因此卡在標籤右邊、懸在欄位
				// 左上角正上方，看起來像壓到欄位。改成標籤（含「?」）自己一行、
				// 欄位另起一行、兩者共用同一條左邊界，見 admin.css 對應規則。
				'wrapper_class'     => 'uappt-field-stacked',
				'custom_attributes' => array( 'multiple' => 'multiple' ),
				'options'           => self::get_staff_options(),
				'value'             => array_map( 'strval', $staff_ids ),
				/* translators: 這個星號代表必填欄位 */
				'label'             => __( '可服務的人員 *', 'ultimate-appointments' ),
				'description'       => __( '哪些人員能做這項服務——這份名單同時是「系統自動安排」與「管理者手動分派」共同的候選範圍，必須至少選一位，否則這項服務在前台完全無法預約。方案的人員欄留空時會沿用這裡。', 'ultimate-appointments' ),
				'desc_tip'          => true,
			)
		);

		woocommerce_wp_checkbox(
			array(
				'id'          => '_uappt_hide_staff_choice',
				'value'       => 'yes' === get_post_meta( $product_id, '_uappt_hide_staff_choice', true ) ? 'yes' : 'no',
				'label'       => __( '不開放客人指定服務人員', 'ultimate-appointments' ),
				'description' => __( '勾選後前台只會顯示「不指定服務人員」，客人無法從上面的名單裡挑人；最終仍會依下面的設定由系統自動安排或標記給管理者手動分派。上面的人員名單不受影響，繼續作為安排時的候選範圍。', 'ultimate-appointments' ),
			)
		);

		woocommerce_wp_checkbox(
			array(
				'id'          => '_uappt_manual_assignment',
				'value'       => 'yes' === get_post_meta( $product_id, '_uappt_manual_assignment', true ) ? 'yes' : 'no',
				'label'       => __( '由管理者手動安排服務人員', 'ultimate-appointments' ),
				'description' => __( '勾選後，客人沒有指定人員時，系統仍會照常挑一位候選人員把時段鎖住（時段完整性不受影響、不會超賣），但這筆預約會標記為「待分派」，需要管理者到預約列表確認或更換最終人選。不勾就是目前的行為：系統自動安排就是最終結果。客人自己指定的預約不受這個設定影響。', 'ultimate-appointments' ),
			)
		);

		echo '</div>';

		// --- 團體預約（一次訂單容納多位參加者，例如瑜珈課帶朋友一起報名）---
		// 獨立成自己的區塊：這是「一張訂單算幾個人頭」的開關，跟上面「服務人員
		// 安排」問的是「誰來服務」完全是兩件事，不該混在一起讓人漏看。刻意預設
		// 關閉——現有「同時可服務人數」（人員的 capacity）本來就已經能讓好幾位
		// 客人各自獨立下單、共用同一個時段（例如 10 位學生各自訂位的瑜珈課），
		// 這裡要解決的是不同的情境：同一位客人一次幫好幾個人（自己+朋友、
		// 一家大小）報名，開了才會在前台多出「報名人數」欄位，沒開就完全是
		// 今天的行為，不影響任何既有商品。
		echo '<div class="options_group">';
		echo '<p class="form-field"><strong>' . esc_html__( '團體預約', 'ultimate-appointments' ) . '</strong></p>';

		woocommerce_wp_checkbox(
			array(
				'id'          => '_uappt_group_booking',
				'value'       => 'yes' === get_post_meta( $product_id, '_uappt_group_booking', true ) ? 'yes' : 'no',
				'label'       => __( '開放一次幫多人報名', 'ultimate-appointments' ),
				'description' => __( '勾選後，前台會多一個「報名人數」欄位，客人可以一次訂多個名額（例如自己加朋友）。實際能訂多少人仍然受服務人員當下「同時可服務人數」剩餘名額限制，不會超賣；下面可以另外設定每張訂單的人數上限。只適合本來就有多位候選人員、或人員「同時可服務人數」設定大於 1 的服務——單一顧客、一對一的服務不要勾這個。', 'ultimate-appointments' ),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => '_uappt_group_max_participants',
				'value'             => get_post_meta( $product_id, '_uappt_group_max_participants', true ),
				'label'             => __( '每張訂單人數上限', 'ultimate-appointments' ),
				'placeholder'       => __( '不限（只受剩餘名額限制）', 'ultimate-appointments' ),
				'description'       => __( '這是您自己訂的營運政策，跟人力能不能塞下是兩回事：就算這堂課還有 10 個名額，也可以規定單張訂單最多訂 4 人（超過的請客人來電或分開下單另外安排）。實際能訂的人數一律是「這裡的上限」與「當下剩餘名額」兩者取較小值——名額不夠的話還是會被擋下來，不會超賣。留空代表沒有這層政策限制，完全看剩餘名額。', 'ultimate-appointments' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '2',
					'step' => '1',
				),
			)
		);

		echo '</div>';

		// --- 預約政策（留空沿用全域設定）---
		echo '<div class="options_group">';
		echo '<p class="form-field"><strong>' . esc_html__( '預約政策（留空沿用全域設定）', 'ultimate-appointments' ) . '</strong></p>';

		woocommerce_wp_text_input(
			array(
				'id'                => '_uappt_horizon_days',
				'value'             => $horizon,
				'label'             => __( '開放預約天數', 'ultimate-appointments' ),
				/* translators: %d: 全域設定的開放預約天數 */
				'placeholder'       => sprintf( __( '沿用全域（%d 天）', 'ultimate-appointments' ), (int) get_option( 'uappt_booking_horizon_days', 30 ) ),
				'description'       => __( '客人最多可以預約到幾天後。熱門服務可以開放得更久，或某些服務只開放近期。', 'ultimate-appointments' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '1',
					'step' => '1',
				),
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => '_uappt_min_lead_minutes',
				'value'             => $lead,
				'label'             => __( '最少提前預約時間（分鐘）', 'ultimate-appointments' ),
				/* translators: %d: 全域設定的最少提前預約分鐘數 */
				'placeholder'       => sprintf( __( '沿用全域（%d 分鐘）', 'ultimate-appointments' ), (int) get_option( 'uappt_min_lead_minutes', 0 ) ),
				'description'       => __( '客人最晚要在服務開始前多久完成預約。設 120 就是兩小時內的時段不再開放，讓店家有時間準備。', 'ultimate-appointments' ),
				'desc_tip'          => true,
				'type'              => 'number',
				'custom_attributes' => array(
					'min'  => '0',
					'step' => '5',
				),
			)
		);

		echo '</div>';

		// --- 暫停接受預約（營業狀態開關，狀態列會替它報到）---
		echo '<div class="options_group uappt-pause-group">';
		woocommerce_wp_checkbox(
			array(
				'id'          => '_uappt_paused',
				'value'       => 'yes' === get_post_meta( $product_id, '_uappt_paused', true ) ? 'yes' : 'no',
				'label'       => __( '暫停接受預約', 'ultimate-appointments' ),
				'description' => __( '勾選後，前台不再顯示這項服務的時段、也無法加入購物車。已經成立的預約完全不受影響（照常提醒、可改期、可取消），後台也仍然可以手動幫客人建立預約。適合用在「師傅休長假」「這個療程暫時停售」這類情境，比把商品退成草稿溫和——商品頁還在，客人看得到內容。', 'ultimate-appointments' ),
			)
		);
		echo '</div>';

		echo '</div>';
	}

	/**
	 * 這個商品目前有沒有任何一種「客人真的買得到」的設定。
	 *
	 * 「有沒有方案」會改變 is_bookable() 該怎麼問：沒有方案時直接問商品本身；
	 * 有方案時**必須**逐一問每個啟用中的方案（is_bookable( $id, '' ) 在有方案的
	 * 商品上永遠回 false——那是刻意的，見該方法的說明：不能在沒指定方案時
	 * 隨便猜一個）。狀態列與存檔後的警告都要問同一個問題，答案不能兩套邏輯各算一次。
	 *
	 * @param int        $product_id 商品 ID。
	 * @param array|null $plans      商品的服務方案；傳 null 由本方法自己讀。
	 * @return bool
	 */
	public static function has_bookable_configuration( $product_id, $plans = null ) {
		if ( null === $plans ) {
			$plans = self::get_plans( $product_id );
		}

		$active_plans = array();
		foreach ( $plans as $plan ) {
			if ( ! empty( $plan['active'] ) ) {
				$active_plans[] = $plan;
			}
		}

		if ( $active_plans ) {
			foreach ( $active_plans as $plan ) {
				if ( self::is_bookable( $product_id, $plan['key'] ) ) {
					return true;
				}
			}
			return false;
		}

		return ! $plans && self::is_bookable( $product_id );
	}

	/**
	 * 「這個服務會用掉哪些耗材」的唯讀摘要（v2.24.0）。
	 *
	 * 配方本身在「耗材管理 → 配方」那一頁編輯，不做在方案卡片裡：卡片本身
	 * 已經是一個可重複、可拖曳排序的區塊，再往裡面塞第二層可重複的欄位
	 * （每一列一個耗材加數量）會變成巢狀重複表單，欄位命名與 JS 複製樣板
	 * 都要跟著複雜一輪，出錯的機會遠大於省下的那幾次點擊。
	 *
	 * 但**看得到**很重要：不然管理者在這一頁完全不知道客人做完這個服務會被
	 * 扣掉什麼，出現「為什麼毛巾一直在少」的疑問時也找不到源頭。所以這裡印
	 * 一份摘要加一個連結過去。
	 *
	 * 站上一項耗材都還沒建立時整段不印——那是還沒開始用這個功能的店家，
	 * 多一段說明只是雜訊。
	 *
	 * @param int   $product_id 商品 ID。
	 * @param array $plans      這個商品的服務方案（含已停用）。
	 */
	protected static function render_consumable_summary( $product_id, $plans ) {
		// 這一段在商品編輯頁，不在耗材區塊裡——模組關著時區塊本身不存在，
		// 但這裡照樣會被畫出來，所以要自己擋。
		if ( ! UAPPT_Modules::enabled( 'consumables' ) ) {
			return;
		}

		if ( ! class_exists( 'UAPPT_Consumable' ) || ! UAPPT_Consumable::get_all( array( 'status' => UAPPT_Consumable::STATUS_ACTIVE ) ) ) {
			return;
		}

		$describe = function ( $plan_key ) use ( $product_id ) {
			$parts = array();
			foreach ( UAPPT_Consumable::describe_recipe( $product_id, $plan_key ) as $row ) {
				$parts[] = $row['name'] . ' ×' . UAPPT_Consumable::format_qty( $row['qty_per_service'], $row['unit'] );
			}
			return $parts;
		};

		$lines = array();
		if ( $plans ) {
			foreach ( $plans as $plan ) {
				$parts = $describe( $plan['key'] );
				if ( $parts ) {
					$lines[] = $plan['name'] . '：' . implode( '、', $parts );
				}
			}
		} else {
			$parts = $describe( '' );
			if ( $parts ) {
				$lines[] = implode( '、', $parts );
			}
		}

		$url = add_query_arg(
			array(
'page'   => UAPPT_Admin::PAGE_SLUG,
				'section'   => 'consumables',
				'tab'    => 'recipes',
				'target' => 'p:' . $product_id,
			),
			admin_url( 'admin.php' )
		);

		echo '<div class="uappt-plans-intro">';
		echo '<p class="description">';

		if ( $lines ) {
			echo '<strong>' . esc_html__( '完成這項服務時會扣用的耗材', 'ultimate-appointments' ) . '</strong><br />';
			echo esc_html( implode( ' ｜ ', $lines ) ) . '<br />';
		} else {
			echo esc_html__( '這項服務目前沒有設定任何耗材，標記完成時不會扣庫存。', 'ultimate-appointments' ) . ' ';
		}

		if ( current_user_can( UAPPT_Caps::CAP_MANAGE_CONSUMABLES ) ) {
			printf(
				'<a href="%s">%s</a>',
				esc_url( $url ),
				esc_html__( '到「耗材管理 → 配方」設定', 'ultimate-appointments' )
			);
		}

		echo '</p>';
		echo '</div>';
	}

	/**
	 * 面板最上方的狀態列：這個商品現在到底能不能賣，不能的話是為什麼。
	 *
	 * 刻意只在伺服器端渲染（＝反映「已儲存」的狀態，旁邊會註明）。想讓它跟著畫面上
	 * 的編輯即時變動，就得去解讀 select2 的多選狀態與每一列方案的有效設定，正確性
	 * 難保證，而一個「有時候說錯」的狀態列比沒有狀態列更糟。
	 *
	 * @param int   $product_id 商品 ID。
	 * @param array $plans      服務方案（含已停用）。
	 * @param array $staff_ids  商品層級的可服務人員。
	 */
	protected static function render_status_bar( $product_id, $plans, $staff_ids ) {
		$has_staff_records = (bool) UAPPT_Staff::get_all();

		$active_plans = array();
		foreach ( $plans as $plan ) {
			if ( ! empty( $plan['active'] ) ) {
				$active_plans[] = $plan;
			}
		}

		$bookable = self::has_bookable_configuration( $product_id, $plans );

		$state   = 'success';
		$label   = __( '可預約', 'ultimate-appointments' );
		$detail  = '';
		$actions = array();

		if ( ! $has_staff_records ) {
			$state  = 'error';
			$label  = __( '目前無法預約', 'ultimate-appointments' );
			$detail = __( '尚未建立任何人力資源。人力就是這套系統的庫存，沒有人員就排不出時段。', 'ultimate-appointments' );
			$actions[] = '<a href="' . esc_url( UAPPT_Admin::url( 'staff' ) ) . '">' .
				esc_html__( '前往「終極預約 → 人力資源」新增', 'ultimate-appointments' ) . '</a>';
		} elseif ( empty( $staff_ids ) && ! $bookable ) {
			// 「可服務的人員」是必填欄位（v2.2.0），空清單就是沒人能做，不是
			// 「不限定」。有方案時，方案自己可能各自指定了人員（$bookable 因此
			// 仍可能是 true），所以要跟 $bookable 一起判斷，不能單看商品層級的
			// $staff_ids 是否為空。
			$state  = 'error';
			$label  = __( '目前無法預約', 'ultimate-appointments' );
			$detail = __( '尚未選擇可服務的人員，前台不會出現任何時段。', 'ultimate-appointments' );
			$actions[] = '<a href="#" class="uappt-focus-field" data-target="_uappt_staff_ids">' .
				esc_html__( '前往設定可服務的人員', 'ultimate-appointments' ) . '</a>';
		} elseif ( $plans && ! $active_plans ) {
			$state  = 'error';
			$label  = __( '目前無法預約', 'ultimate-appointments' );
			$detail = __( '所有服務方案都已停用，客人在商品頁選不到任何方案。', 'ultimate-appointments' );
		} elseif ( ! $bookable ) {
			$state  = 'error';
			$label  = __( '目前無法預約', 'ultimate-appointments' );
			$detail = __( '這個商品還沒有任何一個可以成立的方案（多半是方案指定的人員都已停用或刪除）。', 'ultimate-appointments' );
		} elseif ( self::is_paused( $product_id ) ) {
			$state  = 'warning';
			$label  = __( '暫停接受預約中', 'ultimate-appointments' );
			$detail = __( '前台不顯示時段、無法加入購物車。既有預約不受影響，後台仍可手動建單。', 'ultimate-appointments' );
			$actions[] = '<a href="#" class="uappt-focus-field" data-target="_uappt_paused">' .
				esc_html__( '前往解除暫停', 'ultimate-appointments' ) . '</a>';
		} else {
			// 「可服務人員」不能只算商品層級的 $staff_ids：有方案時，每個方案可以
			// 用自己的人員清單覆寫商品層級設定，只看商品層級會在方案各自指定人員
			// 時把數字報成 0（明明可以賣），比不顯示這個數字還誤導。
			$effective_staff_ids = array();
			if ( $active_plans ) {
				foreach ( $active_plans as $plan ) {
					$plan_staff           = ! empty( $plan['staff_ids'] ) ? $plan['staff_ids'] : $staff_ids;
					$effective_staff_ids  = array_merge( $effective_staff_ids, array_map( 'intval', $plan_staff ) );
				}
				$effective_staff_ids = array_unique( $effective_staff_ids );
			} else {
				$effective_staff_ids = $staff_ids;
			}

			$parts = array();
			if ( $active_plans ) {
				/* translators: %d: 啟用中的服務方案數量 */
				$parts[] = sprintf( __( '%d 個方案', 'ultimate-appointments' ), count( $active_plans ) );
			} else {
				$parts[] = __( '單一服務（未建立方案）', 'ultimate-appointments' );
			}
			/* translators: %d: 可服務人員數量 */
			$parts[] = sprintf( __( '%d 位可服務人員', 'ultimate-appointments' ), count( $effective_staff_ids ) );
			/* translators: %d: 預設服務時長分鐘數 */
			$parts[] = sprintf( __( '預設 %d 分鐘', 'ultimate-appointments' ), max( 5, (int) get_post_meta( $product_id, '_uappt_duration_minutes', true ) ?: 60 ) );

			$detail = implode( __( '・', 'ultimate-appointments' ), $parts );

			$inactive_count = count( $plans ) - count( $active_plans );
			if ( $inactive_count > 0 ) {
				/* translators: %d: 已停用的方案數量 */
				$detail .= '　' . sprintf( __( '（另有 %d 個方案已停用）', 'ultimate-appointments' ), $inactive_count );
			}
		}

		echo '<div class="uappt-status-bar uappt-status-' . esc_attr( $state ) . '">';
		echo '<span class="uappt-badge uappt-badge-' . esc_attr( $state ) . '">' . esc_html( $label ) . '</span> ';
		echo '<span class="uappt-status-detail">' . esc_html( $detail ) . '</span>';
		foreach ( $actions as $action ) {
			echo ' <span class="uappt-status-action">' . wp_kses_post( $action ) . '</span>';
		}
		echo '<span class="uappt-status-note">' . esc_html__( '（儲存後更新）', 'ultimate-appointments' ) . '</span>';
		echo '</div>';
	}

	/**
	 * 輸出單一方案列的 HTML。
	 *
	 * 版面決策：前／後緩衝與「暫停銷售」收在名稱欄底下的「進階」展開層裡，而不是
	 * 另外開一個 <tr>。獨立的展開列會跟拖曳排序打架（sortable 只認得單層 tr，展開列
	 * 會被留在原地變成孤兒）；塞在同一個 <tr> 裡，整列一起被拖走，不需要任何補救。
	 *
	 * @param string $index    表單索引（範本列用 __INDEX__，由 JS 換掉）。
	 * @param array  $plan     方案資料，新列傳空陣列。
	 * @param array  $defaults 商品層級的有效預設值，用來組 placeholder。
	 * @return string
	 */
	protected static function render_plan_row( $index, $plan, $defaults ) {
		$plan = wp_parse_args(
			$plan,
			array(
				'key'           => '',
				'name'          => '',
				'duration'      => '',
				'price'         => '',
				'buffer_before' => '',
				'buffer_after'  => '',
				'staff_ids'     => array(),
				'active'        => true,
			)
		);

		$base     = '_uappt_plan[' . $index . ']';
		$inactive = empty( $plan['active'] );

		// 收在展開層裡的設定一旦有值就預設展開：看不見卻仍然生效的設定，是這個分頁
		// 最容易讓人算錯檔期的地方。
		$has_advanced = $inactive
			|| '' !== (string) $plan['buffer_before']
			|| '' !== (string) $plan['buffer_after'];

		/* translators: %d: 沿用的分鐘數 */
		$ph = __( '沿用 %d', 'ultimate-appointments' );

		/* translators: %s: 貨幣符號 */
		$price_label = sprintf( __( '售價（%s）', 'ultimate-appointments' ), get_woocommerce_currency_symbol() );

		// class 名稱刻意沿用表格時代的 .uappt-plan-row：assets/js/admin-product.js
		// 全部靠它做 closest()／find()（新增、刪除、展開進階、停用同步、驗證、
		// 拖曳排序），改名等於要同步改十幾處選取器，沒有好處。
		$html  = '<div class="uappt-plan-row' . ( $inactive ? ' is-inactive' : '' ) . '">';

		// --- 把手：獨立成卡片左側整條軌道，不再擠在標題那一行裡 ---
		// 舊版把手跟名稱輸入框並排在 .uappt-plan-head 裡，下面的 .uappt-plan-fields
		// 卻是另一個從卡片內距頂邊算起的區塊——結果卡片標題那一行的內容從
		// 「把手寬度＋間距」之後才開始，下面每一行卻從卡片最左邊開始，兩條不同的
		// 左邊界。把手移出來當卡片的第一個子元素、跟 .uappt-plan-body 左右並排，
		// 卡片裡才只有一條左邊界：把手的，以及它右邊「內容」共用的那一條。
		$html .= '<span class="uappt-plan-handle dashicons dashicons-menu" title="' . esc_attr__( '拖曳調整順序', 'ultimate-appointments' ) . '"></span>';

		$html .= '<div class="uappt-plan-body">';

		// --- 卡片頂：方案名稱 ＋ 進階／刪除 ---
		$html .= '<div class="uappt-plan-head">';
		$html .= '<input type="hidden" name="' . esc_attr( $base ) . '[key]" value="' . esc_attr( $plan['key'] ) . '" />';
		$html .= '<input type="text" class="uappt-plan-name" name="' . esc_attr( $base ) . '[name]" value="' . esc_attr( $plan['name'] ) . '" placeholder="' . esc_attr__( '方案名稱，例如：90 分鐘全身舒壓', 'ultimate-appointments' ) . '" />';
		$html .= '<span class="uappt-badge uappt-badge-muted uappt-plan-inactive-badge"' . ( $inactive ? '' : ' style="display:none;"' ) . '>' . esc_html__( '已停用', 'ultimate-appointments' ) . '</span>';
		$html .= '<button type="button" class="button-link uappt-toggle-advanced' . ( $has_advanced ? ' has-values' : '' ) . '" aria-expanded="' . ( $has_advanced ? 'true' : 'false' ) . '">';
		$html .= '<span class="dashicons dashicons-admin-generic"></span>';
		$html .= '<span class="uappt-toggle-advanced-text">' . esc_html__( '進階', 'ultimate-appointments' ) . '</span>';
		$html .= '</button>';
		$html .= '<button type="button" class="button-link uappt-remove-plan" title="' . esc_attr__( '刪除方案', 'ultimate-appointments' ) . '" aria-label="' . esc_attr__( '刪除方案', 'ultimate-appointments' ) . '">&times;</button>';
		$html .= '</div>';

		// --- 主要欄位：時長／售價，兩個短數字欄位並排 ---
		// 可服務人員拆到自己獨立一行（見下方），不再跟這兩個擠在同一個
		// flex-wrap 列裡——原本三欄搶同一列寬度，時長／售價是短數字卻要
		// `flex:1` 撐開去填多選欄位讓出的剩餘空間，兩個 3 位數的欄位中間
		// 因此空出一大塊留白。拆開之後這裡只需要容得下兩個數字輸入框，
		// 用 flex:0 0 <固定寬度> 讓它們照自己的內容取寬，不再被迫撐開。
		$html .= '<div class="uappt-plan-fields">';

		$html .= '<label class="uappt-plan-field">';
		$html .= '<span class="uappt-plan-field-label">' . esc_html__( '時長（分）', 'ultimate-appointments' ) . '</span>';
		$html .= '<input type="number" min="5" step="5" class="uappt-inherit-input" data-field="duration" data-inherit="' . esc_attr( $defaults['duration'] ) . '" name="' . esc_attr( $base ) . '[duration]" value="' . esc_attr( $plan['duration'] ) . '" placeholder="' . esc_attr( sprintf( $ph, $defaults['duration'] ) ) . '" />';
		$html .= '</label>';

		$html .= '<label class="uappt-plan-field">';
		$html .= '<span class="uappt-plan-field-label">' . esc_html( $price_label ) . '</span>';
		$html .= '<input type="text" class="wc_input_price" name="' . esc_attr( $base ) . '[price]" value="' . esc_attr( $plan['price'] ) . '" />';
		$html .= '</label>';

		$html .= '</div>';

		// 可服務人員：獨立一整列，寬度跟「服務人員安排」那個商品層級欄位
		// 一致（見 .uappt-plan-field--staff 的 CSS），多選的人員多時才有地方
		// 排開，不會被前面兩個數字欄位擠成一條窄縫。
		$html .= '<label class="uappt-plan-field uappt-plan-field--staff">';
		$html .= '<span class="uappt-plan-field-label">' . esc_html__( '可服務人員', 'ultimate-appointments' ) . '</span>';
		$html .= '<select class="wc-enhanced-select uappt-plan-staff uappt-staff-select" name="' . esc_attr( $base ) . '[staff_ids][]" multiple="multiple" data-placeholder="' . esc_attr__( '沿用預設值', 'ultimate-appointments' ) . '" style="width:100%;">';
		$selected = array_map( 'intval', (array) $plan['staff_ids'] );
		foreach ( self::get_staff_options() as $staff_id => $staff_name ) {
			$html .= '<option value="' . esc_attr( $staff_id ) . '" ' . selected( in_array( (int) $staff_id, $selected, true ), true, false ) . '>' . esc_html( $staff_name ) . '</option>';
		}
		$html .= '</select>';
		$html .= '</label>';

		// --- 進階：緩衝時間與暫停銷售，展開時拿得到整張卡片的寬度 ---
		$html .= '<div class="uappt-plan-advanced"' . ( $has_advanced ? '' : ' style="display:none;"' ) . '>';
		$html .= '<div class="uappt-plan-fields">';

		$html .= '<label class="uappt-plan-field">';
		$html .= '<span class="uappt-plan-field-label">' . esc_html__( '前緩衝（分鐘）', 'ultimate-appointments' ) . '</span>';
		$html .= '<input type="number" min="0" step="5" class="uappt-inherit-input" data-field="buffer_before" data-inherit="' . esc_attr( $defaults['buffer_before'] ) . '" name="' . esc_attr( $base ) . '[buffer_before]" value="' . esc_attr( $plan['buffer_before'] ) . '" placeholder="' . esc_attr( sprintf( $ph, $defaults['buffer_before'] ) ) . '" />';
		$html .= '</label>';

		$html .= '<label class="uappt-plan-field">';
		$html .= '<span class="uappt-plan-field-label">' . esc_html__( '後緩衝（分鐘）', 'ultimate-appointments' ) . '</span>';
		$html .= '<input type="number" min="0" step="5" class="uappt-inherit-input" data-field="buffer_after" data-inherit="' . esc_attr( $defaults['buffer_after'] ) . '" name="' . esc_attr( $base ) . '[buffer_after]" value="' . esc_attr( $plan['buffer_after'] ) . '" placeholder="' . esc_attr( sprintf( $ph, $defaults['buffer_after'] ) ) . '" />';
		$html .= '</label>';

		$html .= '</div>';

		// 送出的是「停用」而不是「啟用」：沒勾的 checkbox 根本不會進 $_POST，用停用
		// 當語意才能讓「沒送出＝啟用中」成立，不必額外的 hidden 欄位。
		$html .= '<label class="uappt-plan-advanced-toggle">';
		$html .= '<input type="checkbox" class="uappt-plan-inactive" name="' . esc_attr( $base ) . '[inactive]" value="1" ' . checked( $inactive, true, false ) . ' /> ';
		$html .= esc_html__( '暫停銷售此方案', 'ultimate-appointments' );
		$html .= '</label>';
		$html .= '<p class="description">' . esc_html__( '前台不再顯示這個方案，也不列入商品價格區間；已經用它建立的預約仍然可以改期。想永久移除請用卡片右上角的 ×。', 'ultimate-appointments' ) . '</p>';
		$html .= '</div>';

		$html .= '</div>'; // .uappt-plan-body

		$html .= '</div>'; // .uappt-plan-row

		return $html;
	}

	/**
	 * 存檔後把「這次存檔發現的問題」帶進轉址網址，讓 UAPPT_Admin::render_notices()
	 * 顯示出來。
	 *
	 * 只在確定本次存的是預約商品時才動手（self::$saved_product_id），否則存一般
	 * 商品也會被掛上預約相關的警告。
	 *
	 * @param string $location 轉址網址。
	 * @param int    $post_id  被存檔的文章 ID。
	 * @return string
	 */
	public function add_save_notice_args( $location, $post_id ) {
		if ( ! self::$saved_product_id || (int) $post_id !== self::$saved_product_id ) {
			return $location;
		}

		if ( self::$dropped_plan_rows > 0 ) {
			return add_query_arg(
				array(
					'uappt_notice' => 'plan_rows_dropped',
					'uappt_count'  => self::$dropped_plan_rows,
				),
				$location
			);
		}

		// 「完全買不到」是整個分頁最容易踩、後果最嚴重卻最沒有回饋的一件事，所以就算
		// 存檔本身成功也要跳警告。has_bookable_configuration() 跟狀態列問的是同一個
		// 問題（不能各算一套）：沒有方案時看商品層級的人員，有方案時要求至少一個
		// 啟用中的方案本身可預約——不能只看商品層級 staff_ids 是否為空，那在
		// 「方案各自指定人員」的情境下會誤報。
		if ( ! self::has_bookable_configuration( $post_id ) ) {
			return add_query_arg( array( 'uappt_notice' => 'no_bookable_staff' ), $location );
		}

		return $location;
	}

	/**
	 * 人員下拉選單的選項（id => 姓名），只列出啟用中的人員。
	 *
	 * @return array
	 */
	protected static function get_staff_options() {
		$options = array();
		foreach ( (array) UAPPT_Staff::get_all() as $staff ) {
			if ( isset( $staff['status'] ) && 'active' !== $staff['status'] ) {
				continue;
			}
			$options[ (int) $staff['id'] ] = $staff['name'];
		}
		return $options;
	}

	/**
	 * 儲存商品層級欄位與服務方案。
	 *
	 * 掛在 woocommerce_admin_process_product_object 上（而非 *_meta_simple），
	 * 這個 hook 對所有商品類型都會觸發。
	 *
	 * @param WC_Product $product 商品物件。
	 */
	public function save_product_fields( $product ) {
		if (
			! isset( $_POST['woocommerce_meta_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' )
		) {
			return;
		}

		// 這個分頁只在「預約商品」出現，其他類型不該被寫入預約設定——否則管理者
		// 把商品從預約商品改回簡單商品時，會留下一堆看不到卻仍生效的舊設定。
		if ( ! $product instanceof WC_Product || UAPPT_Product_Type::PRODUCT_TYPE !== $product->get_type() ) {
			return;
		}

		$product->update_meta_data( '_uappt_paused', isset( $_POST['_uappt_paused'] ) ? 'yes' : 'no' );

		$product->update_meta_data( '_uappt_duration_minutes', isset( $_POST['_uappt_duration_minutes'] ) ? max( 5, (int) $_POST['_uappt_duration_minutes'] ) : 60 );
		$product->update_meta_data( '_uappt_buffer_before', isset( $_POST['_uappt_buffer_before'] ) ? max( 0, (int) $_POST['_uappt_buffer_before'] ) : 0 );
		$product->update_meta_data( '_uappt_buffer_after', isset( $_POST['_uappt_buffer_after'] ) ? max( 0, (int) $_POST['_uappt_buffer_after'] ) : 0 );

		$staff_ids = isset( $_POST['_uappt_staff_ids'] ) && is_array( $_POST['_uappt_staff_ids'] )
			? array_map( 'intval', wp_unslash( $_POST['_uappt_staff_ids'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			: array();
		$product->update_meta_data( '_uappt_staff_ids', array_values( array_unique( array_filter( $staff_ids ) ) ) );

		$product->update_meta_data( '_uappt_hide_staff_choice', isset( $_POST['_uappt_hide_staff_choice'] ) ? 'yes' : 'no' );
		$product->update_meta_data( '_uappt_manual_assignment', isset( $_POST['_uappt_manual_assignment'] ) ? 'yes' : 'no' );

		$product->update_meta_data( '_uappt_group_booking', isset( $_POST['_uappt_group_booking'] ) ? 'yes' : 'no' );
		// 上限至少要 2——填 1 等於「一個人」，那就是關掉團體預約，不該用上限欄位
		// 表達，直接把上面那個 checkbox 取消勾選就好，避免同一件事有兩種填法。
		$product->update_meta_data( '_uappt_group_max_participants', self::sanitize_optional_int( '_uappt_group_max_participants', 2 ) );

		// 預約政策：留空存成空字串（＝沿用全域），不要存成 0，0 是有意義的值。
		$product->update_meta_data( '_uappt_horizon_days', self::sanitize_optional_int( '_uappt_horizon_days', 1 ) );
		$product->update_meta_data( '_uappt_min_lead_minutes', self::sanitize_optional_int( '_uappt_min_lead_minutes', 0 ) );

		$plans = self::sanitize_plans_from_post();
		$product->update_meta_data( self::PLANS_META, $plans );

		// 記下來給 add_save_notice_args() 用：確定這次存的是預約商品，才發預約相關通知。
		self::$saved_product_id = $product->get_id();
	}

	/**
	 * 商品存檔完成後，把 _price 同步成最低方案價（沒有方案就不動它，「一般」
	 * 分頁的 _regular_price 才是售價）。
	 *
	 * 必須在這裡（存檔完成後）直接寫 postmeta，理由見建構子的說明。同時更新
	 * wc_product_meta_lookup，價格排序／範圍篩選 widget 是讀那張表，不是讀 postmeta。
	 *
	 * @param int $product_id 商品 ID。
	 */
	public function sync_price_after_save( $product_id ) {
		// 只看啟用中的方案：停用的方案不該把商品價格拉低。全部停用時 $prices 會是
		// 空的、直接跳出，_price 維持上一次的值——此時商品本來就不可預約，價格是
		// 什麼已經不影響交易，不值得為它多寫一條分支。
		$prices = array();
		foreach ( self::get_plans( $product_id, true ) as $plan ) {
			if ( '' !== (string) $plan['price'] ) {
				$prices[] = (float) $plan['price'];
			}
		}

		if ( ! $prices ) {
			return;
		}

		update_post_meta( $product_id, '_price', (string) min( $prices ) );

		// wc_product_meta_lookup 是價格排序／範圍篩選 widget 實際查詢的表，不是
		// postmeta。refresh_product_lookup_table() 是 WC 10.8 才新增的公開方法
		// （本外掛最低支援 WC 4.0），舊版沒有就跳過同步——只影響排序／篩選，
		// 不影響預約功能本身。
		// WC_Data_Store::load() 回傳的是外層包裝物件，實際的資料存放層實例包在裡面，
		// 只能透過 __call() 轉發呼叫；method_exists() 檢查的是包裝物件本身的類別，
		// 永遠測不出被轉發的方法，一律回傳 false（曾經因為用 method_exists() 判斷
		// 而讓這段整個被跳過，价格排序表一直沒被同步）。has_callable() 才是
		// WC_Data_Store 自己提供、正確處理這層轉發的判斷方法。
		if ( class_exists( 'WC_Data_Store' ) ) {
			$store = WC_Data_Store::load( 'product' );
			if ( $store->has_callable( 'refresh_product_lookup_table' ) ) {
				$store->refresh_product_lookup_table( $product_id );
			}
		}
	}

	/**
	 * 讀取一個「可留空」的整數欄位：留空回傳空字串，代表沿用全域設定。
	 *
	 * @param string $key $_POST 的欄位名稱。
	 * @param int    $min 最小值。
	 * @return string|int
	 */
	protected static function sanitize_optional_int( $key, $min ) {
		if ( ! isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return '';
		}
		$raw = trim( (string) wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification
		if ( '' === $raw ) {
			return '';
		}
		return max( $min, (int) $raw );
	}

	/**
	 * 從 $_POST 解析並清洗方案陣列。
	 *
	 * 沒有 key 的列（新增的列）在這裡才產生 key，而且一產生就固定不變——既有預約
	 * 是用 plan_key 對應回來的，key 若隨陣列位置變動，刪掉中間一個方案就會讓所有
	 * 後面的預約指到錯的方案。
	 *
	 * @return array
	 */
	protected static function sanitize_plans_from_post() {
		if ( ! isset( $_POST['_uappt_plan'] ) || ! is_array( $_POST['_uappt_plan'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return array();
		}

		$raw_rows                = wp_unslash( $_POST['_uappt_plan'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification
		$plans                   = array();
		$used                    = array();
		self::$dropped_plan_rows = 0;

		foreach ( (array) $raw_rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$name = isset( $row['name'] ) ? sanitize_text_field( $row['name'] ) : '';
			if ( '' === trim( $name ) ) {
				// 名稱空白視為「這一列沒填」，直接丟掉；否則前台會出現無名方案。
				// 但「其他欄位填了、只是忘了打名稱」不能無聲丟掉——那是管理者真的
				// 想留下的資料。數出來，存檔後用 admin notice 講清楚。
				if ( self::row_has_content( $row ) ) {
					self::$dropped_plan_rows++;
				}
				continue;
			}

			$key = isset( $row['key'] ) ? sanitize_key( $row['key'] ) : '';
			if ( '' === $key || isset( $used[ $key ] ) ) {
				$key = self::generate_plan_key();
			}
			$used[ $key ] = true;

			$staff_ids = isset( $row['staff_ids'] ) && is_array( $row['staff_ids'] )
				? array_values( array_unique( array_filter( array_map( 'intval', $row['staff_ids'] ) ) ) )
				: array();

			$plans[] = array(
				'key'           => $key,
				'name'          => $name,
				'duration'      => self::sanitize_optional_row_int( $row, 'duration', 5 ),
				'price'         => isset( $row['price'] ) ? wc_format_decimal( $row['price'] ) : '',
				'buffer_before' => self::sanitize_optional_row_int( $row, 'buffer_before', 0 ),
				'buffer_after'  => self::sanitize_optional_row_int( $row, 'buffer_after', 0 ),
				'staff_ids'     => $staff_ids,
				// 送出的欄位是「停用」：沒勾的 checkbox 不會進 $_POST，所以
				// 「這個 key 不存在」自然就是啟用中，不需要額外的 hidden 欄位。
				'active'        => empty( $row['inactive'] ),
			);
		}

		return $plans;
	}

	/**
	 * 這一列除了名稱以外有沒有填任何東西。
	 *
	 * 用來分辨「按了新增方案但什麼都沒填」（可以安靜丟掉）與「填了價格卻忘了打名稱」
	 * （必須告訴管理者，不然他會以為存好了）。
	 *
	 * @param array $row 該列的原始資料。
	 * @return bool
	 */
	protected static function row_has_content( $row ) {
		foreach ( array( 'duration', 'price', 'buffer_before', 'buffer_after' ) as $field ) {
			if ( isset( $row[ $field ] ) && '' !== trim( (string) $row[ $field ] ) ) {
				return true;
			}
		}

		if ( isset( $row['staff_ids'] ) && is_array( $row['staff_ids'] ) && array_filter( $row['staff_ids'] ) ) {
			return true;
		}

		return ! empty( $row['inactive'] );
	}

	/**
	 * 方案列裡「留空＝沿用商品層級」的整數欄位。
	 *
	 * @param array  $row 該列的原始資料。
	 * @param string $key 欄位名稱。
	 * @param int    $min 最小值。
	 * @return string|int
	 */
	protected static function sanitize_optional_row_int( $row, $key, $min ) {
		$raw = isset( $row[ $key ] ) ? trim( (string) $row[ $key ] ) : '';
		if ( '' === $raw ) {
			return '';
		}
		return max( $min, (int) $raw );
	}

	/**
	 * 讀取商品的 _uappt_staff_ids meta，正規化成整數陣列。
	 *
	 * v2.21.0 從 protected 改成 public：人員→服務的反查索引
	 * （UAPPT_Service_Index）要讀商品層級的人員清單，而那個正規化邏輯
	 * （非陣列一律當成空陣列、值一律轉整數）必須跟這裡完全一致，
	 * 複製一份過去遲早會兩邊走鐘。
	 *
	 * @param int $post_id 商品 ID。
	 * @return array
	 */
	public static function get_staff_ids_meta( $post_id ) {
		$raw = get_post_meta( $post_id, '_uappt_staff_ids', true );
		return is_array( $raw ) ? array_map( 'intval', $raw ) : array();
	}

	/**
	 * 從所有「預約商品」的商品層級 `_uappt_staff_ids` 與每個服務方案的
	 * `staff_ids` 中移除某位人員。刪除人員（UAPPT_Admin::handle_delete_staff()）
	 * 成功後呼叫，避免商品／方案繼續指向一個已經不存在的人員 ID——那會讓
	 * `get_booking_settings()` 的候選名單裡混進查不到資料的幽靈 ID，比起
	 * 「商品因此變成沒有可服務人員」更難察覺、更難排查。
	 *
	 * 只在人員真的被刪除、且已確認沒有未來預約時才會呼叫（見
	 * `handle_delete_staff()` 的保護檢查），所以這裡不需要處理「這個人員還在
	 * 服務中」的情境。
	 *
	 * @param int $staff_id 人員 ID。
	 */
	public static function remove_staff_from_all_products( $staff_id ) {
		$staff_id = (int) $staff_id;
		if ( ! $staff_id || ! class_exists( 'UAPPT_Product_Type' ) ) {
			return;
		}

		foreach ( UAPPT_Product_Type::get_converted_product_ids() as $product_id ) {
			$changed = false;

			$ids = self::get_staff_ids_meta( $product_id );
			if ( in_array( $staff_id, $ids, true ) ) {
				update_post_meta( $product_id, '_uappt_staff_ids', array_values( array_diff( $ids, array( $staff_id ) ) ) );
				$changed = true;
			}

			$plans = get_post_meta( $product_id, self::PLANS_META, true );
			if ( is_array( $plans ) ) {
				$plans_changed = false;
				foreach ( $plans as &$plan ) {
					if ( ! is_array( $plan ) || empty( $plan['staff_ids'] ) || ! is_array( $plan['staff_ids'] ) ) {
						continue;
					}
					$plan_staff_ids = array_map( 'intval', $plan['staff_ids'] );
					if ( in_array( $staff_id, $plan_staff_ids, true ) ) {
						$plan['staff_ids'] = array_values( array_diff( $plan_staff_ids, array( $staff_id ) ) );
						$plans_changed     = true;
					}
				}
				unset( $plan );
				if ( $plans_changed ) {
					update_post_meta( $product_id, self::PLANS_META, $plans );
					$changed = true;
				}
			}

			if ( $changed ) {
				clean_post_cache( $product_id );
			}
		}
	}

	/**
	 * 取得商品的服務方案清單（整份外掛唯一的讀取入口）。
	 *
	 * $active_only 只給「客人看得到什麼」的呼叫點用（前台方案下拉、價格區間、
	 * _price 同步）。**解析既有預約的呼叫點絕對不能開**——已停用的方案仍然要查得到，
	 * 否則客人手上那筆預約會變成「找不到方案」而無法改期，那正是「停用」要避免的事。
	 *
	 * @param int  $product_id  商品 ID。
	 * @param bool $active_only 只回傳未停用的方案。
	 * @return array
	 */
	public static function get_plans( $product_id, $active_only = false ) {
		$raw = get_post_meta( (int) $product_id, self::PLANS_META, true );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$plans = array();
		foreach ( $raw as $plan ) {
			if ( ! is_array( $plan ) || empty( $plan['key'] ) || empty( $plan['name'] ) ) {
				continue;
			}
			// active 預設 true：v2.1.x 存下來的舊方案沒有這個欄位，一律視為啟用中，
			// 所以不需要任何遷移程式。
			$plan = wp_parse_args(
				$plan,
				array(
					'duration'      => '',
					'price'         => '',
					'buffer_before' => '',
					'buffer_after'  => '',
					'staff_ids'     => array(),
					'active'        => true,
				)
			);

			if ( $active_only && empty( $plan['active'] ) ) {
				continue;
			}

			$plans[] = $plan;
		}

		return $plans;
	}

	/**
	 * 這個方案是否已停用（前台不販售）。
	 *
	 * @param int    $product_id 商品 ID。
	 * @param string $plan_key   方案鍵。
	 * @return bool
	 */
	public static function is_plan_inactive( $product_id, $plan_key ) {
		$plan = self::get_plan( $product_id, $plan_key );
		return $plan ? empty( $plan['active'] ) : false;
	}

	/**
	 * 產生一組永不重複、也永不變動的方案鍵（排班申請的批次鍵也借用它）。
	 *
	 * 絕對不能用陣列索引當鍵：管理者刪掉中間某個方案時索引會位移，
	 * 既有預約就會指到別的方案（跟「時間格顆粒跟著紀錄走」是同一類的坑）。
	 * v3.1.0 從 UAPPT_Install 搬過來——那邊只剩建資料表。
	 *
	 * @return string
	 */
	public static function generate_plan_key() {
		return 'plan_' . str_replace( '-', '', wp_generate_uuid4() );
	}

	/**
	 * 取得單一方案；找不到回傳 null。
	 *
	 * @param int    $product_id 商品 ID。
	 * @param string $plan_key   方案鍵。
	 * @return array|null
	 */
	public static function get_plan( $product_id, $plan_key ) {
		$plan_key = sanitize_key( (string) $plan_key );
		if ( '' === $plan_key ) {
			return null;
		}

		foreach ( self::get_plans( $product_id ) as $plan ) {
			if ( $plan['key'] === $plan_key ) {
				return $plan;
			}
		}

		return null;
	}

	/**
	 * 取得商品（或其中一個方案）的預約設定；若不是預約商品或沒有可服務人員則回傳 false。
	 *
	 * 「可服務的人員」是必填欄位（見 render_product_data_panel() 的必填驗證），
	 * 空清單就是「沒人能做這項服務」，不做任何猜測或展開。
	 *
	 * v2.1.2 曾經短暫把留空實作成「不限定，全體在職人員」，v2.2.0 改回必填——
	 * 那個設計讓「這項服務到底該由誰做」變成無法記錄、管理者也無從手動分派的
	 * 隱性狀態，跟「服務人員安排」（指定開關／手動分派）這組功能的前提衝突。
	 * 若之後又想支援「不限定」，**不要**在這裡展開成全體人員，應該讓管理者在
	 * 「可服務的人員」裡明確勾選全部人員，語意清楚，也才篩得出「這個人到底
	 * 服務哪些商品」。
	 *
	 * @param int    $product_id 商品 ID。
	 * @param string $plan_key   方案鍵；沒有方案的商品傳空字串。
	 * @return array|false
	 */
	public static function get_booking_settings( $product_id, $plan_key = '' ) {
		$product_id = (int) $product_id;
		if ( ! $product_id || ! self::booking_enabled( $product_id ) ) {
			return false;
		}

		$duration      = (int) get_post_meta( $product_id, '_uappt_duration_minutes', true );
		$buffer_before = (int) get_post_meta( $product_id, '_uappt_buffer_before', true );
		$buffer_after  = (int) get_post_meta( $product_id, '_uappt_buffer_after', true );
		$staff_ids     = self::get_staff_ids_meta( $product_id );

		$product   = wc_get_product( $product_id );
		$price     = $product ? (string) $product->get_price( 'edit' ) : '';
		$plan_name = '';

		$plans = self::get_plans( $product_id );

		if ( $plans ) {
			$plan = self::get_plan( $product_id, $plan_key );
			if ( ! $plan ) {
				// 商品有方案卻沒指定（或指定了不存在的方案）：不能猜一個給它，
				// 否則客人會買到跟畫面上不同時長/價格的服務。
				return false;
			}

			$plan_name = $plan['name'];

			if ( '' !== (string) $plan['duration'] ) {
				$duration = (int) $plan['duration'];
			}
			if ( '' !== (string) $plan['buffer_before'] ) {
				$buffer_before = (int) $plan['buffer_before'];
			}
			if ( '' !== (string) $plan['buffer_after'] ) {
				$buffer_after = (int) $plan['buffer_after'];
			}
			if ( ! empty( $plan['staff_ids'] ) ) {
				$staff_ids = array_map( 'intval', $plan['staff_ids'] );
			}
			if ( '' !== (string) $plan['price'] ) {
				$price = (string) $plan['price'];
			}
		}

		if ( empty( $staff_ids ) ) {
			return false;
		}

		return array(
			'duration_minutes'       => max( 5, $duration ?: 60 ),
			'buffer_before'          => max( 0, $buffer_before ),
			'buffer_after'           => max( 0, $buffer_after ),
			'staff_ids'              => $staff_ids,
			'price'                  => $price,
			'plan_key'               => $plans ? sanitize_key( (string) $plan_key ) : '',
			'plan_name'              => $plan_name,
			// 團體預約是商品層級的開關，不像時長/緩衝/人員可以被方案覆寫——
			// 「一張訂單算幾個人頭」是銷售政策而不是服務規格，跟同一商品底下
			// 選哪個方案無關。
			'group_booking'          => self::group_booking_enabled( $product_id ),
			'group_max_participants' => self::get_group_max_participants( $product_id ),
		);
	}

	/**
	 * 這個商品是否開放「一次幫多人報名」。
	 *
	 * @param int $product_id 商品 ID。
	 * @return bool
	 */
	public static function group_booking_enabled( $product_id ) {
		return 'yes' === get_post_meta( (int) $product_id, '_uappt_group_booking', true );
	}

	/**
	 * 每張訂單的人數上限（0 代表沒有額外上限，只受剩餘名額限制）。
	 *
	 * 獨立於 get_booking_settings() 之外，因為前台渲染表單當下（商品有多個
	 * 方案時）客人可能還沒選方案，get_booking_settings() 這時候會回傳 false——
	 * 但團體預約是商品層級的設定，不需要等方案才能知道答案。
	 *
	 * @param int $product_id 商品 ID。
	 * @return int
	 */
	public static function get_group_max_participants( $product_id ) {
		$raw = get_post_meta( (int) $product_id, '_uappt_group_max_participants', true );
		return '' !== (string) $raw ? max( 2, (int) $raw ) : 0;
	}

	/**
	 * 判斷商品（或方案）是否為可實際預約的項目。
	 *
	 * @param int    $product_id 商品 ID。
	 * @param string $plan_key   方案鍵。
	 * @return bool
	 */
	public static function is_bookable( $product_id, $plan_key = '' ) {
		return (bool) self::get_booking_settings( $product_id, $plan_key );
	}

	/**
	 * 判斷商品是不是「預約商品」。
	 *
	 * v2.1.0 起改為判斷商品類型，不再讀 _uappt_enable_booking meta：商品類型本身
	 * 就是開關，兩套並行的開關只會造成「型別是預約商品但沒勾啟用」這種矛盾狀態。
	 * （_uappt_enable_booking 仍由 UAPPT_Product_Type::enforce_flags_on_save() 維護，
	 * 純粹作為舊查詢的相容旗標。）
	 *
	 * @param int $product_id 商品 ID。
	 * @return bool
	 */
	public static function booking_enabled( $product_id ) {
		$product = wc_get_product( (int) $product_id );
		return $product && UAPPT_Product_Type::PRODUCT_TYPE === $product->get_type();
	}

	/**
	 * 取得某商品實際適用的「開放預約天數」（商品層級留空則沿用全域）。
	 *
	 * @param int $product_id 商品 ID。
	 * @return int
	 */
	public static function get_horizon_days( $product_id ) {
		$own = get_post_meta( (int) $product_id, '_uappt_horizon_days', true );
		if ( '' !== (string) $own ) {
			return max( 1, (int) $own );
		}
		return max( 1, (int) get_option( 'uappt_booking_horizon_days', 30 ) );
	}

	/**
	 * 取得某商品實際適用的「最少提前預約時間」（分鐘；商品層級留空則沿用全域）。
	 *
	 * @param int $product_id 商品 ID。
	 * @return int
	 */
	public static function get_lead_minutes( $product_id ) {
		$own = get_post_meta( (int) $product_id, '_uappt_min_lead_minutes', true );
		if ( '' !== (string) $own ) {
			return max( 0, (int) $own );
		}
		return max( 0, (int) get_option( 'uappt_min_lead_minutes', 0 ) );
	}

	/**
	 * 這項服務是否暫停接受預約。
	 *
	 * 語意界線（改動時務必維持）：暫停只擋「前台的新預約」。既有預約的提醒、改期、
	 * 取消，以及後台手動建立預約，一律不受影響——後者是店家自己的決定，系統不該
	 * 替店家擋下來（見 UAPPT_Booking::create_hold() 的 ignore_paused 參數）。
	 *
	 * @param int $product_id 商品 ID。
	 * @return bool
	 */
	public static function is_paused( $product_id ) {
		return 'yes' === get_post_meta( (int) $product_id, '_uappt_paused', true );
	}

	/**
	 * 前台是否顯示「選擇服務人員」下拉選單（全域設定）。
	 *
	 * 關閉時所有預約一律走「不指定」由系統安排。**伺服器端也要照這個設定把
	 * 送進來的 staff_id 歸零**，不能只靠前台不輸出那個 select——表單欄位是客人
	 * 可以自己加的，指定人員還牽涉到加價。
	 *
	 * 這是全域開關；個別商品若要單獨關閉指定，用 staff_choice_enabled() ——
	 * 它同時考慮這個全域設定與商品層級的 `_uappt_hide_staff_choice`。
	 *
	 * @return bool
	 */
	public static function staff_selector_enabled() {
		return (bool) get_option( 'uappt_show_staff_selector', 1 );
	}

	/**
	 * 這個商品是否開放客人指定服務人員。
	 *
	 * 統一入口：全域開關關閉、或商品自己勾了「不開放客人指定服務人員」，
	 * 兩者只要有一個成立就不開放。呼叫端（前台輸出、購物車驗證、AJAX 查候選
	 * 人員）一律用這支判斷，不要各自組合兩個設定——那樣容易漏掉其中一層。
	 *
	 * 關閉不代表這項服務不需要人員：「可服務的人員」名單依然是必填，只是客人
	 * 看不到、選不到，最終人選交給 manual_assignment_enabled() 決定的方式安排。
	 *
	 * @param int $product_id 商品 ID。
	 * @return bool
	 */
	public static function staff_choice_enabled( $product_id ) {
		if ( ! self::staff_selector_enabled() ) {
			return false;
		}
		return 'yes' !== get_post_meta( (int) $product_id, '_uappt_hide_staff_choice', true );
	}

	/**
	 * 客人沒有指定人員時，是不是要交給管理者手動分派（而不是系統直接定案）。
	 *
	 * 開啟後 create_hold() 仍然會照常挑一位候選人員把時段鎖住——**時段完整性
	 * 不受這個設定影響，不會因為「還沒分派」就少鎖一格**，只是把這筆預約標記為
	 * `pending`，之後由管理者在預約列表用 UAPPT_Booking::reassign_staff() 確認
	 * 或改派別人。客人自己指定人員的預約不受這個設定影響，一律視為定案。
	 *
	 * @param int $product_id 商品 ID。
	 * @return bool
	 */
	public static function manual_assignment_enabled( $product_id ) {
		return 'yes' === get_post_meta( (int) $product_id, '_uappt_manual_assignment', true );
	}

	/**
	 * 商品頁要用哪一種預約介面。
	 *
	 * - classic：商品頁內嵌的表單（預設，v2.21.0 之前的唯一行為）
	 * - wizard：換成前台預約精靈，服務已經限定成這個商品
	 * - intro：關掉商品頁的預約，只留一顆按鈕導到預約頁（商品頁變成服務介紹頁）
	 *
	 * 預設 classic：這個設定是後來才加的，既有站台不動設定就跟以前一模一樣。
	 *
	 * @return string
	 */
	public static function product_page_mode() {
		$mode = (string) get_option( 'uappt_product_page_mode', 'classic' );
		return in_array( $mode, array( 'classic', 'wizard', 'intro' ), true ) ? $mode : 'classic';
	}

	/**
	 * 「預約頁面」的網址（intro 模式那顆按鈕要導去哪裡）。
	 *
	 * 後台沒有明確指定時自動偵測——站上通常只有一頁放了精靈，何必逼管理者
	 * 再選一次。找不到就回空字串，呼叫端自己決定要不要顯示按鈕。
	 *
	 * @return string
	 */
	public static function booking_page_url() {
		$page_id = (int) get_option( 'uappt_booking_page_id', 0 );

		if ( $page_id > 0 ) {
			$url = get_permalink( $page_id );
			if ( $url ) {
				return $url;
			}
		}

		if ( ! class_exists( 'UAPPT_Wizard' ) ) {
			return '';
		}

		$pages = UAPPT_Wizard::find_pages( 1 );
		return ! empty( $pages ) ? $pages[0]['url'] : '';
	}

	/**
	 * 前台時段按鈕上的「剩 X 位」顯示模式：auto / always / never。
	 *
	 * auto = 只有這個服務有 2 位以上候選人員時才顯示（只有 1 位的話每格都掛
	 * 「剩 1 位」純粹是雜訊）。
	 *
	 * @return string
	 */
	public static function show_remaining_mode() {
		$mode = (string) get_option( 'uappt_show_remaining', 'auto' );
		return in_array( $mode, array( 'auto', 'always', 'never' ), true ) ? $mode : 'auto';
	}

	/**
	 * 前台時段篩選頁籤的分類設定（「上午／下午／晚間」）。
	 *
	 * ⚠️ **這是全站一份，不是每位人員一份**（v2.55.0 從 staff 表搬過來）。
	 *
	 * 原本存在每一位人員身上，但 `UAPPT_Booking::get_available_slots()` 是在
	 * 跑候選人員的迴圈**之前**就把分類算好、然後套用到所有人員產出的時段上，
	 * 而它取的是 `$candidates[0]`——也就是「碰巧排第一的那位人員」。所以系統
	 * 其實一直把它當全站設定用，只是來源是隨機的：兩位人員的分界時間不一樣
	 * 時，同一個 18:30 的時段會因為候選人員的排序而被標成「下午」或「晚間」，
	 * 而改甲的設定會默默改掉乙也能服務的商品的前台頁籤。
	 *
	 * 「上午／下午／晚間」是**店對客人講話的用語**，不是某位員工的屬性，所以
	 * 正確的軸就是全站。將來若真的需要例外，軸會是商品／方案（例如課程想用
	 * 「早鳥／正常」），仍然不會是人員。
	 *
	 * @return array{labels:array, boundaries:array}
	 */
	public static function segment_config() {
		$stored = get_option( self::SEGMENTS_OPTION, null );
		return self::sanitize_segment_config( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * 時段分類的預設值：三段連續切分一整天。
	 *
	 * @return array{labels:array, boundaries:array}
	 */
	public static function default_segment_config() {
		return array(
			'labels'     => array(
				__( '上午', 'ultimate-appointments' ),
				__( '下午', 'ultimate-appointments' ),
				__( '晚間', 'ultimate-appointments' ),
			),
			'boundaries' => array( '12:00', '18:00' ),
		);
	}

	/**
	 * 正規化時段分類設定。
	 *
	 * 結構刻意設計成「三個名稱 ＋ 兩個分界時間」而不是自由的起訖區間：三段一定
	 * 是 [00:00, 分界1)、[分界1, 分界2)、[分界2, 24:00)，連續覆蓋一整天不留空隙，
	 * 任何時段都不可能被歸到錯誤的分類，也不可能同時屬於兩段。
	 *
	 * @param array      $config ['labels'=>string[3], 'boundaries'=>string[2]]。
	 * @param array|null $errors 由參照傳入時，錯誤訊息會附加到這裡。
	 * @return array{labels:array, boundaries:array}
	 */
	public static function sanitize_segment_config( $config, array &$errors = null ) {
		$defaults = self::default_segment_config();

		$raw_labels     = ( is_array( $config ) && isset( $config['labels'] ) && is_array( $config['labels'] ) ) ? $config['labels'] : array();
		$raw_boundaries = ( is_array( $config ) && isset( $config['boundaries'] ) && is_array( $config['boundaries'] ) ) ? $config['boundaries'] : array();

		$labels = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$label = isset( $raw_labels[ $i ] ) ? sanitize_text_field( $raw_labels[ $i ] ) : '';
			// 名稱留空就回退成預設名稱，避免前台出現沒有標題的頁籤。
			$labels[ $i ] = ( '' !== $label ) ? $label : $defaults['labels'][ $i ];
		}

		$boundaries = array();
		for ( $i = 0; $i < 2; $i++ ) {
			$raw  = isset( $raw_boundaries[ $i ] ) ? $raw_boundaries[ $i ] : '';
			$time = ( '' === trim( (string) $raw ) ) ? $defaults['boundaries'][ $i ] : UAPPT_Staff::sanitize_time( $raw );

			if ( null === $time ) {
				if ( null !== $errors ) {
					$errors[] = sprintf(
						/* translators: %d: 第幾個分界時間 */
						__( '時段分類的第 %d 個分界時間格式不正確，請輸入類似 12:00 的格式。', 'ultimate-appointments' ),
						$i + 1
					);
				}
				$boundaries[ $i ] = $defaults['boundaries'][ $i ];
				continue;
			}

			$boundaries[ $i ] = $time;
		}

		// 分界必須嚴格遞增，否則中間那一段會是空的或倒著的。擋不下來就整組
		// 退回預設，不要讓前台出現一個永遠不可能有時段的頁籤。
		if ( $boundaries[0] >= $boundaries[1] ) {
			if ( null !== $errors ) {
				$errors[] = __( '時段分類的第二個分界時間必須晚於第一個分界時間，已還原成預設值。', 'ultimate-appointments' );
			}
			$boundaries = $defaults['boundaries'];
		}

		return array(
			'labels'     => $labels,
			'boundaries' => $boundaries,
		);
	}

	/**
	 * 客人可自行取消的期限（服務開始前幾小時）。
	 *
	 * @return int
	 */
	public static function get_cancel_deadline_hours() {
		return max( 0, (int) get_option( 'uappt_cancel_deadline_hours', 24 ) );
	}

	/**
	 * 取得顯示用的名稱：有方案時是「商品名 – 方案名」。
	 *
	 * @param int    $product_id 商品 ID。
	 * @param string $plan_key   方案鍵。
	 * @return string
	 */
	public static function get_display_name( $product_id, $plan_key = '' ) {
		$product = wc_get_product( (int) $product_id );
		$name    = $product ? $product->get_name() : get_the_title( (int) $product_id );

		$plan = self::get_plan( $product_id, $plan_key );
		if ( $plan ) {
			$name .= ' – ' . $plan['name'];
		}

		return $name;
	}
}
