<?php
/**
 * Elementor 整合：預約精靈 widget 的註冊與資產判斷。
 *
 * 這一層是**軟依賴**：Elementor 沒裝、停用、或版本太舊時整組安靜地不存在，
 * shortcode `[uappt_booking]` 照常運作。
 *
 * ⚠️ **這個檔案裡不可以出現任何 Elementor 的類別名稱。**
 * 它在 `plugins_loaded` 就被 require 進來，那時候 Elementor 可能還沒載入完；
 * 檔案層級只要出現 `extends \Elementor\Widget_Base` 就會直接 fatal。真正的
 * widget 類別放在 `includes/elementor/`，只在 `elementor/widgets/register`
 * 那個 hook 裡才 require——那時候 Elementor 一定已經在了。
 *
 * 寫法整體照抄同站的 wc-hotel-booking（includes/class-wchb-elementor.php），
 * 那邊已經把這些坑都踩過並記錄下來了。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UAPPT_Elementor {

	/**
	 * Widget 分類的 slug。
	 */
	const CATEGORY = 'uappt';

	/**
	 * 預約精靈 widget 的型別名稱。
	 *
	 * 這個字串同時是 Elementor 存進 `_elementor_data` 的 `widgetType`，
	 * `page_has_widget()` 靠它比對——兩邊必須一致，所以放成常數。
	 */
	const WIDGET_BOOKING = 'uappt_booking';

	/**
	 * 「這一頁有沒有我們的 widget」的快取。
	 *
	 * @var bool|null
	 */
	protected static $page_has_widget = null;

	/**
	 * 掛 hook。
	 */
	public static function init() {
		// 這兩個 hook 只有 Elementor 在的時候才會觸發，不需要額外守衛。
		add_action( 'elementor/elements/categories_registered', array( __CLASS__, 'register_category' ) );
		add_action( 'elementor/widgets/register', array( __CLASS__, 'register_widgets' ) );
	}

	/**
	 * Elementor 在不在、而且新到有現行的註冊 API 嗎。
	 *
	 * 用 `did_action( 'elementor/loaded' )` 而不是 `class_exists()`：後者在
	 * Elementor 檔案被載入、但還沒初始化完成時就會是 true。
	 *
	 * @return bool
	 */
	public static function is_available() {
		return did_action( 'elementor/loaded' ) > 0;
	}

	/**
	 * 加一個「終極預約」分類，讓 widget 在面板裡找得到。
	 *
	 * @param \Elementor\Elements_Manager $elements_manager 元素管理員。
	 * @return void
	 */
	public static function register_category( $elements_manager ) {
		$elements_manager->add_category(
			self::CATEGORY,
			array(
				'title' => __( '終極預約', 'ultimate-appointments' ),
				'icon'  => 'eicon-calendar',
			)
		);
	}

	/**
	 * 註冊 widget。
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager Widget 管理員。
	 * @return void
	 */
	public static function register_widgets( $widgets_manager ) {
		// **到這裡才 require**：Elementor 這時候一定載入完了，
		// `extends \Elementor\Widget_Base` 才有東西可以繼承。
		require_once UAPPT_PLUGIN_DIR . 'includes/elementor/class-uappt-elementor-booking.php';

		// `register()` 是 3.5 之後的 API。站上是 4.2.4，不做舊版相容——真的遇到
		// 更舊的 Elementor 時這個 hook 根本不會觸發，widget 只是不出現，
		// shortcode 照樣可以用。
		if ( method_exists( $widgets_manager, 'register' ) ) {
			$widgets_manager->register( new UAPPT_Elementor_Booking() );
		}
	}

	/**
	 * 目前這一頁的 Elementor 內容裡有沒有我們的 widget。
	 *
	 * ⚠️ **為什麼需要這一支**：`UAPPT_Wizard::maybe_enqueue()` 是用
	 * `has_shortcode( $post->post_content, … )` 判斷要不要提前載入資產的，而
	 * Elementor 頁面的版面存在 `_elementor_data` meta，`post_content` 放的是
	 * **已經渲染完的 HTML**——不管用原生 widget 還是 Elementor 內建的「短碼」
	 * widget，`has_shortcode()` 都是 false，精靈會變成沒有樣式的裸清單。
	 *
	 * ⚠️ **為什麼不能只靠 widget 的 `get_style_depends()`**：那一支是在**渲染
	 * 當下**才 enqueue 的，那時候 `wp_head` 早就印完了，樣式會被丟到頁尾——
	 * 畫面會先閃一下沒有樣式的版本。兩個都留著是刻意的：這一支負責「正常情況
	 * 下進 `<head>`」，`get_style_depends()` 負責「這一支沒掃到的場合（彈窗、
	 * 範本、其他外掛塞進來的內容）至少不要完全沒有樣式」。
	 *
	 * @return bool
	 */
	public static function page_has_widget() {
		if ( null !== self::$page_has_widget ) {
			return self::$page_has_widget;
		}

		self::$page_has_widget = false;

		if ( ! self::is_available() || ! is_singular() ) {
			return false;
		}

		$post_id = get_queried_object_id();
		if ( $post_id <= 0 ) {
			return false;
		}

		// 不是用 Elementor 編的就不用往下查了（省一次 meta 讀取與遞迴）。
		if ( 'builder' !== get_post_meta( $post_id, '_elementor_edit_mode', true ) ) {
			return false;
		}

		self::$page_has_widget = self::contains_widget( self::elements_of( $post_id ), self::WIDGET_BOOKING );

		return self::$page_has_widget;
	}

	/**
	 * 取出一篇文章的 Elementor 元素樹。
	 *
	 * 優先走 Elementor 自己的 document API（它會處理修訂版、草稿這些情況）；
	 * 拿不到就退回直接解析 meta，不要因此整個放棄。
	 *
	 * @param int $post_id 文章 ID。
	 * @return array
	 */
	protected static function elements_of( $post_id ) {
		if ( class_exists( '\Elementor\Plugin' ) ) {
			$plugin = \Elementor\Plugin::$instance;

			if ( isset( $plugin->documents ) && method_exists( $plugin->documents, 'get' ) ) {
				$document = $plugin->documents->get( $post_id );

				if ( $document && method_exists( $document, 'get_elements_data' ) ) {
					return (array) $document->get_elements_data();
				}
			}
		}

		$raw = get_post_meta( $post_id, '_elementor_data', true );

		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			return is_array( $decoded ) ? $decoded : array();
		}

		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * 在元素樹裡遞迴找某一種 widget。
	 *
	 * Elementor 的結構是 section → column → widget（容器版型則是巢狀 container），
	 * 深度不固定，所以一定要遞迴——只看第一層的話，放在 container 裡的 widget
	 * 會被漏掉，表現就是「有些頁面有樣式、有些沒有」。
	 *
	 * @param array  $elements 元素樹。
	 * @param string $type     widgetType。
	 * @return bool
	 */
	protected static function contains_widget( $elements, $type ) {
		foreach ( (array) $elements as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( isset( $element['widgetType'] ) && $type === $element['widgetType'] ) {
				return true;
			}

			if ( ! empty( $element['elements'] ) && self::contains_widget( $element['elements'], $type ) ) {
				return true;
			}
		}

		return false;
	}
}
