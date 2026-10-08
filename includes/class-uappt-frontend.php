<?php
/**
 * 前台：在商品頁「加入購物車」按鈕前插入日期/時段選擇器。
 *
 * 用標準 hook `woocommerce_before_add_to_cart_button` 插入，不覆寫佈景主題的
 * template 檔案，理論上跟 Blocksy Pro 對 WooCommerce 模板的客製化不衝突；
 * 但如果 Blocksy 有完全自製、脫離標準 hook 結構的商品頁模板，需要另外確認
 * 這個 hook 是否還會被觸發。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UAPPT_Frontend {

	/**
	 * @var UAPPT_Frontend|null
	 */
	protected static $instance = null;

	/**
	 * 單例。
	 *
	 * @return UAPPT_Frontend
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
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_widget' ), 5 );

		// 暫停時商品根本不會輸出「加入購物車」表單（is_purchasable() 回 false），
		// 而 render_widget() 掛的 woocommerce_before_add_to_cart_button 只在表單內部
		// 觸發——沒有表單就沒有提示，客人只會看到一個沒有購買區塊的商品頁，不知道
		// 發生什麼事。所以提示要掛在商品摘要區，用跟「加入購物車」相同的優先權 30，
		// 出現在原本按鈕的位置。
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_paused_notice' ), 30 );
		add_filter( 'woocommerce_quantity_input_args', array( $this, 'lock_quantity_input_args' ), 10, 2 );

		add_action( 'wp', array( $this, 'maybe_replace_add_to_cart' ) );
	}

	/**
	 * 商品頁改用精靈、或改成純介紹頁時，把 WooCommerce 原本的加入購物車表單
	 * 換掉。
	 *
	 * ⚠️ **刻意不用 `is_purchasable()` 來達成這件事**（暫停接受預約是那樣做的）。
	 * 那個判斷是全站性的：一旦回 false，`WC_Cart::add_to_cart()` 也會拒絕，
	 * 連放在 /預約 那一頁的精靈都加不進購物車——等於把整套預約關掉。這裡要關的
	 * 只是「商品頁上的那個表單」，所以改成拿掉單一商品模板上的那個 action。
	 *
	 * 掛在 `wp`：這時候主查詢已經跑完，is_product() 才有意義，而且還早於
	 * 模板輸出，來得及調整 action。
	 */
	public function maybe_replace_add_to_cart() {
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		$product_id = get_queried_object_id();
		if ( ! $product_id || ! UAPPT_Product::booking_enabled( $product_id ) ) {
			return;
		}

		// 暫停接受預約時本來就不輸出表單，提示由 render_paused_notice() 負責，
		// 不要再疊一層。
		if ( UAPPT_Product::is_paused( $product_id ) ) {
			return;
		}

		$mode = UAPPT_Product::product_page_mode();
		if ( 'classic' === $mode ) {
			return;
		}

		remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_product_page_alternative' ), 30 );
	}

	/**
	 * 精靈模式／純介紹頁模式下，商品頁要輸出什麼。
	 */
	public function render_product_page_alternative() {
		$product_id = get_queried_object_id();
		if ( ! $product_id ) {
			return;
		}

		if ( 'wizard' === UAPPT_Product::product_page_mode() ) {
			// 限定成這個商品：只有一個方案時精靈會自動跳過選項目那一步，
			// 有多個方案則保留該步、但清單裡只有這個商品的方案。
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() 內部逐欄位跳脫。
			echo UAPPT_Wizard::render( array( 'product' => $product_id ) );
			return;
		}

		// 純介紹頁：只放一顆導到預約頁的按鈕。找不到預約頁就什麼都不印——
		// 印一顆連不到任何地方的按鈕比沒有按鈕更糟。
		$url = UAPPT_Product::booking_page_url();
		if ( '' === $url ) {
			return;
		}

		printf(
			'<p class="uappt-product-book-cta"><a class="button alt" href="%s">%s</a></p>',
			// 用限定商品而不是 service：service 會鎖死成單一方案，多方案的商品
			// 客人就沒得選了。參數名是 uappt_product——`product` 被 WooCommerce
			// 的商品文章類型佔用，帶在頁面網址上會 404，見 UAPPT_Wizard::render()。
			esc_url( add_query_arg( 'uappt_product', $product_id, $url ) ),
			esc_html__( '立即預約', 'ultimate-appointments' )
		);
	}

	/**
	 * 載入前台 JS/CSS（只在需要預約的商品頁載入）。
	 *
	 * 刻意不用 `global $product` 判斷——這個全域變數要到主迴圈跑到 `the_post`
	 * （WooCommerce 在該 hook 呼叫 wc_setup_product_data()）才會設定好，時間點
	 * 晚於 wp_enqueue_scripts，這裡讀到的常常還是還沒賦值或上一輪殘留的值，
	 * 導致腳本沒被載入。改用 get_queried_object_id() 直接拿目前頁面的商品 ID，
	 * 不受這個時序影響。
	 */
	public function enqueue_assets() {
		// 結帳完成頁與會員「我的預約」頁只需要行事曆連結的樣式（沒有時段
		// 選擇器），這兩頁不必判斷是否為預約商品——直接載入 CSS 即可，
		// 檔案很小，不值得為了省這幾行樣式另外拆一支獨立的 CSS 檔。
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			wp_enqueue_style( 'uappt-frontend', UAPPT_PLUGIN_URL . 'assets/css/frontend.css', array(), UAPPT_VERSION );
			return;
		}
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			wp_enqueue_style( 'uappt-frontend', UAPPT_PLUGIN_URL . 'assets/css/frontend.css', array(), UAPPT_VERSION );
			return;
		}

		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return;
		}

		$product_id = get_queried_object_id();
		if ( ! $product_id || ! UAPPT_Product::booking_enabled( $product_id ) ) {
			return;
		}

		wp_enqueue_style( 'uappt-frontend', UAPPT_PLUGIN_URL . 'assets/css/frontend.css', array(), UAPPT_VERSION );
		wp_enqueue_script( 'uappt-frontend', UAPPT_PLUGIN_URL . 'assets/js/frontend.js', array( 'jquery' ), UAPPT_VERSION, true );

		wp_localize_script(
			'uappt-frontend',
			'UAPPT_Frontend',
			array(
				'ajax_url'     => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'uappt_slots_nonce' ),
				'i18n'         => array(
					'select_date'          => __( '請先選擇日期', 'ultimate-appointments' ),
					'loading'              => __( '查詢可預約時段中…', 'ultimate-appointments' ),
					'no_slots'             => __( '這天已經沒有可預約的時段了，請換一天試試。', 'ultimate-appointments' ),
					'error'                => __( '查詢時段失敗，請稍後再試。', 'ultimate-appointments' ),
					'select_plan'          => __( '請先選擇上方的服務方案', 'ultimate-appointments' ),
					/* translators: %d: 剩餘可服務人數 */
					'remaining_template'   => __( '剩 %d 位', 'ultimate-appointments' ),
					'full_prefix'          => __( '最近可預約：', 'ultimate-appointments' ),
					'staff_any'            => __( '不指定服務人員', 'ultimate-appointments' ),
					'staff_last_suffix'    => __( '（上次為您服務）', 'ultimate-appointments' ),
					'loading_staff'        => __( '載入服務人員中…', 'ultimate-appointments' ),
					'no_staff'             => __( '目前沒有可服務的人員，請聯繫客服。', 'ultimate-appointments' ),
					// 團體預約「報名人數」欄位的提示文字：選時段前欄位是停用的，
					// 選好時段才解鎖並帶入這個時段真正的上限（見 assets/js/frontend.js）。
					'participants_hint_before' => __( '請先選擇下方的時段，才會知道這個時段還能報名幾人。', 'ultimate-appointments' ),
					/* translators: %d: 這個時段最多還能報名的人數 */
					'participants_hint_after'  => __( '這個時段最多還能報名 %d 人。總金額（人數 × 單價）將在購物車頁顯示。', 'ultimate-appointments' ),
				),
			)
		);
	}

	/**
	 * 把預約商品的數量欄位鎖在 1（min=max=1）。
	 *
	 * 這是不依賴 JS 的硬性防護：即使前端 frontend.js 因快取、載入順序或跟
	 * 佈景主題衝突而沒有執行，數量欄位的 HTML 本身就會被鎖死，使用者無法
	 * 用瀏覽器原生的加減按鈕調到 2 以上。「同時可服務人數」是候選人員在
	 * 單一時段的產能上限，跟這裡的「單一購物車項目只能是 1 個時段」是
	 * 兩件事，但對預約商品來說後者本來就該永遠鎖在 1。
	 *
	 * @param array      $args    數量欄位參數。
	 * @param WC_Product $product 商品物件。
	 * @return array
	 */
	public function lock_quantity_input_args( $args, $product ) {
		if ( $product instanceof WC_Product && UAPPT_Product::booking_enabled( $product->get_id() ) ) {
			$args['min_value']   = 1;
			$args['max_value']   = 1;
			$args['input_value'] = 1;
		}
		return $args;
	}

	/**
	 * 取得某商品（或其中一個服務方案）的候選人員清單，供伺服器端輸出初始 &lt;option&gt; 用。
	 * 與 UAPPT_Ajax::get_staff_options() 回傳的結構一致，好讓 JS 在換方案時
	 * 用同一份 render 邏輯（見 assets/js/frontend.js 的 renderStaffOptions()）。
	 *
	 * @param int    $product_id 商品 ID。
	 * @param string $plan_key   服務方案鍵。
	 * @return array
	 */
	protected static function get_staff_options_for_render( $product_id, $plan_key = '' ) {
		$settings = UAPPT_Product::get_booking_settings( $product_id, $plan_key );
		if ( ! $settings ) {
			return array();
		}

		$rows   = UAPPT_Staff::get_many( $settings['staff_ids'] );
		$active = array_values(
			array_filter(
				$rows,
				function ( $s ) {
					return 'active' === $s['status'];
				}
			)
		);
		usort(
			$active,
			function ( $a, $b ) {
				return $a['sort_order'] <=> $b['sort_order'];
			}
		);

		$last_staff_id = 0;
		if ( is_user_logged_in() ) {
			$last = UAPPT_Booking::get_last_completed_booking( get_current_user_id() );
			if ( $last && ! empty( $last['staff_id'] ) ) {
				$last_staff_id = (int) $last['staff_id'];
			}
		}

		$list = array();
		foreach ( $active as $s ) {
			$list[] = array(
				'id'               => (int) $s['id'],
				'name'             => $s['name'],
				'price_adjustment' => (float) $s['price_adjustment'],
				'is_last'          => ( (int) $s['id'] === $last_staff_id ),
			);
		}
		return $list;
	}

	/**
	 * 組出人員下拉選單的顯示文字，例如「王老師（上次為您服務）（+$200）」。
	 *
	 * @param array $option 單一人員選項。
	 * @return string
	 */
	protected static function format_staff_option_label( $option ) {
		$label = $option['name'];
		if ( ! empty( $option['is_last'] ) ) {
			$label .= __( '（上次為您服務）', 'ultimate-appointments' );
		}
		if ( ! empty( $option['price_adjustment'] ) ) {
			$label .= ' (+' . wc_price( (float) $option['price_adjustment'] ) . ')';
		}
		return wp_strip_all_tags( $label );
	}

	/**
	 * 暫停接受預約時，在商品摘要區輸出說明，取代原本「加入購物車」的位置。
	 */
	public function render_paused_notice() {
		global $product;

		if ( ! $product instanceof WC_Product || ! UAPPT_Product::booking_enabled( $product->get_id() ) ) {
			return;
		}

		if ( ! UAPPT_Product::is_paused( $product->get_id() ) ) {
			return;
		}

		echo '<p class="uappt-paused-notice">' .
			esc_html__( '此服務目前暫停接受預約，請聯繫客服洽詢。', 'ultimate-appointments' ) .
			'</p>';
	}

	/**
	 * 輸出方案/人員/日期/時段選擇器 UI。
	 */
	public function render_widget() {
		global $product;

		if ( ! $product instanceof WC_Product || ! UAPPT_Product::booking_enabled( $product->get_id() ) ) {
			return;
		}

		$product_id = $product->get_id();

		// 暫停時不輸出選擇器。正常情況下根本走不到這裡（商品不可購買，表單不會被
		// 輸出），這是給「佈景主題自己刻了加入購物車表單、繞過 is_purchasable()」
		// 這種情況的保險。提示由 render_paused_notice() 負責。
		if ( UAPPT_Product::is_paused( $product_id ) ) {
			return;
		}

		$horizon_days = UAPPT_Product::get_horizon_days( $product_id );
		$min_date     = current_time( 'Y-m-d' );
		$max_date     = uappt_local_date( $min_date, "+{$horizon_days} days" );

		// 只拿啟用中的方案：停用的方案不該出現在客人的選單裡。既有預約的解析走
		// UAPPT_Product::get_plan()，那條路徑仍然看得到停用的方案，改期不受影響。
		$plans          = UAPPT_Product::get_plans( $product_id, true );
		$show_staff     = UAPPT_Product::staff_choice_enabled( $product_id );

		// 團體預約是商品層級的設定，不受方案影響，渲染表單當下就能決定要不要
		// 輸出「報名人數」欄位——不用像人員候選名單那樣等客人選了方案才知道。
		$group_booking  = UAPPT_Product::group_booking_enabled( $product_id );
		$group_max      = UAPPT_Product::get_group_max_participants( $product_id );

		// 方案時長留空＝沿用商品層級設定。標籤要顯示的是「實際會服務多久」，
		// 不是「這個方案自己填了什麼」——後台明白鼓勵留空，若這裡照原始值判斷，
		// 照建議做的管理者反而換來一個看不到時長的選單。
		$default_duration = max( 5, (int) get_post_meta( $product_id, '_uappt_duration_minutes', true ) ?: 60 );
		$default_plan   = $plans ? $plans[0]['key'] : '';
		$single_plan    = ( 1 === count( $plans ) );

		// 只有一個方案（或完全沒有方案）時，候選人員在頁面渲染當下就確定，直接
		// 伺服器端輸出，省一次頁面載入就要打的 AJAX。有多個方案時要等客人選了
		// 方案才知道候選人員清單（不同方案可能限定不同人員），交給 JS 再查。
		$initial_staff_options = array();
		if ( ! $plans || $single_plan ) {
			$initial_staff_options = self::get_staff_options_for_render( $product_id, $default_plan );
		}

		?>
		<div class="uappt-booking-widget"
			data-product-id="<?php echo esc_attr( $product_id ); ?>"
			data-has-plans="<?php echo $plans ? '1' : '0'; ?>">
			<?php wp_nonce_field( 'uappt_add_booking', 'uappt_booking_nonce' ); ?>

			<?php if ( $single_plan ) : ?>
				<input type="hidden" name="uappt_plan_key" class="uappt-plan-input" value="<?php echo esc_attr( $default_plan ); ?>" />
			<?php elseif ( $plans ) : ?>
				<div class="uappt-field uappt-field-plan">
					<label for="uappt-plan-<?php echo esc_attr( $product_id ); ?>">
						<?php esc_html_e( '選擇服務方案', 'ultimate-appointments' ); ?>
					</label>
					<select id="uappt-plan-<?php echo esc_attr( $product_id ); ?>" class="uappt-plan-select uappt-plan-input" name="uappt_plan_key">
						<option value=""><?php esc_html_e( '請選擇…', 'ultimate-appointments' ); ?></option>
						<?php foreach ( $plans as $plan ) : ?>
							<option
								value="<?php echo esc_attr( $plan['key'] ); ?>"
								data-price-html="<?php echo esc_attr( self::plan_price_html( $product, $plan ) ); ?>">
								<?php echo esc_html( self::format_plan_option_label( $plan, $default_duration ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			<?php endif; ?>

			<?php if ( $show_staff ) : ?>
				<div class="uappt-field uappt-field-staff">
					<label for="uappt-staff-<?php echo esc_attr( $product_id ); ?>">
						<?php esc_html_e( '選擇服務人員', 'ultimate-appointments' ); ?>
					</label>
					<select id="uappt-staff-<?php echo esc_attr( $product_id ); ?>" class="uappt-staff-select" name="uappt_booking_staff">
						<option value="0"><?php esc_html_e( '不指定服務人員', 'ultimate-appointments' ); ?></option>
						<?php foreach ( $initial_staff_options as $option ) : ?>
							<option value="<?php echo esc_attr( $option['id'] ); ?>" <?php selected( ! empty( $option['is_last'] ) ); ?>>
								<?php echo esc_html( self::format_staff_option_label( $option ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>
			<?php else : ?>
				<div class="uappt-field uappt-field-staff uappt-field-staff-fixed">
					<span class="uappt-field-label"><?php esc_html_e( '服務人員', 'ultimate-appointments' ); ?></span>
					<span class="uappt-field-value"><?php esc_html_e( '不指定服務人員', 'ultimate-appointments' ); ?></span>
					<?php // 沒有 select 就沒有 name="uappt_booking_staff"，等同送出 0——跟 cart.php 的
					// staff_choice_enabled() 伺服器端歸零是同一個結果，這裡純粹是文字說明。?>
				</div>
			<?php endif; ?>

			<?php if ( $group_booking ) : ?>
				<div class="uappt-field uappt-field-participants">
					<label for="uappt-participants-<?php echo esc_attr( $product_id ); ?>">
						<?php esc_html_e( '報名人數', 'ultimate-appointments' ); ?>
					</label>
					<?php
					// 預設 disabled：真正能報名幾人取決於「哪個時段、哪一位人員」當下
					// 的剩餘名額，同一天不同時段都不一樣。選時段之前先給一個數字（不管
					// 是不限、還是用理論最大容量）都只是猜的，客人填了 8 人、選完時段
					// 被打回 3 人，就是我們自己製造出來的錯誤期待。乾脆等時段確定、
					// 真實上限算得出來了再開放輸入（由 assets/js/frontend.js 解鎖）。
					// disabled 的欄位不會被送出，但這時候「加入購物車」本來就是鎖住的
					// （syncSubmitState()），而且後端沒收到就一律當 1，不影響任何流程。
					?>
					<input
						type="number"
						id="uappt-participants-<?php echo esc_attr( $product_id ); ?>"
						class="uappt-participants-input"
						name="uappt_participants"
						min="1"
						<?php echo $group_max ? 'max="' . esc_attr( $group_max ) . '"' : ''; ?>
						step="1"
						value="1"
						inputmode="numeric"
						disabled
					/>
					<p class="description uappt-field-hint">
						<?php esc_html_e( '請先選擇下方的時段，才會知道這個時段還能報名幾人。', 'ultimate-appointments' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<div class="uappt-field uappt-field-date">
				<label for="uappt-date-<?php echo esc_attr( $product_id ); ?>">
					<?php esc_html_e( '選擇日期', 'ultimate-appointments' ); ?>
				</label>
				<input
					type="date"
					id="uappt-date-<?php echo esc_attr( $product_id ); ?>"
					class="uappt-date-input"
					min="<?php echo esc_attr( $min_date ); ?>"
					max="<?php echo esc_attr( $max_date ); ?>"
					autocomplete="off"
				/>
			</div>

			<div class="uappt-field uappt-field-slots">
				<label><?php esc_html_e( '選擇時段', 'ultimate-appointments' ); ?></label>
				<div class="uappt-slots-list" aria-live="polite">
					<p class="uappt-slots-placeholder"><?php esc_html_e( '請先選擇日期', 'ultimate-appointments' ); ?></p>
				</div>
			</div>

			<p class="uappt-selected-summary" hidden></p>

			<input type="hidden" name="uappt_booking_date" class="uappt-input-date" value="" />
			<input type="hidden" name="uappt_booking_time" class="uappt-input-time" value="" />
		</div>
		<?php
	}

	/**
	 * 組出方案下拉選單的顯示文字，例如「90 分鐘全身舒壓（90 分鐘）— NT$2,800」。
	 *
	 * @param array $plan             方案定義。
	 * @param int   $default_duration 商品層級的服務時長，方案留空時沿用。
	 * @return string
	 */
	protected static function format_plan_option_label( $plan, $default_duration = 0 ) {
		$label = $plan['name'];

		$duration = '' !== (string) $plan['duration'] ? (int) $plan['duration'] : (int) $default_duration;
		if ( $duration > 0 ) {
			/* translators: %d: 服務時長分鐘數 */
			$label .= sprintf( __( '（%d 分鐘）', 'ultimate-appointments' ), $duration );
		}

		if ( '' !== (string) $plan['price'] ) {
			$label .= ' — ' . wp_strip_all_tags( wc_price( (float) $plan['price'] ) );
		}

		return $label;
	}

	/**
	 * 方案價格的顯示 HTML，讓 JS 在客人換方案時即時換掉商品頁上的價格。
	 *
	 * 用 wc_get_price_to_display() 而不是直接 wc_price()，含稅/未稅的顯示規則
	 * 才會跟商品頁其他地方一致。
	 *
	 * @param WC_Product $product 商品物件。
	 * @param array      $plan    方案定義。
	 * @return string
	 */
	protected static function plan_price_html( $product, $plan ) {
		if ( '' === (string) $plan['price'] ) {
			return '';
		}

		return wc_price( wc_get_price_to_display( $product, array( 'price' => (float) $plan['price'] ) ) );
	}
}
