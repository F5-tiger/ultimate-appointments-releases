<?php
/**
 * Elementor widget：預約精靈。
 *
 * ⚠️ **這個檔案只能在 `elementor/widgets/register` 裡被 require。**
 * 它 `extends \Elementor\Widget_Base`，在 Elementor 載入之前 require 會直接
 * fatal。進入點是 `UAPPT_Elementor::register_widgets()`。
 *
 * ⚠️ **排查時會踩到的一件事**：在前台請求裡 `get_controls()` **看不到外觀分頁
 * 的控制項**，這不是壞掉。Elementor 4.x 的 `Performance::should_optimize_controls()`
 * （`! is_admin() && ! 預覽模式 && ! REST_REQUEST`）會把 style 分頁的控制項改存到
 * 另一個桶子 `style_controls`，省下建 stack 的成本——前台只需要存好的設定值與
 * 產好的 CSS，不需要控制項定義本身。要在 CLI 裡驗證，得先用反射把
 * `Performance::$is_frontend` 設成 false。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 預約精靈 widget。
 *
 * **畫面完全走 `UAPPT_Wizard::render()`，跟 shortcode 同一支。** widget 只負責
 * 把面板上的設定翻成那一支的參數——兩條路各自組畫面的話，遲早會有一邊先改到，
 * 而使用者看到的是「shortcode 跟 widget 長得不一樣」。
 */
class UAPPT_Elementor_Booking extends \Elementor\Widget_Base {

	/**
	 * 型別名稱。
	 *
	 * @return string
	 */
	public function get_name() {
		return UAPPT_Elementor::WIDGET_BOOKING;
	}

	/**
	 * 面板上的名稱。
	 *
	 * @return string
	 */
	public function get_title() {
		return __( '預約精靈', 'ultimate-appointments' );
	}

	/**
	 * 面板上的圖示。
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-form-horizontal';
	}

	/**
	 * 所屬分類。
	 *
	 * @return string[]
	 */
	public function get_categories() {
		return array( UAPPT_Elementor::CATEGORY );
	}

	/**
	 * 搜尋關鍵字（Elementor 面板上方的搜尋框）。
	 *
	 * 中英文都放：面板語系跟著後台走，但使用者未必用同一種語言找東西。
	 *
	 * @return string[]
	 */
	public function get_keywords() {
		return array( '預約', '訂位', '精靈', '服務', '美容', 'booking', 'appointment', 'wizard', 'salon' );
	}

	/**
	 * 相依的樣式。
	 *
	 * 這是**後備**，不是主要路徑：Elementor 是在渲染當下才處理它的，那時候
	 * `wp_head` 已經印完，樣式會落到頁尾。正常情況由
	 * `UAPPT_Elementor::page_has_widget()` 在 `wp_enqueue_scripts` 就先載好，
	 * 這裡保的是它掃不到的場合（彈窗、範本、別的外掛把內容塞進來）——
	 * 寧可晚一點載入，也不要完全沒有樣式。
	 *
	 * @return string[]
	 */
	public function get_style_depends() {
		return array( UAPPT_Wizard::STYLE_HANDLE );
	}

	/**
	 * 相依的腳本（同上，這也是後備路徑）。
	 *
	 * @return string[]
	 */
	public function get_script_depends() {
		return array( UAPPT_Wizard::SCRIPT_HANDLE );
	}

	/**
	 * 這個 widget 的內容是動態產生的，不吃 Elementor 的行內編輯。
	 *
	 * @return bool
	 */
	protected function is_dynamic_content(): bool {
		return true;
	}

	/**
	 * 控制項。
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'section_content',
			array(
				'label' => __( '預約精靈', 'ultimate-appointments' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'mode',
			array(
				'label'       => __( '流程順序', 'ultimate-appointments' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => 'staff_first',
				'options'     => array(
					'staff_first'   => __( '先選人員，再選項目', 'ultimate-appointments' ),
					'service_first' => __( '先選項目，再選人員', 'ultimate-appointments' ),
				),
				'description' => __( '客人習慣指定固定的設計師時，用「先選人員」。', 'ultimate-appointments' ),
			)
		);

		$this->add_control(
			'staff',
			array(
				'label'       => __( '限定服務人員', 'ultimate-appointments' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => '0',
				'options'     => self::staff_options(),
				'description' => __( '選了就會跳過「選擇服務人員」那一步，直接鎖定這一位。', 'ultimate-appointments' ),
			)
		);

		$this->add_control(
			'service',
			array(
				'label'       => __( '限定服務項目', 'ultimate-appointments' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => '',
				'options'     => self::service_options(),
				'description' => __( '選了就會跳過「選擇服務項目」那一步。適合放在單一服務的介紹頁。', 'ultimate-appointments' ),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_palette',
			array(
				'label' => __( '配色', 'ultimate-appointments' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		// ⚠️ 這一項必須排在所有單項顏色**之前**。Elementor 是照控制項註冊的
		// 順序把 selectors 寫進同一份 CSS 的，權重都一樣、由後面的蓋前面的——
		// 預設排在前面，使用者另外挑的單一顏色才蓋得過它。反過來排的話，選了
		// 深色之後再改「卡片底色」會完全沒反應。
		$this->add_control(
			'palette',
			array(
				'label'       => __( '配色預設', 'ultimate-appointments' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => 'light',
				'options'     => array(
					'light' => __( '淺色', 'ultimate-appointments' ),
					'dark'  => __( '深色', 'ultimate-appointments' ),
				),
				'description' => __( '先挑一組底，再用下面的欄位微調。放在深色區塊裡卻只改主色是不夠的——卡片、月曆、頭像底色都得跟著換，這裡一次處理。', 'ultimate-appointments' ),
				'selectors'   => array(
					'{{WRAPPER}} .uappt-wiz' => '{{VALUE}}',
				),
				// 值直接就是一串 CSS 宣告，會被原樣寫進規則裡。淺色留空＝維持
				// wizard.css 的預設值，不必在這裡重複一次（重複就會有兩份要維護）。
				'selectors_dictionary' => array(
					'light' => '',
					'dark'  => '--uappt-wiz-ink:#e8e8ea;'
						. '--uappt-wiz-surface:#26282b;'
						. '--uappt-wiz-surface-2:#191a1c;'
						. '--uappt-wiz-border:#3a3d42;'
						. '--uappt-wiz-muted:#a0a3a8;'
						. '--uappt-wiz-disabled:#5c6066;'
						. '--uappt-wiz-danger-bg:#3a2325;'
						. '--uappt-wiz-danger-ink:#ff9a9a;',
				),
			)
		);

		// 精靈的樣式全部吃這些變數（見 wizard.css 最上面），所以換色不必為了
		// 每個元件各寫一條選擇器。
		$this->add_control(
			'accent',
			array(
				'label'       => __( '主色', 'ultimate-appointments' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => __( '目前步驟、選中的日期與時段、確認按鈕都用這個顏色。', 'ultimate-appointments' ),
				'selectors'   => array(
					'{{WRAPPER}} .uappt-wiz' => '--uappt-wiz-accent: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'accent_ink',
			array(
				'label'       => __( '主色上的文字顏色', 'ultimate-appointments' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => __( '壓在主色上的字，例如確認按鈕的字。主色偏淺時記得改深一點。', 'ultimate-appointments' ),
				'selectors'   => array(
					'{{WRAPPER}} .uappt-wiz' => '--uappt-wiz-accent-ink: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'ink',
			array(
				'label'       => __( '文字顏色', 'ultimate-appointments' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => __( '留空時跟著佈景主題的內文顏色走。', 'ultimate-appointments' ),
				'selectors'   => array(
					'{{WRAPPER}} .uappt-wiz' => '--uappt-wiz-ink: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'surface',
			array(
				'label'       => __( '卡片底色', 'ultimate-appointments' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => __( '人員／服務卡片、時段、月曆上有位的日子。', 'ultimate-appointments' ),
				'selectors'   => array(
					'{{WRAPPER}} .uappt-wiz' => '--uappt-wiz-surface: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'surface_2',
			array(
				'label'       => __( '次要底色', 'ultimate-appointments' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => __( '月曆上不能選的日子、還沒上傳照片的人員色塊。跟卡片底色要看得出差別，客人才分得出哪天有位。', 'ultimate-appointments' ),
				'selectors'   => array(
					'{{WRAPPER}} .uappt-wiz' => '--uappt-wiz-surface-2: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'border',
			array(
				'label'     => __( '框線顏色', 'ultimate-appointments' ),
				'type'      => \Elementor\Controls_Manager::COLOR,
				'separator' => 'before',
				'selectors' => array(
					'{{WRAPPER}} .uappt-wiz' => '--uappt-wiz-border: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'muted',
			array(
				'label'       => __( '次要文字顏色', 'ultimate-appointments' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => __( '服務時長、備註、步驟列這些輔助資訊。', 'ultimate-appointments' ),
				'selectors'   => array(
					'{{WRAPPER}} .uappt-wiz' => '--uappt-wiz-muted: {{VALUE}};',
				),
			)
		);

		$this->add_control(
			'disabled',
			array(
				'label'       => __( '停用狀態顏色', 'ultimate-appointments' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => __( '約滿或沒排班的日子。太淡會讓客人以為是可以按的。', 'ultimate-appointments' ),
				'selectors'   => array(
					'{{WRAPPER}} .uappt-wiz' => '--uappt-wiz-disabled: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_layout',
			array(
				'label' => __( '版面', 'ultimate-appointments' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		// 只給容器一組字級，其餘元件在 wizard.css 裡一律用 em 相對於它，所以
		// 這一項會讓整組等比縮放。刻意**不**替步驟列、卡片名稱、價格各開一組
		// ——預約類外掛最常見的毛病就是控制項堆到五六十個，使用者反而找不到
		// 想改的那一個，也很容易調出比例失衡的版面。
		$this->add_group_control(
			\Elementor\Group_Control_Typography::get_type(),
			array(
				'name'     => 'typography',
				'selector' => '{{WRAPPER}} .uappt-wiz',
			)
		);

		$this->add_responsive_control(
			'max_width',
			array(
				'label'       => __( '最大寬度', 'ultimate-appointments' ),
				'type'        => \Elementor\Controls_Manager::SLIDER,
				'size_units'  => array( 'px', '%' ),
				'range'       => array(
					'px' => array( 'min' => 320, 'max' => 1200 ),
					'%'  => array( 'min' => 40, 'max' => 100 ),
				),
				'description' => __( '預設 720px，是一次只問一件事的閱讀寬度。放進全寬區塊想更開闊時再調寬——太寬會讓時段格拉得很扁。', 'ultimate-appointments' ),
				'selectors'   => array(
					'{{WRAPPER}} .uappt-wiz' => 'max-width: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'card_gap',
			array(
				'label'      => __( '卡片間距', 'ultimate-appointments' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array( 'px' => array( 'min' => 0, 'max' => 32 ) ),
				'selectors'  => array(
					'{{WRAPPER}} .uappt-wiz .uappt-wiz-grid' => 'gap: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_responsive_control(
			'card_padding',
			array(
				'label'      => __( '卡片內距', 'ultimate-appointments' ),
				'type'       => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => array( 'px', 'em' ),
				'selectors'  => array(
					'{{WRAPPER}} .uappt-wiz .uappt-wiz-card' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				),
			)
		);

		// ⚠️ 版面與張數都刻意**不是**響應式控制項，理由見 wizard.css 底部
		// `@media (min-width: 768px)` 上方那段註解：Elementor 的電腦版值不包
		// media query、選擇器權重又比元件自己的高，只設電腦版會連手機一起改到。
		// 改成寫一個**只在該 query 裡被讀取**的變數，斷點界線就還是留在元件手上。
		//
		// ⚠️ 這一項必須排在「每列張數」之前：橫式清單順便把預設張數放寬成
		// 260px 起跳（橫式卡片擠在 140px 的格子裡會很難看），但使用者若自己
		// 指定了張數，那個值要蓋得過這裡——Elementor 照註冊順序輸出，後面的贏。
		$this->add_control(
			'staff_layout',
			array(
				'label'       => __( '電腦版：人員卡片版面', 'ultimate-appointments' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => '',
				'separator'   => 'before',
				'options'     => array(
					''     => __( '直式方格（照片在上）', 'ultimate-appointments' ),
					'list' => __( '橫式清單（照片在左）', 'ultimate-appointments' ),
				),
				'description' => __( '人員少、或名字比較長時，橫式清單比較好讀。手機版一律是橫式一列一張，不受這裡影響——窄螢幕的方格只塞得下兩欄，每格都太小。', 'ultimate-appointments' ),
				'selectors'   => array(
					'{{WRAPPER}} .uappt-wiz' => '{{VALUE}}',
				),
				'selectors_dictionary' => array(
					''     => '',
					'list' => '--uappt-wiz-staff-dir:row;'
						. '--uappt-wiz-staff-text:left;'
						. '--uappt-wiz-staff-gap:12px;'
						. '--uappt-wiz-staff-cross:flex-start;'
						. '--uappt-wiz-avatar-size:56px;'
						. '--uappt-wiz-staff-cols:repeat(auto-fill, minmax(260px, 1fr));',
				),
			)
		);

		// 這一項跟版面拆開而不是併成一個三選一的下拉：兩者是互相獨立的決定，
		// 拆開就有四種組合可用（方格／清單 × 有照片／沒照片），而且「我們沒有
		// 幫人員拍照」跟「我想要清單版面」本來就是兩件事。
		$this->add_control(
			'hide_photo',
			array(
				'label'        => __( '不顯示人員照片', 'ultimate-appointments' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'return_value' => 'none',
				'default'      => '',
				'description'  => __( '沒有幫人員拍照時建議打開。沒有照片的人員現在會顯示姓名首字的圓形色塊，一整排色塊其實不太好看，整個拿掉會乾淨很多。這一項手機版與電腦版都會生效。', 'ultimate-appointments' ),
				'selectors'    => array(
					'{{WRAPPER}} .uappt-wiz' => '--uappt-wiz-avatar-display: {{VALUE}};',
				),
			)
		);

		// ⚠️ 這兩項刻意**不是**響應式控制項，理由見 wizard.css 底部
		// `@media (min-width: 768px)` 上方那段註解：Elementor 的電腦版值不包
		// media query、選擇器權重又比元件自己的高，只設電腦版會連手機一起改到。
		// 改成寫一個**只在該 query 裡被讀取**的變數，斷點界線就還是留在元件手上。
		$this->add_control(
			'staff_cols',
			array(
				'label'       => __( '電腦版：人員每列張數', 'ultimate-appointments' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => '',
				'separator'   => 'before',
				'options'     => array(
					''  => __( '自動（依寬度排滿）', 'ultimate-appointments' ),
					'2' => __( '2 張', 'ultimate-appointments' ),
					'3' => __( '3 張', 'ultimate-appointments' ),
					'4' => __( '4 張', 'ultimate-appointments' ),
					'5' => __( '5 張', 'ultimate-appointments' ),
				),
				'description' => __( '「自動」會依容器寬度排滿，人員多的時候最省空間；人員只有兩三位時固定張數比較好看，不會出現一張卡佔掉半個畫面。手機版一律一列一張，不受這裡影響。', 'ultimate-appointments' ),
				'selectors'   => array(
					'{{WRAPPER}} .uappt-wiz' => '--uappt-wiz-staff-cols: {{VALUE}};',
				),
				'selectors_dictionary' => array(
					'2' => 'repeat(2, minmax(0, 1fr))',
					'3' => 'repeat(3, minmax(0, 1fr))',
					'4' => 'repeat(4, minmax(0, 1fr))',
					'5' => 'repeat(5, minmax(0, 1fr))',
				),
			)
		);

		$this->add_control(
			'service_cols',
			array(
				'label'       => __( '電腦版：服務項目每列張數', 'ultimate-appointments' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'default'     => '',
				'options'     => array(
					''  => __( '2 張（預設）', 'ultimate-appointments' ),
					'1' => __( '1 張（整列）', 'ultimate-appointments' ),
					'3' => __( '3 張', 'ultimate-appointments' ),
				),
				'description' => __( '服務名稱比較長時改成整列，名稱才不會被擠成兩行。手機版一律整列。', 'ultimate-appointments' ),
				'selectors'   => array(
					'{{WRAPPER}} .uappt-wiz' => '--uappt-wiz-service-cols: {{VALUE}};',
				),
				'selectors_dictionary' => array(
					'1' => 'minmax(0, 1fr)',
					'3' => 'repeat(3, minmax(0, 1fr))',
				),
			)
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_shape',
			array(
				'label' => __( '外型', 'ultimate-appointments' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'radius',
			array(
				'label'       => __( '圓角', 'ultimate-appointments' ),
				'type'        => \Elementor\Controls_Manager::SLIDER,
				'size_units'  => array( 'px' ),
				'range'       => array( 'px' => array( 'min' => 0, 'max' => 24 ) ),
				'description' => __( '卡片、時段與月曆格共用。網站整體是直角或全圓角時，這裡跟著調才不會突兀。', 'ultimate-appointments' ),
				'selectors'   => array(
					'{{WRAPPER}} .uappt-wiz' => '--uappt-wiz-radius: {{SIZE}}{{UNIT}};',
				),
			)
		);

		$this->add_control(
			'photo_shape',
			array(
				'label'     => __( '人員照片形狀', 'ultimate-appointments' ),
				'type'      => \Elementor\Controls_Manager::SELECT,
				'default'   => 'circle',
				'options'   => array(
					'circle' => __( '圓形（大頭貼）', 'ultimate-appointments' ),
					'round'  => __( '圓角方形', 'ultimate-appointments' ),
				),
				'selectors' => array(
					'{{WRAPPER}} .uappt-wiz' => '--uappt-wiz-avatar-radius: {{VALUE}};',
				),
				'selectors_dictionary' => array(
					'circle' => '50%',
					'round'  => '12px',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * 「限定服務人員」的選項。
	 *
	 * @return array
	 */
	protected static function staff_options() {
		$options = array( '0' => __( '不限定（讓客人選）', 'ultimate-appointments' ) );

		if ( ! class_exists( 'UAPPT_Staff' ) ) {
			return $options;
		}

		foreach ( UAPPT_Staff::get_all( true ) as $staff ) {
			$options[ (string) $staff['id'] ] = $staff['name'];
		}

		return $options;
	}

	/**
	 * 「限定服務項目」的選項。
	 *
	 * 值的格式是 `商品ID|方案鍵`：一個商品可以有多個服務方案，光靠商品 ID
	 * 指不到「60 分鐘經典按摩」跟「90 分鐘全身舒壓」的差別。
	 *
	 * @return array
	 */
	protected static function service_options() {
		$options = array( '' => __( '不限定（讓客人選）', 'ultimate-appointments' ) );

		if ( ! class_exists( 'UAPPT_Service_Index' ) || ! class_exists( 'UAPPT_Availability_Query' ) ) {
			return $options;
		}

		$seen = array();

		foreach ( UAPPT_Service_Index::get() as $entries ) {
			foreach ( $entries as $entry ) {
				$key = $entry['product_id'] . '|' . $entry['plan_key'];
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;

				$payload = UAPPT_Availability_Query::service_payload( $entry['product_id'], $entry['plan_key'] );
				if ( null === $payload ) {
					continue;
				}

				$options[ $key ] = $payload['product_name'] === $payload['name']
					? $payload['name']
					: $payload['product_name'] . ' — ' . $payload['name'];
			}
		}

		return $options;
	}

	/**
	 * 輸出。
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		// 外掛沒完整載入時（例如 WooCommerce 被停用）不要 fatal，在編輯器裡
		// 講一句話就好——白畫面會讓人以為是 Elementor 壞了。
		if ( ! class_exists( 'UAPPT_Wizard' ) ) {
			if ( \Elementor\Plugin::$instance->editor->is_edit_mode() ) {
				echo '<p>' . esc_html__( '終極預約沒有正常載入（請確認 WooCommerce 已啟用）。', 'ultimate-appointments' ) . '</p>';
			}
			return;
		}

		// 服務選項的值是「商品ID|方案鍵」，拆回 shortcode 要的兩個參數。
		$service_raw = isset( $settings['service'] ) ? (string) $settings['service'] : '';
		$product_id  = 0;
		$plan_key    = '';

		if ( '' !== $service_raw ) {
			$parts      = explode( '|', $service_raw, 2 );
			$product_id = (int) $parts[0];
			$plan_key   = isset( $parts[1] ) ? $parts[1] : '';
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render() 內部逐欄位跳脫。
		echo UAPPT_Wizard::render(
			array(
				'mode'    => isset( $settings['mode'] ) ? (string) $settings['mode'] : 'staff_first',
				'staff'   => isset( $settings['staff'] ) ? (int) $settings['staff'] : 0,
				'service' => $product_id,
				'plan'    => $plan_key,
			)
		);
	}
}
