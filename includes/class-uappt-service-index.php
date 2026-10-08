<?php
/**
 * 人員 → 服務的反查索引。
 *
 * **為什麼需要這個東西**：可服務的人員是存在商品那一側的——商品層級是
 * `_uappt_staff_ids`（陣列），方案層級是 `_uappt_plans` 裡每個方案自己的
 * `staff_ids`。兩者都是序列化之後存進 postmeta，**沒辦法用 SQL 反查**
 * 「這位人員能做哪些服務」。前台的預約精靈是「先選人再選項目」，第二步
 * 需要的正是這個反方向，所以自己建一份索引。
 *
 * 唯一的既有前例是 `UAPPT_Product::remove_staff_from_all_products()`，它每次
 * 都掃過全部預約商品——那支一年跑不到幾次無所謂，但精靈每次載入都要問一次，
 * 不能每次都全表掃描。
 *
 * **懶惰重建**：失效時只把 option 刪掉，不當場重建。商品存檔是熱路徑
 * （一次存檔會連續觸發好幾個 meta 更新），當場重建等於同一次請求掃好幾遍
 * 全部商品；刪掉之後等下一次真的有人要讀才重建，成本只付一次。
 *
 * @package Ultimate_Appointments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UAPPT_Service_Index {

	/**
	 * 索引存放的 option 名稱。
	 *
	 * 刻意不用 transient：transient 可能被物件快取的清除策略無預警丟掉，
	 * 而重建成本是「掃過全部預約商品」，不希望它在尖峰時段被隨機觸發。
	 * option 本身就有 wp_cache 保護，而且失效時機我們自己完全掌握。
	 */
	const OPTION = 'uappt_staff_service_index';

	/**
	 * 會影響索引內容的 postmeta，只有這兩個。
	 */
	const WATCHED_META = array( '_uappt_staff_ids', UAPPT_Product::PLANS_META );

	/**
	 * 掛上所有會讓索引過期的 hook。
	 *
	 * 失效寧可過度也不要漏掉：刪一個 option 很便宜，拿到過期的索引卻會讓
	 * 客人在前台看到自己根本約不到的服務。所以這裡連「商品的分類法被重設」
	 * 都掛上（一鍵轉換商品類型走的是 wp_set_object_terms()，不會觸發
	 * save_post，只掛 save_post 會漏）。
	 */
	public static function init() {
		add_action( 'save_post_product', array( __CLASS__, 'invalidate' ) );
		add_action( 'deleted_post', array( __CLASS__, 'invalidate_if_product' ), 10, 2 );
		add_action( 'trashed_post', array( __CLASS__, 'invalidate_if_product' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'invalidate_if_product' ) );

		// meta 直接被程式改掉的路徑（例如 remove_staff_from_all_products()
		// 在刪除人員時逐一 update_post_meta()）不會觸發 save_post，一定要另外接。
		//
		// 反過來也不能只靠這三個：`update_post_meta()` 在「新值跟舊值相同」時
		// 會提早返回、**不觸發** updated_post_meta（WordPress 的標準行為）。
		// 後台存檔沒改到人員清單時就是這種情況，那時候靠的是上面的
		// save_post_product。兩組看起來重疊，其實各自補對方的洞，不要刪。
		add_action( 'added_post_meta', array( __CLASS__, 'invalidate_if_watched_meta' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'invalidate_if_watched_meta' ), 10, 3 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'invalidate_if_watched_meta' ), 10, 3 );

		add_action( 'set_object_terms', array( __CLASS__, 'invalidate_if_product_type' ), 10, 4 );
	}

	/**
	 * 取得整份索引（沒有就當場重建）。
	 *
	 * @return array [staff_id => [ ['product_id'=>int,'plan_key'=>string], … ]]
	 */
	public static function get() {
		$index = get_option( self::OPTION, null );

		if ( ! is_array( $index ) ) {
			$index = self::build();
			update_option( self::OPTION, $index, false ); // autoload=false：只有精靈的 API 用得到，不必每個請求都載入。
		}

		return $index;
	}

	/**
	 * 這位人員能做哪些服務。
	 *
	 * 回傳的是「商品 ID ＋ 方案鍵」這組最小識別，名稱、時長、價格一律留給
	 * 呼叫端用 `UAPPT_Product::get_booking_settings()` 現場查——索引裡存越少
	 * 衍生資料，過期的風險面就越小。
	 *
	 * @param int $staff_id 人員 ID。
	 * @return array [ ['product_id'=>int,'plan_key'=>string], … ]，沒有就是空陣列。
	 */
	public static function get_services_for_staff( $staff_id ) {
		$index    = self::get();
		$staff_id = (int) $staff_id;

		return isset( $index[ $staff_id ] ) ? $index[ $staff_id ] : array();
	}

	/**
	 * 讓索引失效。下次 get() 會重建。
	 */
	public static function invalidate() {
		delete_option( self::OPTION );
	}

	/**
	 * @param int          $post_id 文章 ID。
	 * @param WP_Post|null $post    文章物件（deleted_post 才有第二個參數）。
	 */
	public static function invalidate_if_product( $post_id, $post = null ) {
		$post_type = $post instanceof WP_Post ? $post->post_type : get_post_type( $post_id );
		if ( 'product' === $post_type ) {
			self::invalidate();
		}
	}

	/**
	 * @param int    $meta_id  meta ID（用不到，位置參數）。
	 * @param int    $post_id  文章 ID（用不到，位置參數）。
	 * @param string $meta_key meta 鍵。
	 */
	public static function invalidate_if_watched_meta( $meta_id, $post_id, $meta_key ) {
		if ( in_array( $meta_key, self::WATCHED_META, true ) ) {
			self::invalidate();
		}
	}

	/**
	 * @param int    $object_id 物件 ID（用不到，位置參數）。
	 * @param array  $terms     詞彙（用不到，位置參數）。
	 * @param array  $tt_ids    詞彙分類 ID（用不到，位置參數）。
	 * @param string $taxonomy  分類法。
	 */
	public static function invalidate_if_product_type( $object_id, $terms, $tt_ids, $taxonomy ) {
		if ( 'product_type' === $taxonomy ) {
			self::invalidate();
		}
	}

	/**
	 * 掃過全部預約商品，組出反查索引。
	 *
	 * 三個刻意的取捨：
	 *
	 * 1. **只收 publish 的商品**。`get_converted_product_ids()` 的
	 *    `post_status => 'any'` 連草稿跟垃圾桶都拿得到（那支是給資料清理用的，
	 *    範圍越大越好），但這份索引是給前台客人看的，草稿不該出現。
	 *
	 * 2. **只收啟用中的方案**（`get_plans( $id, true )`）。停用的方案前台本來
	 *    就不販售。
	 *
	 * 3. **不管商品可見度**。服務商品建議設成「隱藏」不要出現在商店列表
	 *    （見 docs/booking-wizard-plan.md 的建議站台結構），但那只影響逛商店，
	 *    透過預約精靈照樣要約得到——所以這裡不看 catalog visibility。
	 *
	 * 「暫停預約」也不在這裡過濾：那是會即時切換的執行期狀態，索引是
	 * 「誰能做什麼」的能力對應表，暫停與否留給讀取端當下判斷。
	 *
	 * @return array
	 */
	protected static function build() {
		$index = array();

		if ( ! class_exists( 'UAPPT_Product_Type' ) || ! class_exists( 'UAPPT_Product' ) ) {
			return $index;
		}

		foreach ( UAPPT_Product_Type::get_converted_product_ids() as $product_id ) {
			if ( 'publish' !== get_post_status( $product_id ) ) {
				continue;
			}

			$product_staff = UAPPT_Product::get_staff_ids_meta( $product_id );
			$plans         = UAPPT_Product::get_plans( $product_id, true );

			if ( empty( $plans ) ) {
				// 沒有方案的商品：整個商品共用商品層級的人員，plan_key 是空字串
				// （`get_booking_settings()` 對這種商品本來就收空的 plan_key）。
				self::add_entries( $index, $product_staff, $product_id, '' );
				continue;
			}

			foreach ( $plans as $plan ) {
				// 方案層級的人員清單非空時覆蓋商品層級，跟
				// `UAPPT_Product::get_booking_settings()` 的判斷方式一致——
				// 兩邊的規則必須一樣，否則索引說做得到、實際下單卻被擋。
				$staff_ids = ! empty( $plan['staff_ids'] ) && is_array( $plan['staff_ids'] )
					? array_map( 'intval', $plan['staff_ids'] )
					: $product_staff;

				self::add_entries( $index, $staff_ids, $product_id, (string) $plan['key'] );
			}
		}

		return $index;
	}

	/**
	 * 把一組人員對某個服務的對應寫進索引。
	 *
	 * @param array  $index      索引（傳參考）。
	 * @param array  $staff_ids  人員 ID 陣列。
	 * @param int    $product_id 商品 ID。
	 * @param string $plan_key   方案鍵（沒有方案的商品是空字串）。
	 */
	protected static function add_entries( array &$index, $staff_ids, $product_id, $plan_key ) {
		foreach ( (array) $staff_ids as $staff_id ) {
			$staff_id = (int) $staff_id;
			if ( $staff_id <= 0 ) {
				continue;
			}

			if ( ! isset( $index[ $staff_id ] ) ) {
				$index[ $staff_id ] = array();
			}

			$index[ $staff_id ][] = array(
				'product_id' => (int) $product_id,
				'plan_key'   => $plan_key,
			);
		}
	}
}
